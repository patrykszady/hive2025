<?php

namespace App\Console\Commands;

use App\Models\ProjectStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ConsolidateServiceCallComplete extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'project:consolidate-service-call-complete';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Consolidate Service Call Complete (code 9) to Complete (code 7)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Consolidating Service Call Complete statuses...');
        
        // Count records to update
        $count = ProjectStatus::where('status_code', 9)->count();
        
        if ($count === 0) {
            $this->info('No Service Call Complete records found.');
            return Command::SUCCESS;
        }
        
        $this->info("Found {$count} Service Call Complete records to convert.");
        
        if (!$this->confirm('Do you want to proceed with the conversion?', true)) {
            $this->warn('Operation cancelled.');
            return Command::FAILURE;
        }
        
        // Update all status_code 9 to 7
        $updated = DB::table('project_status')
            ->where('status_code', 9)
            ->update(['status_code' => 7]);
        
        $this->info("Successfully converted {$updated} records from Service Call Complete to Complete.");
        
        // Show sample of converted records
        $samples = ProjectStatus::where('status_code', 7)
            ->with('project:id,project_name')
            ->latest('updated_at')
            ->limit(5)
            ->get(['id', 'project_id', 'status_code', 'start_date']);
        
        if ($samples->isNotEmpty()) {
            $this->newLine();
            $this->info('Sample of converted records:');
            $this->table(
                ['ID', 'Project', 'Status Code', 'Status', 'Start Date'],
                $samples->map(fn($s) => [
                    $s->id,
                    $s->project->project_name ?? 'N/A',
                    $s->status_code,
                    $s->title,
                    $s->start_date?->format('Y-m-d') ?? 'N/A',
                ])
            );
        }
        
        return Command::SUCCESS;
    }
}
