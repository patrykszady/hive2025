<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Models\ProjectStatus;
use Carbon\Carbon;
use Illuminate\Console\Command;

class ActivateScheduledProjects extends Command
{
    protected $signature = 'projects:activate-scheduled';

    protected $description = 'Activate Scheduled projects whose estimate start_date is tomorrow or earlier';

    public function handle(): int
    {
        $tomorrow = now()->addDay()->toDateString();

        $projects = Project::query()
            ->whereHas('latestStatus', fn ($q) => $q->where('status_code', 5))
            ->with(['latestStatus', 'estimates'])
            ->get();

        $count = 0;

        foreach ($projects as $project) {
            $estimateStartDate = $project->estimates
                ->map(fn ($e) => $e->start_date)
                ->filter()
                ->sort()
                ->first();

            if (! $estimateStartDate || $estimateStartDate->toDateString() > $tomorrow) {
                continue;
            }

            ProjectStatus::create([
                'project_id'           => $project->id,
                'belongs_to_vendor_id' => $project->latestStatus->belongs_to_vendor_id,
                'status_code'          => 6,
                'start_date'           => $estimateStartDate->toDateString(),
            ]);

            $count++;
        }

        if ($count === 0) {
            $this->info('No scheduled projects to activate.');
        } else {
            $this->info("Activated {$count} project(s).");
        }

        return self::SUCCESS;
    }
}
