<?php

namespace App\Services\Emails;

use App\Models\CompanyEmail;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Read every connected mailbox — each CompanyEmail grant's own inbox and
 * sent folder, and the shared crew@ inbox through a grant that can see it —
 * since its watermark, and hand each message to StakeholderEmailStore,
 * which keeps only email with project stakeholders.
 */
class StakeholderEmailSync
{
    public function __construct(protected StakeholderEmailStore $store)
    {
    }

    /**
     * @return list<array{mailbox: string, folder: string, fetched: int, stored: int, error: ?string}>
     */
    public function run(?int $days = null): array
    {
        $report = [];
        $own = [];

        foreach (CompanyEmail::withoutGlobalScopes()->whereNotNull('grant_id')->orderBy('id')->get() as $companyEmail) {
            $mailbox = mb_strtolower(trim((string) $companyEmail->email));
            $grantId = trim((string) $companyEmail->grant_id);

            if ($mailbox === '' || $grantId === '') {
                continue;
            }

            $own[] = $mailbox;

            foreach ($this->folders($grantId, null) as $folder => $folderId) {
                $report[] = $this->readFolder($grantId, $mailbox, $folder, $folderId, null, $days);
            }
        }

        $crew = mb_strtolower(trim((string) config('nylas.crew_leads.mailbox')));

        if ($crew !== '' && ! in_array($crew, $own, true)) {
            foreach ((array) config('nylas.crew_leads.grant_ids') as $grantId) {
                $grantId = trim((string) $grantId);
                $inbox = $grantId !== '' ? ($this->folders($grantId, $crew)['inbox'] ?? null) : null;

                if ($inbox) {
                    $report[] = $this->readFolder($grantId, $crew, 'inbox', $inbox, $crew, $days);

                    break;
                }
            }
        }

        return $report;
    }

    /**
     * @return array{mailbox: string, folder: string, fetched: int, stored: int, error: ?string}
     */
    protected function readFolder(string $grantId, string $mailbox, string $folder, string $folderId, ?string $sharedFrom, ?int $days): array
    {
        $watermarkKey = "emails:sync:{$grantId}:{$mailbox}:{$folder}";
        $since = $days !== null
            ? now()->subDays($days)->getTimestamp()
            : (int) Cache::get($watermarkKey, now()->subDays((int) config('emails.stakeholder_sync.initial_days', 30))->getTimestamp());

        $out = ['mailbox' => $mailbox, 'folder' => $folder, 'fetched' => 0, 'stored' => 0, 'error' => null];
        $newest = $since;
        $cursor = null;

        for ($page = 0; $page < (int) config('emails.stakeholder_sync.max_pages', 10); $page++) {
            try {
                $response = $this->nylas()->get("/v3/grants/{$grantId}/messages", array_filter([
                    'in' => $folderId,
                    'shared_from' => $sharedFrom,
                    'limit' => (int) config('emails.stakeholder_sync.page_size', 50),
                    'received_after' => $since,
                    'fields' => 'include_headers',
                    'page_token' => $cursor,
                ]));
            } catch (ConnectionException $e) {
                $out['error'] = 'unreachable';

                break;
            }

            if (! $response->successful()) {
                $out['error'] = 'HTTP '.$response->status();
                Log::channel('nylas')->warning('Stakeholder email sync: folder unreadable', ['mailbox' => $mailbox, 'folder' => $folder, 'status' => $response->status()]);

                break;
            }

            foreach ((array) $response->json('data') as $message) {
                if (! is_array($message)) {
                    continue;
                }

                $out['fetched']++;
                $newest = max($newest, (int) ($message['date'] ?? 0));

                try {
                    if ($this->store->store($message, $grantId, $mailbox, $sharedFrom !== null)) {
                        $out['stored']++;
                    }
                } catch (\Throwable $e) {
                    Log::channel('nylas')->warning('Stakeholder email sync: message failed', ['mailbox' => $mailbox, 'message_id' => $message['id'] ?? null, 'error' => $e->getMessage()]);
                }
            }

            $cursor = $response->json('next_cursor');

            if (! $cursor) {
                break;
            }
        }

        // A message dated in the same second as the newest one read must not
        // fall between runs; the store dedupes the overlap.
        if ($out['error'] === null && $newest > $since) {
            Cache::forever($watermarkKey, $newest - 1);
        }

        return $out;
    }

    /**
     * The inbox and sent folder ids for a grant (or a mailbox it reads
     * shared), cached a day.
     *
     * @return array<string, string>
     */
    protected function folders(string $grantId, ?string $sharedFrom): array
    {
        return Cache::remember("emails:folders:{$grantId}:".($sharedFrom ?? 'own'), now()->addDay(), function () use ($grantId, $sharedFrom): array {
            try {
                $response = $this->nylas()->get("/v3/grants/{$grantId}/folders", array_filter(['shared_from' => $sharedFrom]));
            } catch (ConnectionException) {
                return [];
            }

            if (! $response->successful()) {
                return [];
            }

            $found = [];

            foreach ((array) $response->json('data') as $folder) {
                $name = mb_strtolower(trim((string) ($folder['name'] ?? '')));
                $attributes = array_map('mb_strtolower', (array) ($folder['attributes'] ?? []));
                $id = (string) ($folder['id'] ?? '');

                if ($id === '') {
                    continue;
                }

                if (! isset($found['inbox']) && ($name === 'inbox' || in_array('\\inbox', $attributes, true))) {
                    $found['inbox'] = $id;
                } elseif (! isset($found['sent']) && (in_array($name, ['sent items', 'sent', 'sent mail'], true) || in_array('\\sent', $attributes, true))) {
                    $found['sent'] = $id;
                }
            }

            return $sharedFrom !== null ? array_intersect_key($found, ['inbox' => true]) : $found;
        });
    }

    protected function nylas(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('nylas.api_uri', 'https://api.us.nylas.com'), '/'))
            ->withToken((string) config('nylas.api_key'))
            ->timeout(60)
            ->retry(2, 2000, throw: false);
    }
}
