<?php

namespace App\Livewire\Estimates;

use App\Models\Estimate;
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

    public $project = null;

    protected $listeners = ['refreshComponent' => '$refresh'];

    #[Computed]
    public function estimates()
    {
        $project_id = $this->project ? $this->project->id : null;

        $estimates = Estimate::withTrashed()
            ->when($this->project != null, function ($query) use ($project_id) {
                //order by date Active first, then removed (seperate)
                return $query->where('project_id', $project_id)->orderBy('deleted_at', 'ASC');
            })
            ->orderBy('created_at', 'DESC')
            ->paginate(10);

        $estimates->getCollection()->each(function ($estimate, $key) {
            if (is_null($estimate->deleted_at)) {
                $estimate->status = 'Active';
            } else {
                $estimate->status = 'Removed';
            }
        });

        return $estimates;
    }

    //also on EstimateShow
    public function deleteEstimate(Estimate $estimate)
    {
        // $this->estimate = $estimate;
        $estimate->delete();

        Flux::toast(
            duration: 5000,
            position: 'top right',
            variant: 'success',
            heading: 'Estimate Removed',
            // route / href / wire:click
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
