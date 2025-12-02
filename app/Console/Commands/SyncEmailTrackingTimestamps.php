<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncEmailTrackingTimestamps extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'email-tracking:sync-timestamps 
                            {--dry-run : Show what would be changed without making changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fix updated_at timestamps that were corrupted during timezone conversion';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Fixing email_tracking updated_at timestamps...');
        $this->newLine();

        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
            $this->newLine();
        }

        // Find records where updated_at doesn't match created_at
        // For immutable event logs, these should always be the same
        $count = DB::table('email_tracking')
            ->whereRaw('updated_at != created_at')
            ->count();

        if ($count === 0) {
            $this->info('✓ All timestamps are already synchronized!');
            return Command::SUCCESS;
        }

        $this->line("Found {$count} records where updated_at doesn't match created_at...");

        if ($dryRun) {
            $this->line("  ○ Would set updated_at = created_at for {$count} records");
            
            // Show some examples
            $examples = DB::table('email_tracking')
                ->whereRaw('updated_at != created_at')
                ->limit(5)
                ->get(['id', 'event_type', 'event_at', 'created_at', 'updated_at']);
            
            $this->newLine();
            $this->line('Examples:');
            foreach ($examples as $record) {
                $this->line("  ID {$record->id}: event_at={$record->event_at}, created_at={$record->created_at}, updated_at={$record->updated_at}");
            }
        } else {
            DB::statement("
                UPDATE email_tracking 
                SET updated_at = created_at
            ");
            $this->info("  ✓ Synced {$count} records (set updated_at = created_at)");
        }

        $this->newLine();
        $this->info('✓ Sync completed!');

        if ($dryRun) {
            $this->warn('This was a dry run. Run without --dry-run to apply changes.');
        }

        return Command::SUCCESS;
    }
}
