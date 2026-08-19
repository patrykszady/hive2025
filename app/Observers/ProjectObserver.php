<?php

namespace App\Observers;

use App\Jobs\MarkClientLeadWon;
use App\Models\Project;
use App\Models\ProjectStatus;

class ProjectObserver
{
    /**
     * project id => deleted_at, captured in restoring() and consumed by
     * restored(). Keyed so nested/bulk restores cannot cross wires.
     *
     * @var array<int|string, \Carbon\CarbonInterface|null>
     */
    protected static array $restoringDeletedAt = [];

    public function creating(Project $project): void
    {
        $user = auth()->user();
        $project->belongs_to_vendor_id = $user->vendor->id;
    }

    /**
     * Handle the Project "created" event.
     */
    public function created(Project $project): void
    {
        $project->vendors()->attach($project->belongs_to_vendor_id, ['client_id' => $project->client_id]);

        // Create initial Estimate status (code 2)
        ProjectStatus::create([
            'project_id' => $project->id,
            'belongs_to_vendor_id' => $project->belongs_to_vendor_id,
            'status_code' => 2, // Estimate
            'start_date' => today()->format('Y-m-d'),
        ]);

        // The client now has work — any New lead behind them has converted.
        if ($project->client_id) {
            MarkClientLeadWon::dispatch($project->client_id, $project->belongs_to_vendor_id);
        }
    }

    /**
     * Handle the Project "updated" event.
     */
    public function updated(Project $project): void
    {
        //
    }

    /**
     * Handle the Project "deleted" event.
     *
     * Soft delete only — the force-delete path fires forceDeleted() below.
     * Estimates are the one child listed independently of their project
     * (/estimates queries every estimate with withTrashed()), so an untouched
     * estimate would keep showing there with a blank project column. Everything
     * else — statuses, images, expenses — stays put: it is only reachable
     * through the project, which is now hidden, so it is preserved rather than
     * destroyed and comes straight back on restore.
     */
    public function deleted(Project $project): void
    {
        if ($project->isForceDeleting()) {
            return;
        }

        $project->estimates()->delete();
    }

    /**
     * Handle the Project "restoring" event.
     *
     * deleted_at is already null by the time restored() runs, so stash it here:
     * it is what tells the cascade apart from estimates that were disabled on
     * their own before the project was deleted.
     */
    public function restoring(Project $project): void
    {
        static::$restoringDeletedAt[$project->getKey()] = $project->deleted_at;
    }

    /**
     * Handle the Project "restored" event.
     *
     * Restores only the estimates this deletion took down — matched on the
     * project's own deleted_at. An estimate disabled last week stays disabled.
     */
    public function restored(Project $project): void
    {
        $deletedAt = static::$restoringDeletedAt[$project->getKey()] ?? null;
        unset(static::$restoringDeletedAt[$project->getKey()]);

        if (! $deletedAt) {
            return;
        }

        // A second of slack: the cascade runs in the same request, but the
        // parent and children can land either side of a tick.
        $project->estimates()
            ->onlyTrashed()
            ->whereBetween('deleted_at', [
                $deletedAt->copy()->subSecond(),
                $deletedAt->copy()->addSecond(),
            ])
            ->restore();
    }

    /**
     * Handle the Project "force deleted" event.
     */
    public function forceDeleted(Project $project): void
    {
        // Delete pivot records
        \DB::table('project_vendor')->where('project_id', $project->id)->delete();
        
        // Delete related records
        $project->statuses()->delete();
        $project->estimates()->delete();
    }
}
