<?php

namespace App\Console\Commands;

use App\Models\AppNotification;
use App\Models\Project;
use App\Models\Task;
use App\Scopes\ProjectScope;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Moves a submitted service_availability off a project that no longer has
 * any pending (unscheduled) task to cover it, onto the same client's project
 * that does — the Krupa bug (Patryk, 2026-11-07): a homeowner's schedule
 * link for a long-finished project with zero tasks saved its preferred
 * times there, while the real pending work, and the Hub notification meant
 * to reach it, sat stranded on a sibling job at the same address.
 *
 * Re-runnable and idempotent: once a source project's service_availability
 * is cleared, it no longer qualifies as a source, so a second run (dry or
 * applied) changes nothing. Safe against the dev DB being refreshed from
 * production — it only ever acts on whatever service_availability rows
 * exist at run time.
 */
class RepairServiceAvailability extends Command
{
    protected $signature = 'service-availability:repair
        {--apply : Write the moves (without this it only reports what it would do)}
        {--dry-run : Explicitly report without changing anything (the default — wins over --apply if both are given)}
        {--project= : Limit to one source project id}';

    protected $description = 'Move a stranded service_availability submission onto the same client\'s project that actually has pending work, and re-point its Hub notification.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply') && ! $this->option('dry-run');
        $projectId = $this->option('project') !== null ? (int) $this->option('project') : null;

        $sources = Project::withoutGlobalScope(ProjectScope::class)
            ->whereNotNull('service_availability')
            ->when($projectId, fn ($query) => $query->where('id', $projectId))
            ->get()
            ->filter(fn (Project $project): bool => ! empty(data_get($project->service_availability, 'slots')))
            ->filter(fn (Project $project): bool => ! Task::query()
                ->where('project_id', $project->id)
                ->whereNull('start_date')
                ->exists())
            ->values();

        if ($sources->isEmpty()) {
            $this->info('Nothing to repair — every project holding preferred times still has a pending task of its own.');

            return self::SUCCESS;
        }

        $this->line($apply
            ? "Repairing among {$sources->count()} project(s) with no pending task of their own."
            : "DRY RUN — {$sources->count()} project(s) examined. Re-run with --apply to write.");
        $this->newLine();

        $moved = 0;

        foreach ($sources as $source) {
            $target = Task::bestServiceAvailabilityProject($source);

            if (! $target) {
                $this->line("  project {$source->id}: SKIPPED, no sibling project with pending work for this client");

                continue;
            }

            $sourceSubmittedAt = data_get($source->service_availability, 'submitted_at');
            $targetSubmittedAt = data_get($target->service_availability, 'submitted_at');

            if ($targetSubmittedAt && $sourceSubmittedAt
                && Carbon::parse($targetSubmittedAt)->greaterThan(Carbon::parse($sourceSubmittedAt))) {
                $this->line("  project {$source->id} -> project {$target->id}: SKIPPED, target already has a newer submission of its own ({$targetSubmittedAt})");

                continue;
            }

            $targetTaskIds = Task::query()
                ->where('project_id', $target->id)
                ->whereNull('start_date')
                ->orderBy('id')
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $slotCount = count((array) data_get($source->service_availability, 'slots', []));

            $this->line(sprintf(
                '  project %d -> project %d (%d slot%s, submitted %s, task_ids [%s])',
                $source->id,
                $target->id,
                $slotCount,
                $slotCount === 1 ? '' : 's',
                $sourceSubmittedAt,
                implode(',', $targetTaskIds)
            ));

            $moved++;

            if (! $apply) {
                continue;
            }

            $target->forceFill([
                'service_availability' => [
                    'slots' => data_get($source->service_availability, 'slots', []),
                    'task_ids' => $targetTaskIds,
                    'submitted_at' => $sourceSubmittedAt,
                ],
            ])->save();

            $source->forceFill(['service_availability' => null])->save();

            $this->repointNotifications($source, $target);
        }

        $this->newLine();
        $this->info($moved === 0
            ? 'None of them have a usable target.'
            : ($apply ? "{$moved} moved." : "{$moved} would be moved. Nothing was written."));

        return self::SUCCESS;
    }

    /**
     * Re-point every service_availability_submitted AppNotification whose
     * data.project_id was $source to $target instead — data.project_id,
     * data.task_id and action_url all move together, the same shape
     * ScheduleIndex::createServiceAvailabilityHubNotifications() writes on a
     * fresh submission, so an old notification opens exactly where a new one
     * would.
     */
    private function repointNotifications(Project $source, Project $target): void
    {
        $task = Task::pendingForServiceAvailability($target);
        $actionUrl = $task
            ? route('planner.cards', ['task' => $task->id])
            : route('projects.show', $target);

        AppNotification::query()
            ->where('type', 'service_availability_submitted')
            ->where('data->project_id', $source->id)
            ->get()
            ->each(function (AppNotification $notification) use ($target, $task, $actionUrl): void {
                $notification->update([
                    'data' => array_merge((array) $notification->data, [
                        'project_id' => $target->id,
                        'task_id' => $task?->id,
                    ]),
                    'action_url' => $actionUrl,
                ]);
            });
    }
}
