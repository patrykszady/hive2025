<?php

namespace App\Livewire\Estimates;

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
    use AuthorizesRequests, WithPagination;

    public $view = 'estimates.index';

    public Project $project;

    protected $listeners = ['refreshComponent' => '$refresh', 'disableEstimate'];

    #[Computed]
    public function estimates()
    {
        $project_id = $this->project?->id;

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

        if ($this->view === 'estimates.show') {
            $this->dispatch('navigate', route('projects.show', ['project' => $estimate->project->id]));
        }

        Flux::toast(
            duration: 5000,
            position: 'top right',
            variant: 'success',
            heading: 'Estimate Disabled',
            // route / href / wire:click
            text: '',
        );
    }

    public function removeEstimate($estimate_id)
    {
        $estimate = Estimate::withTrashed()->with(['estimate_sections', 'estimate_line_items'])->findOrFail($estimate_id);

        $estimate->estimate_line_items()->forceDelete();
        $estimate->estimate_sections()->forceDelete();

        $estimate->forceDelete();

        Flux::toast(
            duration: 5000,
            position: 'top right',
            variant: 'success',
            heading: 'Estimate Deleted',
            text: '',
        );
    }

    public function activateEstimate($estimate_id)
    {
        $estimate = Estimate::withTrashed()->findOrFail($estimate_id);

        // $this->estimate = $estimate;
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
        // $this->authorize('viewAny', Project::class);
        return view('livewire.estimates.index');
    }
}
