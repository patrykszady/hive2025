<?php

namespace App\Livewire\Projects;

use App\Models\Project;
use App\Models\ProjectTimelapseFrame;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * The project page's Images card: frame count, when the last shot landed, and
 * the way into the camera. Kept deliberately small — browsing and shooting
 * happen on the images page.
 */
class ProjectTimelapseCard extends Component
{
    public Project $project;

    /**
     * The newest shot across every collection — newest by id, not sort_order,
     * since sort_order only ranks WITHIN a timelapse and "the last photo on
     * this job" means most recently taken, whatever album.
     */
    #[Computed]
    public function latestFrame(): ?ProjectTimelapseFrame
    {
        return ProjectTimelapseFrame::query()
            ->whereHas('timelapse', fn ($q) => $q->where('project_id', $this->project->id))
            ->with('takenBy:id,first_name,nickname')
            ->orderByDesc('id')
            ->first();
    }

    #[Computed]
    public function frameCount(): int
    {
        return ProjectTimelapseFrame::query()
            ->whereHas('timelapse', fn ($q) => $q->where('project_id', $this->project->id))
            ->count();
    }

    public function render()
    {
        return view('livewire.projects.project-timelapse-card');
    }

    /**
     * Same-chrome skeleton; the COUNT is far cheaper than the card it stands
     * in for, and an empty card never flashes fake content.
     */
    public function placeholder(array $params = []): \Illuminate\Contracts\View\View
    {
        $projectId = $params['project'] instanceof Project
            ? $params['project']->id
            : (int) ($params['project'] ?? 0);

        $hasFrames = ProjectTimelapseFrame::query()
            ->whereHas('timelapse', fn ($q) => $q->where('project_id', $projectId))
            ->exists();

        return view('livewire.projects.project-timelapse-card-placeholder', [
            'hasFrames' => $hasFrames,
        ]);
    }
}
