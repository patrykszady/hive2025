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
}
