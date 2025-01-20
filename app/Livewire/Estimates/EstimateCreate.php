<?php

namespace App\Livewire\Estimates;

use App\Models\Estimate;
use App\Models\Project;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class EstimateCreate extends Component
{
    use AuthorizesRequests;

    public Project $project;

    public function mount()
    {
        //authorize, make sure logged in vendor can create estimates for this project.
        //user can create estiamte for this Project
        // $this->authorize('create', Estimate::class, $this->project);

        //create new estimate and send to estimates.show view
        $estimate = Estimate::create([
            'project_id' => $this->project->id,
            'belongs_to_vendor_id' => auth()->user()->vendor->id,
        ]);

        // $this->dispatch('mount', estimate: $estimate)->to(EstimateShow::class);
        return redirect(route('estimates.show', $estimate->id));
    }

    // public function render()
    // {
    //     // dd('in rendor of estimate_form');
    //     return view('livewire.estimates.form');
    // }
}
