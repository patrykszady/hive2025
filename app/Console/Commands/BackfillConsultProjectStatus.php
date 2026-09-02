<?php

namespace App\Console\Commands;

use App\Livewire\Leads\PickTimes;
use App\Models\ProjectStatus;
use App\Models\Task;
use Illuminate\Console\Command;

/**
 * One-time backfill for the Consult project status: projects created before
 * the status existed sit in Estimate even though their consult hasn't
 * happened yet. Any project whose latest status is Estimate and which has
 * an upcoming "… | Consult" Meet task (the exact title bookConsult() books
 * under) is parked in Consult — from where the hourly
 * projects:advance-past-consults run will advance it once the meeting
 * passes, same as projects parked at booking time.
 *
 * Idempotent: once parked, the latest status is Consult and the project no
 * longer matches. Invoked once per environment by the accompanying
 * migration.
 */
class BackfillConsultProjectStatus extends Command
{
    protected $signature = 'projects:backfill-consult-status {--dry-run : Report without changing anything}';

    protected $description = 'Park Estimate projects with an upcoming consult meeting in the Consult status';

    public function handle(): int
    {
        $tz = PickTimes::timezone();
        $dryRun = (bool) $this->option('dry-run');

        // Projects whose LATEST status row is Estimate.
        $estimateProjects = ProjectStatus::withoutGlobalScopes()
            ->where('status_code', 2)
            ->whereRaw(
                'project_status.id = (
                    select ps.id from project_status as ps
                    where ps.project_id = project_status.project_id
                    order by ps.start_date desc, ps.id desc
                    limit 1
                )'
            )
            ->pluck('belongs_to_vendor_id', 'project_id');

        $parked = 0;

        foreach ($estimateProjects as $projectId => $vendorId) {
            $hasUpcomingConsult = Task::withoutGlobalScopes()
                ->whereNull('deleted_at')
                ->where('project_id', $projectId)
                ->where('type', 'Meet')
                ->where('title', 'like', '%| Consult')
                ->get()
                ->contains(fn (Task $task) => ! $task->meetHasPassed($tz));

            if (! $hasUpcomingConsult) {
                continue;
            }

            if ($dryRun) {
                $this->line("would park project {$projectId} in Consult");
                $parked++;

                continue;
            }

            ProjectStatus::create([
                'project_id' => $projectId,
                'belongs_to_vendor_id' => $vendorId,
                'status_code' => 9, // Consult
                'start_date' => today()->format('Y-m-d'),
            ]);
            $parked++;
        }

        $this->info(($dryRun ? 'would park' : 'parked') . ": {$parked} project(s)");

        return self::SUCCESS;
    }
}
