<?php

namespace App\Console\Commands;

use App\Models\Check;
use App\Models\Timesheet;
use Illuminate\Console\Command;

class FixCheck2973 extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fix:check-2973 {--dry-run : Show what would be changed without making changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fix Check 2973 - Update payee to Grzegorz and set timesheet paid_by correctly';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $dryRun = $this->option('dry-run');

        $this->info('=== Fixing Check 2973 ===');
        
        // Get check and timesheet
        $check = Check::find(2973);
        $timesheet = Timesheet::find(5795);
        
        if (!$check) {
            $this->error('Check 2973 not found!');
            return 1;
        }
        
        if (!$timesheet) {
            $this->error('Timesheet 5795 not found!');
            return 1;
        }

        $this->info('Current state:');
        $this->line("Check payee: {$check->user->full_name} (ID: {$check->user_id})");
        $this->line("Timesheet worker: {$timesheet->user->full_name} (ID: {$timesheet->user_id})");
        $this->line("Timesheet paid_by: " . ($timesheet->paid_by ? "ID: {$timesheet->paid_by}" : 'null'));

        $this->info("\nChanges to make:");
        $this->line("• Update check payee to Grzegorz Szady (ID: 2)");
        $this->line("• Update timesheet paid_by to Grzegorz Szady (ID: 2)");
        $this->line("• This reflects: Andzelina did work, Grzegorz got Zelle payment");

        if ($dryRun) {
            $this->warn("\n[DRY RUN] No changes made. Remove --dry-run to apply.");
            return 0;
        }

        if (!$this->confirm("\nApply these changes?")) {
            $this->info('Cancelled.');
            return 0;
        }

        // Make the changes
        $check->user_id = 2; // Grzegorz
        $check->save();
        
        $timesheet->paid_by = 2; // Grzegorz paid Andzelina
        $timesheet->save();

        $this->info("\n✅ Fixed!");
        $this->line("Check 2973 payee: {$check->user->full_name}");
        $this->line("Timesheet 5795: {$timesheet->user->full_name} paid by Grzegorz");
        
        return 0;
    }
}
