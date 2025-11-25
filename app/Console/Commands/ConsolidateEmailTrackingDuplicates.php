<?php

namespace App\Console\Commands;

use App\Models\EmailTracking;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ConsolidateEmailTrackingDuplicates extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'email-tracking:consolidate-duplicates';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Consolidate duplicate email tracking rows into single message-level events with combined recipients';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Finding duplicate email tracking events...');

        // Find all duplicate groups (same message_id, event_type, and event_at)
        $duplicates = DB::table('email_tracking')
            ->select([
                'nylas_message_id',
                'event_type',
                'event_at',
                DB::raw('COUNT(*) as count'),
                DB::raw('MIN(id) as keep_id'),
                DB::raw('GROUP_CONCAT(id ORDER BY id) as all_ids'),
            ])
            ->whereIn('event_type', ['sent', 'opened', 'clicked', 'replied', 'bounced', 'rejected'])
            ->groupBy(['nylas_message_id', 'event_type', 'event_at'])
            ->having('count', '>', 1)
            ->get();

        if ($duplicates->isEmpty()) {
            $this->info('No duplicates found!');
            return 0;
        }

        $this->info("Found {$duplicates->count()} groups of duplicates to consolidate.");

        $consolidated = 0;
        $deleted = 0;

        foreach ($duplicates as $duplicate) {
            $ids = explode(',', $duplicate->all_ids);
            $keepId = $duplicate->keep_id;
            $deleteIds = array_diff($ids, [$keepId]);

            // Get all records in this group
            $records = EmailTracking::whereIn('id', $ids)->get();

            // Collect all unique recipients from all records
            $allRecipients = $records
                ->pluck('recipient_emails')
                ->flatten()
                ->unique()
                ->values()
                ->all();

            // Update the kept record with all recipients
            EmailTracking::where('id', $keepId)->update([
                'recipient_emails' => json_encode($allRecipients),
            ]);

            // Delete the duplicate records
            EmailTracking::whereIn('id', $deleteIds)->delete();

            $consolidated++;
            $deleted += count($deleteIds);

            $this->line("Consolidated {$duplicate->event_type} event for message {$duplicate->nylas_message_id} - kept ID {$keepId}, deleted " . count($deleteIds) . " duplicates");
        }

        $this->info("✓ Consolidated {$consolidated} duplicate groups, deleted {$deleted} duplicate rows");

        return 0;
    }
}
