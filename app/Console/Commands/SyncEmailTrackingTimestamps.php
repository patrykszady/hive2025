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
    protected $description = 'Sync created_at and updated_at to match event_at for email tracking records';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Syncing email_tracking timestamps...');
        $this->newLine();

        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
            $this->newLine();
        }

        // Find records where created_at is approximately 6 hours behind event_at (MST offset)
        $count = DB::table('email_tracking')
            ->whereRaw('TIMESTAMPDIFF(SECOND, event_at, created_at) BETWEEN -21610 AND -21590')
            ->count();

        if ($count === 0) {
            $this->info('✓ All timestamps are already synchronized!');
            return Command::SUCCESS;
        }

        $this->line("Found {$count} records with created_at ~6 hours behind event_at...");

        if ($dryRun) {
            $this->line("  ○ Would sync created_at and updated_at to match event_at for {$count} records");
            
            // Show some examples
            $examples = DB::table('email_tracking')
                ->whereRaw('TIMESTAMPDIFF(SECOND, event_at, created_at) BETWEEN -21610 AND -21590')
                ->limit(5)
                ->get(['id', 'event_type', 'event_at', 'created_at']);
            
            $this->newLine();
            $this->line('Examples:');
            foreach ($examples as $record) {
                $this->line("  ID {$record->id}: event_at={$record->event_at}, created_at={$record->created_at}");
            }
        } else {
            DB::statement("
                UPDATE email_tracking 
                SET created_at = event_at, 
                    updated_at = event_at
                WHERE TIMESTAMPDIFF(SECOND, event_at, created_at) BETWEEN -21610 AND -21590
            ");
            $this->info("  ✓ Synced {$count} records");
        }

        $this->newLine();
        $this->info('✓ Sync completed!');

        if ($dryRun) {
            $this->warn('This was a dry run. Run without --dry-run to apply changes.');
        }

        return Command::SUCCESS;
    }
}
