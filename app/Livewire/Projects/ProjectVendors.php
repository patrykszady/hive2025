<?php

namespace App\Livewire\Projects;

use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\Vendor;
use Livewire\Component;

class ProjectVendors extends Component
{
    public $vendor_id;

    public Project $project;

    public $vendors = [];

    public $showModal = false;

    protected $listeners = ['addVendors'];

    public function rules()
    {
        return [
            'vendor_id' => 'required',
        ];
    }

    public function mount(Project $project)
    {
        $this->vendors = auth()->user()->vendor->vendors()->whereJsonContains('registration', ['registered' => true])->whereNotIn('vendors.id', $project->vendors->pluck('id')->toArray())->get();
    }

    public function addVendors()
    {
        $this->showModal = true;
    }

    public function save()
    {
        $vendor = Vendor::findOrFail($this->vendor_id);
        $client = Client::withoutGlobalScopes()->where('vendor_id', auth()->user()->vendor->id)->first();

        if (! $vendor->projects->contains($this->project->id)) {
            $vendor->projects()->attach($this->project->id, ['client_id' => $client->id]);

            $statusCode = ProjectStatus::getCodeForLabel('Invited') ?? 1;
            ProjectStatus::create([
                'project_id' => $this->project->id,
                'belongs_to_vendor_id' => $vendor->id,
                'status_code' => $statusCode,
                'start_date' => today()->format('Y-m-d'),
            ]);

            $this->dispatch('notify',
                type: 'success',
                content: 'Vendor invited to Project',
                // route: 'clients/' . $client->id
            );
        } else {
            $this->dispatch('notify',
                type: 'success',
                content: 'Vendor already part of Project',
                // route: 'clients/' . $client->id
            );
        }

        $this->showModal = false;
    }

    public function render()
    {
        // dd('here in livewire.projects.project-vendors');
        return view('livewire.projects.project-vendors');
    }
}
