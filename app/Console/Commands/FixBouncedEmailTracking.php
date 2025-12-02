<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixBouncedEmailTracking extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'email-tracking:fix-bounced 
                            {--dry-run : Show what would be changed without making changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Mark email tracking records 42 and 47 as bounced (they are error notifications, not replies)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Fixing email tracking records 42 and 47 to bounced...');
        $this->newLine();

        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
            $this->newLine();
        }

        // Specific IDs that are known to be bounce notifications
        $knownBounceIds = [42, 47];

        $this->line('Processing known bounce notifications (IDs: ' . implode(', ', $knownBounceIds) . ')...');

        if ($dryRun) {
            $records = DB::table('email_tracking')
                ->whereIn('id', $knownBounceIds)
                ->get(['id', 'event_type']);
            
            foreach ($records as $record) {
                $this->line("  ○ Would change ID {$record->id} from '{$record->event_type}' to 'bounced'");
            }
        } else {
            $updated = DB::table('email_tracking')
                ->whereIn('id', $knownBounceIds)
                ->update(['event_type' => 'bounced']);
            
            $this->info("  ✓ Updated {$updated} records to event_type = 'bounced'");
        }

        $this->newLine();
        $this->info('✓ Fix completed!');

        if ($dryRun) {
            $this->warn('This was a dry run. Run without --dry-run to apply changes.');
        }

        return Command::SUCCESS;
    }
}
