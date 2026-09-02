<?php

namespace App\Console\Commands;

use App\Livewire\Leads\PickTimes;
use App\Models\ProjectStatus;
use App\Models\Task;
use Illuminate\Console\Command;

/**
 * A project parked in Consult (a won lead whose meeting hasn't happened yet)
 * advances to Estimate on its own once every Meet on it has passed — the
 * consult happening is what starts the estimating, no one should have to
 * flip the status by hand. Runs hourly; a consult ending at 11:30 has its
 * project estimating by noon.
 *
 * A Consult project whose meeting was cancelled (Meet task deleted) has no
 * upcoming Meet either — it advances too, rather than sitting in a state
 * that claims a meeting which no longer exists.
 */
class AdvancePastConsultProjects extends Command
{
    protected $signature = 'projects:advance-past-consults {--dry-run : Report without changing anything}';

    protected $description = 'Move Consult-status projects to Estimate once their meeting has passed';

    public function handle(): int
    {
        $tz = PickTimes::timezone();
        $dryRun = (bool) $this->option('dry-run');

        // Projects whose LATEST status row is Consult.
        $consultProjectIds = ProjectStatus::withoutGlobalScopes()
            ->where('status_code', 9)
            ->whereRaw(
                'project_status.id = (
                    select ps.id from project_status as ps
                    where ps.project_id = project_status.project_id
                    order by ps.start_date desc, ps.id desc
                    limit 1
                )'
            )
            ->pluck('belongs_to_vendor_id', 'project_id');

        $advanced = 0;

        foreach ($consultProjectIds as $projectId => $vendorId) {
            $hasUpcomingMeet = Task::withoutGlobalScopes()
                ->whereNull('deleted_at')
                ->where('project_id', $projectId)
                ->where('type', 'Meet')
                ->get()
                ->contains(fn (Task $task) => ! $task->meetHasPassed($tz));

            if ($hasUpcomingMeet) {
                continue;
            }

            if ($dryRun) {
                $this->line("would advance project {$projectId} to Estimate");
                $advanced++;

                continue;
            }

            ProjectStatus::create([
                'project_id' => $projectId,
                'belongs_to_vendor_id' => $vendorId,
                'status_code' => 2, // Estimate
                'start_date' => today()->format('Y-m-d'),
            ]);
            $advanced++;
        }

        $this->info(($dryRun ? 'would advance' : 'advanced') . ": {$advanced} project(s)");

        return self::SUCCESS;
    }
}
