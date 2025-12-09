<?php

namespace App\Console\Commands;

use App\Models\EmailTracking;
use Illuminate\Console\Command;

class CleanYahooBotOpens extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'email-tracking:clean-bots {--dry-run : Show what would be deleted without actually deleting}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Remove email tracking events from Yahoo bots and Nylas duplicate webhooks';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $totalDeleted = 0;

        // 1. Clean Yahoo bot opens
        $this->info('Searching for Yahoo bot opens...');
        
        $yahooQuery = EmailTracking::query()
            ->where('event_type', 'opened')
            ->where('user_agent', 'like', '%YahooMailProxy%');

        $yahooCount = $yahooQuery->count();

        if ($yahooCount > 0) {
            $this->info("Found {$yahooCount} Yahoo bot opens.");

            if ($this->option('dry-run')) {
                $this->warn('DRY RUN: Would delete ' . $yahooCount . ' Yahoo bot records');
                
                $sample = $yahooQuery->limit(5)->get();
                $this->table(
                    ['ID', 'Event Type', 'Event At', 'User Agent'],
                    $sample->map(fn($record) => [
                        $record->id,
                        $record->event_type,
                        $record->event_at,
                        substr($record->user_agent, 0, 50) . '...',
                    ])
                );
            } else {
                if ($this->confirm("Delete {$yahooCount} Yahoo bot open events?", true)) {
                    $deleted = $yahooQuery->delete();
                    $this->info("Deleted {$deleted} Yahoo bot opens.");
                    $totalDeleted += $deleted;
                }
            }
        } else {
            $this->info('No Yahoo bot opens found.');
        }

        // 2. Clean Nylas duplicate webhooks (same opened_id)
        $this->newLine();
        $this->info('Searching for Nylas duplicate webhooks...');

        // Find duplicates by grouping on message_id, event_type, event_at, and opened_id
        $duplicates = EmailTracking::query()
            ->selectRaw('nylas_message_id, event_type, event_at, JSON_EXTRACT(metadata, "$.resolved_event_details.opened_id") as opened_id, COUNT(*) as count, MIN(id) as keep_id, GROUP_CONCAT(id) as all_ids')
            ->where('event_type', 'opened')
            ->whereNotNull('metadata')
            ->whereRaw('JSON_EXTRACT(metadata, "$.resolved_event_details.opened_id") IS NOT NULL')
            ->groupByRaw('nylas_message_id, event_type, event_at, opened_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        $duplicateCount = 0;
        $duplicateIds = [];

        foreach ($duplicates as $duplicate) {
            // Parse the comma-separated IDs and exclude the one we're keeping
            $allIds = explode(',', $duplicate->all_ids);
            $idsToDelete = array_filter($allIds, fn($id) => $id != $duplicate->keep_id);
            
            $duplicateIds = array_merge($duplicateIds, $idsToDelete);
            $duplicateCount += count($idsToDelete);
        }

        if ($duplicateCount > 0) {
            $this->info("Found {$duplicateCount} Nylas duplicate webhook records.");

            if ($this->option('dry-run')) {
                $this->warn('DRY RUN: Would delete ' . $duplicateCount . ' duplicate webhook records');
                
                $sample = EmailTracking::query()->whereIn('id', array_slice($duplicateIds, 0, 5))->get();
                $this->table(
                    ['ID', 'Event Type', 'Event At', 'User Agent'],
                    $sample->map(fn($record) => [
                        $record->id,
                        $record->event_type,
                        $record->event_at,
                        substr($record->user_agent ?? '', 0, 50) . '...',
                    ])
                );
            } else {
                if ($this->confirm("Delete {$duplicateCount} duplicate webhook events?", true)) {
                    $deleted = EmailTracking::query()->whereIn('id', $duplicateIds)->delete();
                    $this->info("Deleted {$deleted} duplicate webhook records.");
                    $totalDeleted += $deleted;
                }
            }
        } else {
            $this->info('No Nylas duplicate webhooks found.');
        }

        if (!$this->option('dry-run') && $totalDeleted > 0) {
            $this->newLine();
            $this->info("Total deleted: {$totalDeleted} records");
        }

        return self::SUCCESS;
    }
}
