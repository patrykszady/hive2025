<?php

namespace App\Livewire\ProjectStatus;

use App\Models\Project;
use App\Models\ProjectStatus;
use Flux;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class StatusCreate extends Component
{
    // use AuthorizesRequests;

    public Project $project;

    public $statuses = [];

    public $project_status = null;

    public $project_status_date = null;

    public function rules()
    {
        return [
            'project_status' => 'required',
        ];
    }

    public function mount(Project $project)
    {
        $this->project = $project;
        $this->project_status_date = today()->format('Y-m-d');
        $this->statuses = $this->project->statuses()->orderBy('start_date', 'ASC')->get();
    }

    public function update_project()
    {
        $this->validate();
        $status =
            ProjectStatus::create([
                'project_id' => $this->project->id,
                'belongs_to_vendor_id' => auth()->user()->vendor->id,
                'title' => $this->project_status,
                'start_date' => $this->project_status_date,
            ]);

        if ($this->project_status === 'Cancelled') {
            $this->project->estimates()->delete();
        }

        $this->project_status = null;
        $this->mount($this->project);
        $this->render();

        $this->dispatch('refreshComponent')->to('projects.project-show');
        $this->dispatch('refreshComponent')->to('estimates.estimates-index');

        Flux::toast(
            duration: 5000,
            position: 'top right',
            variant: 'success',
            heading: 'Status Update',
            // route / href / wire:click
            text: '',
        );
    }

    public function render()
    {
        return view('livewire.project-status.create');
    }
}
