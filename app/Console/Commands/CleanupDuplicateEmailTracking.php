<?php

namespace App\Console\Commands;

use App\Models\EmailTracking;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanupDuplicateEmailTracking extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'email-tracking:cleanup-duplicates 
                            {--dry-run : Show what would be deleted without actually deleting}
                            {--seconds=5 : Time window in seconds to consider duplicates}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Remove duplicate email tracking events that occurred within a few seconds of each other';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $windowSeconds = (int) $this->option('seconds');

        $this->info("Finding duplicate 'opened' events within {$windowSeconds} seconds...");
        
        if ($dryRun) {
            $this->warn('DRY RUN MODE - No records will be deleted');
        }

        $duplicatesFound = 0;
        $duplicatesDeleted = 0;

        // Get all opened events grouped by message and recipient
        $openedEvents = EmailTracking::query()
            ->where('event_type', 'opened')
            ->orderBy('nylas_message_id')
            ->orderBy('event_at')
            ->get()
            ->groupBy(function ($event) {
                return $event->nylas_message_id . '|' . json_encode($event->recipient_emails);
            });

        foreach ($openedEvents as $groupKey => $events) {
            if ($events->count() < 2) {
                continue;
            }

            // Check each pair of events
            $eventsArray = $events->sortBy('event_at')->values();
            $toDelete = [];

            for ($i = 0; $i < $eventsArray->count() - 1; $i++) {
                $current = $eventsArray[$i];
                $next = $eventsArray[$i + 1];

                $timeDiff = $current->event_at->diffInSeconds($next->event_at);

                if ($timeDiff <= $windowSeconds) {
                    $toDelete[] = $next->id;
                    $duplicatesFound++;

                    $this->line(sprintf(
                        'Found duplicate: ID %d (kept) and ID %d (marked for deletion) - %d second(s) apart',
                        $current->id,
                        $next->id,
                        $timeDiff
                    ));
                }
            }

            if (!$dryRun && !empty($toDelete)) {
                $deleted = EmailTracking::whereIn('id', $toDelete)->delete();
                $duplicatesDeleted += $deleted;
            }
        }

        if ($duplicatesFound === 0) {
            $this->info('No duplicates found!');
            return self::SUCCESS;
        }

        $this->newLine();
        $this->info("Found {$duplicatesFound} duplicate records");

        if ($dryRun) {
            $this->warn('DRY RUN: No records were deleted. Run without --dry-run to delete duplicates.');
        } else {
            $this->info("Deleted {$duplicatesDeleted} duplicate records");
        }

        return self::SUCCESS;
    }
}
