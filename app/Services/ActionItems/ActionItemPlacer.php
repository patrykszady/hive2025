<?php

namespace App\Services\ActionItems;

use App\Models\CallLog;
use App\Models\EmailMessage;
use App\Models\Project;
use App\Models\ProjectActionItem;
use Illuminate\Support\Carbon;

/**
 * Puts an unplaced action item (project_id null — see
 * ProjectActionItem::scopeUnplaced()) onto the job it is about: the call or
 * email it came from goes with it (so what it says next is read there too),
 * and a dated visit becomes the project's task at once when the day is
 * someone else's to set (ScheduledVisitApplier).
 *
 * Shared by the web planner's "Needs a project" picker
 * (App\Livewire\Planner\CardsIndex::placeActionItem()) and the app's own
 * (App\Http\Controllers\Api\App\V1\PlannerController::place(), via
 * App\Services\Planner\AppPlannerAgenda::place()) — the one rule is that
 * neither duplicates this logic.
 */
class ActionItemPlacer
{
    public function __construct(private ScheduledVisitApplier $visitApplier) {}

    /**
     * Returns false (a no-op) when $item is already placed — callers that
     * also need to validate $project belongs to the item's own company
     * should do so before calling this (both current callers already fetch
     * $project through a scope that only returns visible projects).
     */
    public function place(ProjectActionItem $item, Project $project): bool
    {
        if ($item->project_id) {
            return false;
        }

        $item->update(['project_id' => $project->id]);

        foreach ((array) ($item->sources ?? []) as $source) {
            $model = match ($source['kind'] ?? null) {
                'call' => CallLog::withoutGlobalScopes()->find($source['id'] ?? 0),
                'email' => EmailMessage::withoutGlobalScopes()->find($source['id'] ?? 0),
                default => null,
            };
            if ($model && ! $model->project_id) {
                $model->forceFill(['project_id' => $project->id])->saveQuietly();
            }
        }

        $last = collect($item->sources ?? [])->last();
        if ($item->isVisit() && $item->due_on && is_array($last)) {
            $this->visitApplier->apply(
                $item->fresh(),
                ['at' => Carbon::parse($last['at'] ?? now()), 'source' => $last],
            );
        }

        return true;
    }
}
