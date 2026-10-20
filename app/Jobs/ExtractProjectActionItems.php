<?php

namespace App\Jobs;

use App\Livewire\Planner\CardsIndex;
use App\Models\Project;
use App\Services\ActionItems\ProjectActionItemExtractor;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;

/**
 * Re-reads a project's action items right after a stakeholder email brings
 * new ones in (SummarizeEmailMessage) — the on-demand counterpart of the
 * nightly `project:action-items --active` run.
 *
 * Dispatched with a 10-minute delay so several emails that land close
 * together are read in one pass instead of one model call each;
 * ShouldBeUnique on the project keeps that batching even if more emails
 * arrive while this is already queued.
 *
 * Only worth running for a project the planner is actually showing the
 * chip and modal for — the same status codes the nightly run limits
 * itself to (CardsIndex::PLANNER_PROJECT_STATUS_CODES). Running it for a
 * completed or cancelled project would just spend OpenAI calls on action
 * items nobody will ever see.
 */
class ExtractProjectActionItems implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 600; // 10 minutes — matches the dispatch delay

    public function __construct(public int $projectId)
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return "extract_project_action_items_{$this->projectId}";
    }

    public function handle(ProjectActionItemExtractor $extractor): void
    {
        $project = Project::withoutGlobalScopes()->with('latestStatus')->find($this->projectId);

        if (! $project || ! in_array($project->latestStatus?->status_code, CardsIndex::PLANNER_PROJECT_STATUS_CODES, true)) {
            return;
        }

        $extractor->run($project, Carbon::parse($project->created_at)->startOfDay());
    }
}
