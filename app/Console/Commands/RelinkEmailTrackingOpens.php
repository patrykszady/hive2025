<?php

namespace App\Console\Commands;

use App\Models\EmailTracking;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class RelinkEmailTrackingOpens extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'email-tracking:relink-opens
        {--execute : Persist changes to the database (otherwise dry-run)}
        {--limit=0 : Limit the number of opened rows processed (0 = no limit)}
        {--delete-duplicates : Delete pre_* opened rows when an equivalent canonical opened row already exists}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Relink opened email tracking events from pre-send ids (pre_*) to canonical Nylas message/thread ids';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $execute = (bool) $this->option('execute');
        $limit = (int) ($this->option('limit') ?? 0);
        $deleteDuplicates = (bool) $this->option('delete-duplicates');

        $query = EmailTracking::query()
            ->where('event_type', 'opened')
            ->where('nylas_message_id', 'like', 'pre\_%')
            ->orderBy('id');

        if ($limit > 0) {
            $query->limit($limit);
        }

        $total = (clone $query)->count();
        if ($total === 0) {
            $this->info('No opened events with pre_* message ids found.');
            return self::SUCCESS;
        }

        $this->info(($execute ? 'Executing' : 'Dry-run').": processing {$total} opened events...");

        $updated = 0;
        $skippedNoSent = 0;
        $skippedRecipientMismatch = 0;
        $duplicates = 0;
        $deleted = 0;

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $processed = 0;

        $query->chunkById(250, function ($opens) use (
            &$processed,
            $total,
            $limit,
            $execute,
            $deleteDuplicates,
            &$updated,
            &$skippedNoSent,
            &$skippedRecipientMismatch,
            &$duplicates,
            &$deleted,
            $bar
        ): void {
            foreach ($opens as $open) {
                if ($limit > 0 && $processed >= $limit) {
                    return;
                }

                $processed++;
                $bar->advance();

                $preId = (string) $open->nylas_message_id;
                if (! Str::startsWith($preId, 'pre_')) {
                    continue;
                }

                $recipientEmail = null;
                if (is_array($open->recipient_emails) && ($open->recipient_emails[0] ?? null)) {
                    $recipientEmail = (string) $open->recipient_emails[0];
                }

                $sent = EmailTracking::query()
                    ->where('event_type', 'sent')
                    ->where(function ($q) use ($preId): void {
                        $q->where('nylas_message_id', $preId)
                            ->orWhere('metadata->pre_send_tracking_id', $preId);
                    })
                    ->orderByDesc('event_at')
                    ->first();

                if (! $sent) {
                    $skippedNoSent++;
                    continue;
                }

                if ($recipientEmail !== null) {
                    $sentRecipients = is_array($sent->recipient_emails) ? $sent->recipient_emails : [];
                    if (! in_array($recipientEmail, $sentRecipients, true)) {
                        $skippedRecipientMismatch++;
                        continue;
                    }
                }

                $canonicalMessageId = (string) ($sent->nylas_message_id ?? '');
                if ($canonicalMessageId === '') {
                    $skippedNoSent++;
                    continue;
                }

                $canonicalThreadId = $sent->nylas_thread_id ? (string) $sent->nylas_thread_id : null;

                // If an equivalent canonical open already exists, we can delete this pre_* row.
                $existingCanonicalOpenQuery = EmailTracking::query()
                    ->where('event_type', 'opened')
                    ->where('nylas_message_id', $canonicalMessageId);

                if ($recipientEmail !== null) {
                    $existingCanonicalOpenQuery->whereJsonContains('recipient_emails', $recipientEmail);
                }

                if ($open->event_at instanceof Carbon) {
                    $windowStart = $open->event_at->copy()->subMinute();
                    $windowEnd = $open->event_at->copy()->addMinute();
                    $existingCanonicalOpenQuery->whereBetween('event_at', [$windowStart, $windowEnd]);
                }

                $existingCanonicalOpen = $existingCanonicalOpenQuery->first();

                if ($existingCanonicalOpen) {
                    $duplicates++;
                    if ($execute && $deleteDuplicates) {
                        $open->delete();
                        $deleted++;
                    }
                    continue;
                }

                $updates = [
                    'nylas_message_id' => $canonicalMessageId,
                    'nylas_thread_id' => $canonicalThreadId,
                ];

                if (! $open->project_id && $sent->project_id) {
                    $updates['project_id'] = (int) $sent->project_id;
                }

                if (! $open->email_template_name && $sent->email_template_name) {
                    $updates['email_template_name'] = (string) $sent->email_template_name;
                }

                $meta = is_array($open->metadata) ? $open->metadata : [];
                $meta['pre_send_tracking_id'] = $preId;
                $updates['metadata'] = $meta;

                if ($execute) {
                    $open->update($updates);
                }

                $updated++;
            }
        });

        $bar->finish();
        $this->newLine(2);

        $this->info('Relink summary');
        $this->line('Updated: ' . $updated . ($execute ? '' : ' (dry-run)'));
        $this->line('Duplicates found: ' . $duplicates . ($execute && $deleteDuplicates ? " (deleted: {$deleted})" : ''));
        $this->line('Skipped (no matching sent): ' . $skippedNoSent);
        $this->line('Skipped (recipient mismatch): ' . $skippedRecipientMismatch);

        if (! $execute) {
            $this->newLine();
            $this->warn('No changes were written. Re-run with --execute to apply.');
        }

        return self::SUCCESS;
    }
}
