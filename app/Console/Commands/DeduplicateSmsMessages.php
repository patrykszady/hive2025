<?php

namespace App\Console\Commands;

use App\Models\SmsMessage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DeduplicateSmsMessages extends Command
{
    protected $signature = 'sms:deduplicate {--dry-run : Show what would be deleted without making changes}';

    protected $description = 'Remove duplicate inbound SMS messages caused by webhook retries, keeping the first occurrence of each message';

    public function handle(): int
    {
        $isDryRun = $this->option('dry-run');

        if ($isDryRun) {
            $this->info('🔍 DRY RUN MODE - No changes will be made');
            $this->newLine();
        }

        // Find all duplicate inbound messages by provider_message_id
        $duplicateGroups = SmsMessage::where('direction', 'inbound')
            ->whereNotNull('provider_message_id')
            ->select('provider_message_id', DB::raw('COUNT(*) as cnt'))
            ->groupBy('provider_message_id')
            ->having('cnt', '>', 1)
            ->pluck('provider_message_id')
            ->toArray();

        if (empty($duplicateGroups)) {
            $this->info('✓ No duplicate inbound messages found.');
            return self::SUCCESS;
        }

        $this->info('Found ' . count($duplicateGroups) . ' messages with duplicates.');
        $this->newLine();

        $totalDeleted = 0;

        foreach ($duplicateGroups as $providerId) {
            // Get all messages with this provider ID, ordered by created_at (keep first)
            $messages = SmsMessage::where('provider_message_id', $providerId)
                ->where('direction', 'inbound')
                ->orderBy('created_at', 'asc')
                ->get(['id', 'thread_id', 'created_at', 'from_number', 'text']);

            if ($messages->count() > 1) {
                $idsToDelete = $messages->skip(1)->pluck('id')->toArray();
                $count = count($idsToDelete);
                $firstMessage = $messages->first();

                $this->line(
                    "Provider ID: <fg=cyan>{$providerId}</> | "
                    . "Count: {$messages->count()} | "
                    . "Deleting: {$count} | "
                    . "Kept: ID {$firstMessage->id} (Thread: {$firstMessage->thread_id})"
                );

                if (!$isDryRun) {
                    SmsMessage::whereIn('id', $idsToDelete)->delete();
                }

                $totalDeleted += $count;
            }
        }

        $this->newLine();
        if ($isDryRun) {
            $this->warn("DRY RUN: Would delete {$totalDeleted} duplicate messages");
            $this->info('Run without --dry-run flag to apply changes');
        } else {
            $this->info("✓ Successfully deleted {$totalDeleted} duplicate messages");
        }

        return self::SUCCESS;
    }
}
