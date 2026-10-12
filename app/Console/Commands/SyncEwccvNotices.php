<?php

namespace App\Console\Commands;

use App\Models\Vendor;
use App\Models\VendorDoc;
use App\Notifications\EwccvNoticeNotification;
use App\Services\NylasService;
use App\Support\Ewccv\MailFolders;
use App\Support\Ewccv\NoticeParser;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Read EWCCV's notices from the certificates mailbox onto the workers comp
 * docs they concern, and text the admins about the ones that matter.
 *
 * Tracking a policy on ewccv.com means EWCCV emails the account when the
 * carrier reports a cancellation, non-renewal or reinstatement. Those mails
 * land at patryk.szady@live.com and are forwarded to the certificates
 * mailbox Nylas reads. Until now only a person reading the inbox would
 * notice; this stamps the notice on the doc (the Verified column turns red
 * on a cancellation) and sends a text, so the compliance question — is the
 * sub still covered? — is answered in Hive.
 *
 * Idempotent: every notice is keyed by its message id on the doc it was
 * pinned to; the mailbox is read from a bookmark with an hour's overlap.
 */
class SyncEwccvNotices extends Command
{
    protected $signature = 'ewccv:notices
        {--belongs-to-vendor-id=1 : Whose workers comp docs the notices concern}
        {--since= : Read mail from this date instead of the bookmark}
        {--dry-run : Show what would be stamped and sent, change nothing}
        {--no-filing : Leave the mail where it is instead of filing it under Workers Comp}';

    protected $description = 'Pin EWCCV cancellation / reinstatement notices from the certificates mailbox onto workers comp docs, and alert the admins';

    public const BOOKMARK = 'ewccv/notices-bookmark.json';

