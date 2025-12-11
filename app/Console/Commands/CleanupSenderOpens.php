<?php

namespace App\Console\Commands;

use App\Models\CompanyEmail;
use App\Models\EmailTracking;
use Illuminate\Console\Command;

class CleanupSenderOpens extends Command
{
    protected $signature = 'email:cleanup-sender-opens 
                            {--dry-run : Show what would be deleted without actually deleting}
                            {--ip=* : Specific IP addresses to clean up (use with caution)}
                            {--force : Skip confirmation prompts}';

    protected $description = 'Remove email open events that were made by the sender (not actual recipients)';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $specificIps = $this->option('ip');
        $force = $this->option('force');

        $this->info($dryRun ? '🔍 DRY RUN - No data will be deleted' : '🗑️  Analyzing sender opens...');
        $this->newLine();

        $toDelete = [];

        // Strategy 1: Opens from sender IPs (stored in sent records)
        $toDelete['sender_ip'] = $this->findBySenderIp();

        // Strategy 2: Opens from specific IPs (only if explicitly provided)
        if (!empty($specificIps)) {
            $toDelete['specific_ips'] = $this->findBySpecificIps($specificIps);
        }

        // Strategy 3: Opens from known bot user agents
        $toDelete['bots'] = $this->findBotOpens();

        // Show summary
        $this->showSummary($toDelete);

        if ($dryRun) {
            $this->newLine();
            $this->info('Run without --dry-run to delete these records');
            return Command::SUCCESS;
        }

        // Confirm before deleting
        if (!$force) {
            $this->newLine();
            $this->warn('⚠️  WARNING: This will permanently delete the records shown above.');
            
            if (!$this->confirm('Are you sure you want to proceed?', false)) {
                $this->info('Aborted.');
                return Command::SUCCESS;
            }
        }

        // Actually delete
        $totalDeleted = $this->executeDelete($toDelete);

        $this->newLine();
        $this->info("✅ Deleted {$totalDeleted} open events");

        return Command::SUCCESS;
    }

    /**
     * Find opens where the opener IP matches the sender IP stored in the sent record.
     */
    protected function findBySenderIp(): array
    {
        $this->info('Strategy 1: Matching sender IP from sent records...');

        $results = [];

        $sentRecordsWithSenderIp = EmailTracking::query()
            ->where('event_type', 'sent')
            ->whereNotNull('metadata->sender_ip')
            ->get(['nylas_message_id', 'metadata', 'recipient_emails']);

        foreach ($sentRecordsWithSenderIp as $sent) {
            $senderIp = $sent->metadata['sender_ip'] ?? null;
            if (!$senderIp) {
                continue;
            }

            $opens = EmailTracking::query()
                ->where('nylas_message_id', $sent->nylas_message_id)
                ->where('event_type', 'opened')
                ->where('ip_address', $senderIp)
                ->get();

            foreach ($opens as $open) {
                $results[] = [
                    'id' => $open->id,
                    'recipient' => $open->recipient_emails[0] ?? 'unknown',
                    'ip' => $open->ip_address,
                    'event_at' => $open->event_at,
                    'reason' => 'Sender IP match',
                ];
            }
        }

        $this->info("  Found " . count($results) . " opens from sender IPs");
        return $results;
    }

    /**
     * Find opens from specific IP addresses.
     */
    protected function findBySpecificIps(array $specificIps): array
    {
        $this->info('Strategy 2: Specific IPs...');
        $this->warn('  ⚠️  Use caution: IPs can be shared by multiple recipients!');

        $results = [];

        foreach ($specificIps as $ip) {
            $opens = EmailTracking::query()
                ->where('event_type', 'opened')
                ->where('ip_address', $ip)
                ->get();

            foreach ($opens as $open) {
                $results[] = [
                    'id' => $open->id,
                    'recipient' => $open->recipient_emails[0] ?? 'unknown',
                    'ip' => $open->ip_address,
                    'event_at' => $open->event_at,
                    'reason' => 'Specified IP',
                ];
            }
        }

        $this->info("  Found " . count($results) . " opens from specified IPs");
        return $results;
    }

    /**
     * Find opens from known bot/prefetch user agents.
     */
    protected function findBotOpens(): array
    {
        $this->info('Strategy 3: Known bot/prefetch user agents...');

        $results = [];

        // Generic Mozilla/5.0 only
        $genericOpens = EmailTracking::query()
            ->where('event_type', 'opened')
            ->whereRaw("TRIM(user_agent) = 'Mozilla/5.0'")
            ->get();

        foreach ($genericOpens as $open) {
            $results[] = [
                'id' => $open->id,
                'recipient' => $open->recipient_emails[0] ?? 'unknown',
                'ip' => $open->ip_address,
                'event_at' => $open->event_at,
                'reason' => 'Bot: Generic Mozilla/5.0',
            ];
        }

        // Known bot patterns
        $botPatterns = ['GoogleImageProxy', 'YahooMailProxy', 'Outlook-iOS-Android'];
        
        foreach ($botPatterns as $pattern) {
            $opens = EmailTracking::query()
                ->where('event_type', 'opened')
                ->where('user_agent', 'LIKE', "%{$pattern}%")
                ->get();

            foreach ($opens as $open) {
                $results[] = [
                    'id' => $open->id,
                    'recipient' => $open->recipient_emails[0] ?? 'unknown',
                    'ip' => $open->ip_address,
                    'event_at' => $open->event_at,
                    'reason' => "Bot: {$pattern}",
                ];
            }
        }

        $this->info("  Found " . count($results) . " bot/prefetch opens");
        return $results;
    }

    /**
     * Show detailed summary of what will be deleted.
     */
    protected function showSummary(array $toDelete): void
    {
        $this->newLine();
        $this->info('📋 Records to be deleted:');
        $this->newLine();

        $allRecords = collect($toDelete)->flatten(1);
        
        if ($allRecords->isEmpty()) {
            $this->info('  No records found to delete.');
            return;
        }

        // Group by recipient for clarity
        $byRecipient = $allRecords->groupBy('recipient');

        foreach ($byRecipient as $recipient => $records) {
            $this->line("  <fg=cyan>{$recipient}</> ({$records->count()} opens)");
            foreach ($records as $record) {
                $this->line("    - {$record['event_at']} | IP: {$record['ip']} | {$record['reason']}");
            }
        }

        $this->newLine();
        $this->warn("Total: {$allRecords->count()} records");
    }

    /**
     * Execute the actual deletion.
     */
    protected function executeDelete(array $toDelete): int
    {
        $ids = collect($toDelete)->flatten(1)->pluck('id')->unique()->toArray();
        
        if (empty($ids)) {
            return 0;
        }

        return EmailTracking::query()->whereIn('id', $ids)->delete();
    }
}
