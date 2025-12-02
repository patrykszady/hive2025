<?php

namespace App\Console\Commands;

use App\Models\EmailTracking;
use Carbon\Carbon;
use Illuminate\Console\Command;

class ResetEmailTrackingTimestamps extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'email-tracking:reset-timestamps 
                            {--dry-run : Show what would be changed without making changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reset all email_tracking timestamps from Nylas raw timestamps in metadata';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Resetting email_tracking timestamps from Nylas metadata...');
        $this->newLine();

        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
            $this->newLine();
        }

        // Get all records
        $records = EmailTracking::all();
        $fixed = 0;
        $errors = 0;
        $skipped = 0;

        foreach ($records as $record) {
            // Try to get raw timestamp from metadata
            $rawTimestamp = null;
            
            // Check new webhook payload format first (IDs 62, 63, 64)
            if (isset($record->metadata['nylas_webhook_payload']['raw_timestamp'])) {
                $rawTimestamp = $record->metadata['nylas_webhook_payload']['raw_timestamp'];
            }
            // Check older metadata format
            elseif (isset($record->metadata['object']['timestamp'])) {
                $rawTimestamp = $record->metadata['object']['timestamp'];
            }
            
            if (!$rawTimestamp) {
                $this->line("  ⚠ ID {$record->id}: No raw timestamp in metadata, skipping");
                $skipped++;
                continue;
            }

            // Convert Unix timestamp to UTC Carbon instance
            $correctEventAt = Carbon::createFromTimestamp($rawTimestamp, 'UTC');
            
            // For immutable event logs: created_at and updated_at should equal event_at
            $changes = [];
            if (!$record->event_at->eq($correctEventAt)) {
                $changes[] = "event_at: {$record->event_at} → {$correctEventAt}";
            }
            if (!$record->created_at->eq($correctEventAt)) {
                $changes[] = "created_at: {$record->created_at} → {$correctEventAt}";
            }
            if (!$record->updated_at->eq($correctEventAt)) {
                $changes[] = "updated_at: {$record->updated_at} → {$correctEventAt}";
            }

            if (empty($changes)) {
                // Already correct
                continue;
            }

            if ($dryRun) {
                $this->line("  ○ ID {$record->id} ({$record->event_type}): " . implode(', ', $changes));
            } else {
                try {
                    // Disable timestamps temporarily to prevent auto-update
                    $record->timestamps = false;
                    
                    $record->event_at = $correctEventAt;
                    $record->created_at = $correctEventAt;
                    $record->updated_at = $correctEventAt;
                    
                    $record->save();
                    
                    $this->line("  ✓ ID {$record->id} fixed");
                } catch (\Exception $e) {
                    $this->error("  ✗ ID {$record->id} error: " . $e->getMessage());
                    $errors++;
                    continue;
                }
            }
            
            $fixed++;
        }

        $this->newLine();
        $this->info("Summary:");
        $this->info("  Fixed: {$fixed}");
        $this->info("  Skipped (no timestamp in metadata): {$skipped}");
        if ($errors > 0) {
            $this->error("  Errors: {$errors}");
        }

        if ($dryRun) {
            $this->newLine();
            $this->warn('This was a dry run. Run without --dry-run to apply changes.');
        }

        return Command::SUCCESS;
    }
}
