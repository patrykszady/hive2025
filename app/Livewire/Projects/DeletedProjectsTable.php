<?php

namespace App\Livewire\Projects;

use App\Models\Project;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Deleted projects, with a way back.
 *
 * Projects soft delete (see ProjectForm::delete) — before that they were
 * destroyed outright and project 427 was lost for good. A trashed project is
 * invisible everywhere else in the app, including Meilisearch, so without this
 * card the only route back is tinker. Eloquent-backed on purpose: the main
 * projects table searches Meilisearch, which filters trashed rows out by
 * design (scout.soft_delete) and should keep doing so.
 *
 * Rendered eagerly, not lazily: the card is usually absent (nothing trashed),
 * and a lazy component whose placeholder paints nothing has no height to
 * trigger its own load — it would never appear. The query is one indexed
 * lookup on (belongs_to_vendor_id, deleted_at).
 */
class DeletedProjectsTable extends Component
{
    use AuthorizesRequests, WithPagination;

    protected string $pageName = 'deleted_page';

    /** Rows per page (also the skeleton's row ceiling). */
    public const PER_PAGE = 10;

    public static function placeholderRows(): int
    {
        return self::PER_PAGE;
    }

    /** One source of truth for the header and the loading skeleton. */
    public static function columnDefs(): array
    {
        return [
            ['label' => 'Project', 'width' => 'w-[40%] min-w-0', 'skeletonWidth' => 'w-40'],
            ['label' => 'Client', 'width' => 'w-[28%] min-w-0', 'skeletonWidth' => 'w-28'],
            ['label' => 'Deleted', 'width' => 'w-[20%]', 'skeletonWidth' => 'w-20'],
            ['label' => '', 'width' => 'w-[12%]', 'skeleton' => 'badge'],
        ];
    }

    #[Computed]
    public function deletedProjects()
    {
        return Project::onlyTrashed()
            ->where('belongs_to_vendor_id', auth()->user()->vendor->id)
            ->with('client.users:id,first_name,last_name,nickname')
            ->orderByDesc('deleted_at')
            ->paginate(self::PER_PAGE, ['*'], $this->pageName);
    }

    public function restoreProject(int $projectId): void
    {
        $project = Project::onlyTrashed()->findOrFail($projectId);

        // Same gate as deleting it: whoever may delete a project may undo that.
        $this->authorize('delete', $project);

        // restore() fires the observer, which brings back the estimates this
        // deletion cascaded (and leaves separately-disabled ones alone).
        $project->restore();

        unset($this->deletedProjects);

        \Flux\Flux::toast(
            variant: 'success',
            text: 'Project restored.',
            duration: 4000,
            position: 'top right',
        );

        // The projects table is Meilisearch-backed and this row just became
        // visible again — make it refetch rather than show a stale list.
        $this->dispatch('refreshComponent')->to(ProjectsTable::class);
    }

    public function render()
    {
        return view('livewire.projects.deleted-projects-table');
    }
}
