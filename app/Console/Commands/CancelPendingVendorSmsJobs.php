<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

class CancelPendingVendorSmsJobs extends Command
{
    protected $signature = 'jobs:cancel-vendor-sms {--dry-run : Show what would be deleted without actually deleting}';

    protected $description = 'Cancel all pending SendBatchVendorAvailabilitySms jobs from the queue';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $removed = 0;

        // Check delayed jobs in default queue
        $delayed = Redis::zrange('queues:default:delayed', 0, -1);
        
        $this->info("Found " . count($delayed) . " delayed job(s) in default queue");

        foreach ($delayed as $job) {
            if (str_contains($job, 'SendBatchVendorAvailabilitySms')) {
                $this->line("Found: SendBatchVendorAvailabilitySms job");
                
                if (!$dryRun) {
                    Redis::zrem('queues:default:delayed', $job);
                    $this->info("  → Removed!");
                } else {
                    $this->warn("  → Would be removed (dry-run)");
                }
                $removed++;
            }
        }

        // Also check reserved jobs
        $reserved = Redis::zrange('queues:default:reserved', 0, -1);
        
        foreach ($reserved as $job) {
            if (str_contains($job, 'SendBatchVendorAvailabilitySms')) {
                $this->line("Found reserved: SendBatchVendorAvailabilitySms job");
                
                if (!$dryRun) {
                    Redis::zrem('queues:default:reserved', $job);
                    $this->info("  → Removed!");
                } else {
                    $this->warn("  → Would be removed (dry-run)");
                }
                $removed++;
            }
        }

        if ($removed === 0) {
            $this->info("No SendBatchVendorAvailabilitySms jobs found.");
        } else {
            $action = $dryRun ? "Would remove" : "Removed";
            $this->info("{$action} {$removed} job(s).");
        }

        return 0;
    }
}
