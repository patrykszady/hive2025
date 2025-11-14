<?php

namespace App\Livewire\Forms;

use App\Models\Project;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Rule;
use Livewire\Form;

class ProjectForm extends Form
{
    use AuthorizesRequests;

    public ?Project $project;

    #[Rule('required', as: 'Client')]
    public $client_id = null;

    #[Rule('required|min:3', as: 'Project Name')]
    public $project_name = null;

    #[Rule('required', as: 'Address')]
    public $project_existing_address = null;

    public function setProject(Project $project)
    {
        $this->project = $project;

        $this->client_id = $project->client_id;
        $this->project_name = $project->project_name;
        $this->project_existing_address = 'NEW';

        $this->component->address_1 = $project->address;
        $this->component->address_2 = $project->address_2;
        $this->component->city = $project->city;
        $this->component->state = $project->state;
        $this->component->zip_code = $project->zip_code;
    }

    public function update()
    {
        $this->validate();

        $oldClientId = $this->project->client_id;
        $newClientId = $this->client_id;

        $this->project->update([
            'project_name' => $this->project_name,
            'client_id' => $this->client_id,
            'address' => $this->component->address_1,
            'address_2' => $this->component->address_2,
            'city' => $this->component->city,
            'state' => $this->component->state,
            'zip_code' => $this->component->zip_code,
        ]);

        // If client changed, update the project_vendor pivot table
        if ($oldClientId != $newClientId) {
            \DB::table('project_vendor')
                ->where('project_id', $this->project->id)
                ->update(['client_id' => $newClientId]);
        }

        return $this->project;
    }

    public function store()
    {
        if ($this->project_existing_address == 'CLIENT_PROJECT') {
            $client_address = $this->component->client_addresses->first();
            $this->component->address_1 = $client_address['address'];
            $this->component->address_2 = $client_address['address_2'];
            $this->component->city = $client_address['city'];
            $this->component->state = $client_address['state'];
            $this->component->zip_code = $client_address['zip_code'];
        } elseif(numeric($this->project_existing_address)){
            $existing_project = Project::findOrFail($this->project_existing_address);
            $this->component->address_1 = $existing_project->address;
            $this->component->address_2 = $existing_project->address_2;
            $this->component->city = $existing_project->city;
            $this->component->state = $existing_project->state;
            $this->component->zip_code = $existing_project->zip_code;
        }

        $this->validate();

        return Project::create([
            'project_name' => $this->project_name,
            'client_id' => $this->client_id,
            'address' => $this->component->address_1,
            'address_2' => $this->component->address_2,
            'city' => $this->component->city,
            'state' => $this->component->state,
            'zip_code' => $this->component->zip_code,
        ]);
        // ProjectStatus with Estimate (code 2) is created automatically via ProjectObserver
    }
}
