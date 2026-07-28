<?php

namespace App\Livewire\Estimates;

use App\Livewire\Concerns\HasToJsonMethod;
use App\Models\Estimate;
use App\Models\Project;

use Flux;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

class EstimatesIndex extends Component
{
    use AuthorizesRequests, WithPagination, HasToJsonMethod;

    public $view = 'estimates.index';

    public Project $project;

    protected $listeners = ['refreshComponent' => '$refresh', 'disableEstimate'];

    /**
     * How many skeleton rows the loading placeholder should paint — the card's
     * page size, so the skeleton is the same height as the table that replaces
     * it (no jump on load). Callers that can cheaply COUNT the real rows pass
     * the smaller of the two.
     */
    public static function placeholderRows(): int
    {
        return 10;
    }

    /**
     * Column defs for the estimates table — the loading skeleton renders from
     * the same array as the real header row, so widths can never drift apart.
     * Columns vary by view (the embedded card drops Client and is far narrower
     * than the index), so the caller passes the same $view the table uses.
     *
     * @return array<int, array{label: string, width: string, skeleton?: string, skeletonWidth?: string}>
     */
    public static function columnDefs(?string $view = 'estimates.index'): array
    {
        // Embedded on projects.show: two auto-width cells (amount + status
        // badge, then date) and no Client column.
        if ($view !== null && $view !== 'estimates.index') {
            return [
                ['label' => 'Estimate', 'width' => 'w-1/2 min-w-0', 'skeletonWidth' => 'w-20'],
                ['label' => 'Date', 'width' => 'w-1/2 min-w-0', 'skeletonWidth' => 'w-16'],
            ];
        }

        return [
            ['label' => 'Estimate', 'width' => 'w-[40%] min-w-0', 'skeletonWidth' => 'w-24'],
            ['label' => 'Date', 'width' => 'w-[25%]', 'skeletonWidth' => 'w-16'],
            ['label' => 'Client', 'width' => 'w-[35%] min-w-0', 'skeletonWidth' => 'w-28'],
        ];
    }

    #[Computed]
    public function estimates()
    {
        // Standalone /estimates mounts without a project, leaving the typed
        // property uninitialized — `?->` still fatals on that, so guard it.
        $project_id = isset($this->project) ? $this->project->id : null;

        return Estimate::withTrashed()
            ->when($project_id, function ($query) use ($project_id) {
                $query->where('project_id', $project_id);
            })
            ->orderBy('deleted_at') // Active first
            ->orderByDesc('created_at') // Latest created_at next
            ->paginate(10);
    }

    public function disableEstimate(Estimate $estimate)
    {
        $estimate->delete();

        Flux::toast(
            duration: 5000,
            position: 'top right',
            variant: 'success',
            heading: 'Estimate Disabled',
            text: '',
        );
    }

    public function removeEstimate($estimate_id)
    {
        $estimate = Estimate::withTrashed()->findOrFail($estimate_id);
        
        // If already soft deleted (disabled), force delete permanently
        if ($estimate->trashed()) {
            $estimate->estimate_line_items()->each(function ($lineItem) {
                $lineItem->allowances()->delete();
                $lineItem->forceDelete();
            });
            $estimate->estimate_sections()->forceDelete();
            $estimate->forceDelete();
            $message = 'Estimate permanently deleted';
        } else {
            // Otherwise, soft delete (disable)
            $estimate->delete();
            $message = 'Estimate disabled';
        }

        Flux::toast(
            duration: 5000,
            position: 'top right',
            variant: 'success',
            heading: $message,
            text: '',
        );
    }

    public function activateEstimate($estimate_id)
    {
        $estimate = Estimate::withTrashed()->findOrFail($estimate_id);
        $estimate->restore();

        Flux::toast(
            duration: 5000,
            position: 'top right',
            variant: 'success',
            heading: 'Estimate Restored',
            // route / href / wire:click
            text: '',
        );
    }

    #[Title('Estimates')]
    public function render()
    {
        $this->authorize('viewAny', Estimate::class);
        return view('livewire.estimates.index');
    }

    /**
     * @param  array<string, mixed>  $params  The lazy component's mount params —
     *   Livewire passes the whole array positionally, never named arguments.
     */
    public function placeholder(array $params = [])
    {
        // placeholder() runs before mount(), so $this->view is still the
        // default here — the embedded projects.show card only announces itself
        // through the mount params. The view data key can't be 'view': Livewire
        // merges the component's public properties into the placeholder view
        // afterwards and would clobber it.
        $project = $params['project'] ?? null;
        $projectId = $project instanceof \App\Models\Project
            ? $project->id
            : (is_numeric($project) ? (int) $project : null);

        // A COUNT is far cheaper than the paginated query the skeleton stands
        // in for, so the card shimmers exactly as many rows as will arrive —
        // and none at all when the project has no estimates (the loaded card
        // is header-only there).
        $estimates = $projectId
            ? \App\Models\Estimate::withTrashed()
                ->where('project_id', $projectId)
                ->orderBy('deleted_at')
                ->orderByDesc('created_at')
                ->limit(static::placeholderRows())
                ->get(['id', 'deleted_at'])
            : collect();

        // The card shows ACTIVE estimates only — the rest collapse behind the
        // header toggle — so the skeleton must count the VISIBLE rows, not all
        // of them. (With nothing active the card falls back to showing them
        // all, and so does this.)
        $visible = $estimates->reject(fn ($e) => $e->deleted_at !== null);

        if ($visible->isEmpty()) {
            $visible = $estimates;
        }

        // Measured against the loaded card: an active estimate's cell is a
        // money link + badge (44px row); a trashed one adds an actions
        // dropdown (50px). Cheap to know, so the skeleton matches row for row.
        $rowHeights = $visible->map(fn ($e) => $e->deleted_at ? 50 : 44)->all();

        return view('livewire.estimates.estimates-index-placeholder', [
            'tableView' => $params['view'] ?? $this->view,
            'rows' => $projectId ? count($rowHeights) : static::placeholderRows(),
            'rowHeights' => $projectId ? $rowHeights : null,
        ]);
    }
}
