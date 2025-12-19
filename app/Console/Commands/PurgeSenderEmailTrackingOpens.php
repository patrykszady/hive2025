<?php

namespace App\Console\Commands;

use App\Models\EmailTracking;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;

class PurgeSenderEmailTrackingOpens extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'email-tracking:purge-sender-opens
        {--execute : Actually delete records (default is dry-run)}
        {--limit=5000 : Max opened records to scan}
        {--delete-limit=5000 : Max records to delete}
        {--only-message-id= : Only consider a specific nylas_message_id}
        {--delete-id= : Delete a specific email_tracking id (or comma-separated list of ids)}
        {--force : Allow deleting --delete-id rows even if they do not match sender_ip heuristics}
    ';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Purge opened tracking rows that match the sender_ip of the corresponding sent event (dry-run by default).';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $execute = (bool) $this->option('execute');
        $scanLimit = (int) $this->option('limit');
        $deleteLimit = (int) $this->option('delete-limit');
        $onlyMessageId = $this->option('only-message-id');
        $deleteIdOption = $this->option('delete-id');
        $force = (bool) $this->option('force');

        if (is_string($deleteIdOption) && trim($deleteIdOption) !== '') {
            return $this->handleDeleteByIds($deleteIdOption, $execute, $force);
        }

        if ($scanLimit <= 0) {
            $this->error('Option --limit must be > 0.');
            return self::FAILURE;
        }

        if ($deleteLimit <= 0) {
            $this->error('Option --delete-limit must be > 0.');
            return self::FAILURE;
        }

        $this->info($execute ? 'Executing purge...' : 'Dry-run (no deletes).');

        $openedQuery = EmailTracking::query()
            ->where('event_type', 'opened')
            ->whereNotNull('nylas_message_id')
            ->whereNotNull('ip_address')
            ->when(is_string($onlyMessageId) && $onlyMessageId !== '', function ($query) use ($onlyMessageId) {
                $query->where('nylas_message_id', $onlyMessageId);
            })
            ->orderByDesc('id')
            ->limit($scanLimit);

        $opened = $openedQuery->get(['id', 'nylas_message_id', 'ip_address', 'user_agent', 'event_at']);

        if ($opened->isEmpty()) {
            $this->info('No opened rows found to scan.');
            return self::SUCCESS;
        }

        $messageIds = $opened->pluck('nylas_message_id')->unique()->values()->all();

        $sentByMessageId = EmailTracking::query()
            ->where('event_type', 'sent')
            ->whereIn('nylas_message_id', $messageIds)
            ->get(['id', 'nylas_message_id', 'metadata'])
            ->keyBy('nylas_message_id');

        $toDeleteIds = [];
        foreach ($opened as $open) {
            $sent = $sentByMessageId->get($open->nylas_message_id);
            if (! $sent) {
                continue;
            }

            $senderIp = Arr::get($sent->metadata ?? [], 'sender_ip');
            if (! is_string($senderIp) || $senderIp === '') {
                continue;
            }

            if ((string) $open->ip_address !== $senderIp) {
                continue;
            }

            $toDeleteIds[] = (int) $open->id;

            if (count($toDeleteIds) >= $deleteLimit) {
                break;
            }
        }

        $count = count($toDeleteIds);
        $this->info("Matched {$count} opened rows that look like sender opens.");

        if ($count === 0) {
            return self::SUCCESS;
        }

        $this->line('Sample IDs: '.implode(', ', array_slice($toDeleteIds, 0, 25)));

        if (! $execute) {
            $this->warn('Re-run with --execute to delete these rows.');
            return self::SUCCESS;
        }

        $deleted = EmailTracking::query()->whereIn('id', $toDeleteIds)->delete();
        $this->info("Deleted {$deleted} opened rows.");

        return self::SUCCESS;
    }

    protected function handleDeleteByIds(string $deleteIdOption, bool $execute, bool $force): int
    {
        $ids = collect(explode(',', $deleteIdOption))
            ->map(static fn (string $id) => trim($id))
            ->filter(static fn (string $id) => $id !== '')
            ->map(static fn (string $id) => (int) $id)
            ->filter(static fn (int $id) => $id > 0)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            $this->error('Option --delete-id must be a valid id or comma-separated list of ids.');
            return self::FAILURE;
        }

        $rows = EmailTracking::query()
            ->whereIn('id', $ids->all())
            ->get(['id', 'event_type', 'nylas_message_id', 'ip_address', 'metadata', 'event_at']);

        $foundIds = $rows->pluck('id')->map(static fn ($id) => (int) $id)->all();
        $missing = $ids->diff($foundIds);

        if ($missing->isNotEmpty()) {
            $this->warn('Some ids were not found: '.implode(', ', $missing->all()));
        }

        if ($rows->isEmpty()) {
            $this->info('No matching rows found.');
            return self::SUCCESS;
        }

        $messageIds = $rows
            ->pluck('nylas_message_id')
            ->filter(static fn ($value) => is_string($value) && $value !== '')
            ->unique()
            ->values()
            ->all();

        $sentByMessageId = EmailTracking::query()
            ->where('event_type', 'sent')
            ->whereIn('nylas_message_id', $messageIds)
            ->get(['nylas_message_id', 'metadata'])
            ->keyBy('nylas_message_id');

        $toDeleteIds = [];

        foreach ($rows as $row) {
            $messageId = is_string($row->nylas_message_id) ? $row->nylas_message_id : null;
            $ip = is_string($row->ip_address) ? $row->ip_address : null;

            $matchesSenderIp = false;
            $senderIp = null;

            if ($messageId && $ip) {
                $sent = $sentByMessageId->get($messageId);
                $senderIp = Arr::get($sent?->metadata ?? [], 'sender_ip');
                $matchesSenderIp = is_string($senderIp) && $senderIp !== '' && $senderIp === $ip;
            }

            if (! $force && ! $matchesSenderIp) {
                $this->warn("Skipping id {$row->id} (does not match sender_ip). Use --force to override.");
                continue;
            }

            $toDeleteIds[] = (int) $row->id;

            $reason = $matchesSenderIp ? 'sender_ip_match' : 'forced';
            $this->line("Will delete id {$row->id} ({$row->event_type}) reason={$reason} message_id={$messageId} ip={$ip} sender_ip=".(is_string($senderIp) ? $senderIp : 'n/a'));
        }

        $count = count($toDeleteIds);
        $this->info("Matched {$count} rows for deletion.");

        if ($count === 0) {
            return self::SUCCESS;
        }

        if (! $execute) {
            $this->warn('Dry-run (no deletes). Re-run with --execute to delete these rows.');
            return self::SUCCESS;
        }

        $deleted = EmailTracking::query()->whereIn('id', $toDeleteIds)->delete();
        $this->info("Deleted {$deleted} rows.");

        return self::SUCCESS;
    }
}