    public function handle(NylasService $nylas, MailFolders $folders): int
    {
        $belongsTo = (int) $this->option('belongs-to-vendor-id');
        $dryRun = (bool) $this->option('dry-run');
        $since = $this->option('since') ? Carbon::parse((string) $this->option('since')) : $this->bookmark();

        $grantId = (string) config('nylas.certificates_grant_id');
        $response = $nylas->getMessages($grantId, ['limit' => 100, 'received_after' => $since->timestamp]);
        $messages = collect(is_array($response) ? ($response['data'] ?? []) : [])
            ->filter(fn ($m) => is_array($m) && NoticeParser::isFromEwccv($m))
            ->sortBy(fn (array $m) => (int) ($m['date'] ?? 0))
            ->values();

        $docs = VendorDoc::withoutGlobalScopes()
            ->where('type', 'workers')
            ->where('belongs_to_vendor_id', $belongsTo)
            ->with(['vendor' => fn ($query) => $query->withoutGlobalScopes()])
            ->orderByDesc('expiration_date')
            ->get();
        $known = $docs->pluck('number')->filter()->map(fn ($n) => (string) $n)->all();
        $admins = Vendor::withoutGlobalScopes()->find($belongsTo)?->getAdminUsersWithCellPhones() ?? collect();

        $this->info(sprintf('EWCCV mail since %s: %d message(s)%s', $since->toDateTimeString(), $messages->count(), $dryRun ? ' (dry run)' : ''));

        $stats = ['notices' => 0, 'stamped' => 0, 'alerted' => 0, 'unmatched' => 0, 'skipped' => 0, 'filed' => 0];
        $newest = $since;
        $filing = ! $dryRun && ! $this->option('no-filing');

        foreach ($messages as $message) {
            $receivedAt = Carbon::createFromTimestamp((int) ($message['date'] ?? 0));
            $newest = $receivedAt->gt($newest) ? $receivedAt : $newest;
            $kind = NoticeParser::kind($message);
            $id = (string) ($message['id'] ?? '');

            // Every EWCCV mail is filed by what it is: sign-in links and
            // subscription confirmations into their subfolders, the rest
            // under Workers Comp itself.
            if ($filing && $folders->file($grantId, $message, $kind)) {
                $stats['filed']++;
            }

            if ($kind === NoticeParser::KIND_CONFIRMATION && ! $dryRun) {
                $this->repairMisreadConfirmation($docs, $id);
            }

            if (in_array($kind, [NoticeParser::KIND_SIGN_IN, NoticeParser::KIND_OTHER, NoticeParser::KIND_CONFIRMATION], true)) {
                $stats['skipped']++;

                continue;
            }

            $stats['notices']++;
            $numbers = NoticeParser::policyNumbers($message, $known);
            $employer = NoticeParser::employer($message);
            $matched = $docs->filter(function (VendorDoc $doc) use ($numbers, $employer) {
                $ours = NoticeParser::normalize((string) $doc->number);
                foreach ($numbers as $theirs) {
                    if (strlen($ours) >= 6 && strlen($theirs) >= 6 && ($ours === $theirs || str_contains($ours, $theirs) || str_contains($theirs, $ours))) {
                        return true;
                    }
                }
                $name = NoticeParser::normalize((string) ($doc->vendor?->business_name ?? ''));

                return $employer !== null && strlen($name) >= 6 && str_contains(NoticeParser::normalize($employer), $name);
            })->unique('vendor_id')->values();

            $label = sprintf('%s %s | %s', $receivedAt->format('m/d H:i'), $kind, Str::limit((string) ($message['subject'] ?? ''), 70));

            if ($matched->isEmpty()) {
                $stats['unmatched']++;
                $this->warn("  unmatched: {$label}  (policies seen: ".implode(', ', $numbers).')');
                Log::warning('EWCCV notice matched no workers comp doc', ['subject' => $message['subject'] ?? null, 'kind' => $kind, 'policies' => $numbers, 'employer' => $employer]);

                continue;
            }

            foreach ($matched as $doc) {
                $options = $doc->options ?? [];
                $seen = (array) ($options['ewccv']['notice_message_ids'] ?? []);
                if ($id !== '' && in_array($id, $seen, true)) {
                    continue;
                }

                $vendorName = (string) ($doc->vendor?->business_name ?? "vendor {$doc->vendor_id}");
                $this->line(sprintf('  %s → %s (%s)', $label, $vendorName, $doc->number));

                if ($dryRun) {
                    continue;
                }

                $state = (array) ($options['ewccv'] ?? []);
                $state['notice'] = [
                    'kind' => $kind,
                    'received_at' => $receivedAt->toIso8601String(),
                    'subject' => Str::limit((string) ($message['subject'] ?? ''), 160),
                    'excerpt' => NoticeParser::excerpt($message),
                    'message_id' => $id,
                ];
                $state['notice_message_ids'] = array_values(array_unique([...$seen, $id]));
                if (in_array($kind, [NoticeParser::KIND_CANCELLATION, NoticeParser::KIND_NONRENEWAL], true)) {
                    $state['status'] = 'cancelled';
                    $state['checked_at'] = $receivedAt->toIso8601String();
                } elseif ($kind === NoticeParser::KIND_REINSTATEMENT) {
                    $state['status'] = 'tracking';
                    $state['checked_at'] = $receivedAt->toIso8601String();
                }
                $options['ewccv'] = $state;
                $doc->options = $options;
                // saveQuietly: the observer would queue a fresh lookup for a changed doc.
                $doc->saveQuietly();
                $stats['stamped']++;

                if (in_array($kind, NoticeParser::ALERT_KINDS, true)) {
                    foreach ($admins as $admin) {
                        $admin->notify(new EwccvNoticeNotification($kind, $vendorName, (string) $doc->number, $receivedAt->format('m/d/Y')));
                        $stats['alerted']++;
                    }
                }
            }
        }

        if (! $dryRun && ! $this->option('since')) {
            Storage::disk('local')->put(self::BOOKMARK, json_encode(['last_received_at' => $newest->toIso8601String()]));
        }

        $this->info(sprintf('Notices %d, stamped %d, texts %d, unmatched %d, other mail %d, filed %d.', $stats['notices'], $stats['stamped'], $stats['alerted'], $stats['unmatched'], $stats['skipped'], $stats['filed']));
        Log::info('EWCCV notices synced', $stats);

        return self::SUCCESS;
    }

    /**
     * A confirmation mail once misread as a notice (2026-10-12: five of them
     * as "reinstatement") left a wrong notice on the doc; when the same
     * message now reads as a confirmation, the notice comes off.
     *
     * @param  \Illuminate\Support\Collection<int, VendorDoc>  $docs
     */
    protected function repairMisreadConfirmation($docs, string $messageId): void
    {
        if ($messageId === '') {
            return;
        }
        foreach ($docs as $doc) {
            $state = (array) ($doc->options['ewccv'] ?? []);
            if (($state['notice']['message_id'] ?? null) !== $messageId) {
                continue;
            }
            unset($state['notice']);
            $options = $doc->options;
            $options['ewccv'] = $state;
            $doc->options = $options;
            $doc->saveQuietly();
            $this->line("  repaired: the notice on {$doc->number} was this confirmation, removed");
        }
    }

    /** Where the last run stopped, with an hour's overlap; the first run reads a week back. */
    protected function bookmark(): Carbon
    {
        $disk = Storage::disk('local');
        if ($disk->exists(self::BOOKMARK)) {
            $data = json_decode((string) $disk->get(self::BOOKMARK), true);
            if (! empty($data['last_received_at'])) {
                return Carbon::parse($data['last_received_at'])->subHour();
            }
        }

        return Carbon::now()->subWeek();
    }
}
