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

    #[Rule('required|min:3')]
    public $address = null;

    #[Rule('nullable|min:2')]
    public $address_2 = null;

    #[Rule('required|min:3')]
    public $city = null;

    #[Rule('required|min:2|max:2')]
    public $state = null;

    #[Rule('required|digits:5', as: 'zip code')]
    public $zip_code = null;

    #[Rule('required', as: 'Address')]
    public $project_existing_address = null;

    public function setProject(Project $project)
    {
        $this->project = $project;

        $this->client_id = $project->client_id;
        $this->project_name = $project->project_name;
        $this->project_existing_address = 'NEW';

        $this->address = $project->address;
        $this->address_2 = $project->address_2;
        $this->city = $project->city;
        $this->state = $project->state;
        $this->zip_code = $project->zip_code;
    }

    public function update()
    {
        $this->validate();

        $this->project->update([
            'project_name' => $this->project_name,
            // 'client_id' => $this->client_id,
            'address' => $this->address,
            'address_2' => $this->address_2,
            'city' => $this->city,
            'state' => $this->state,
            'zip_code' => $this->zip_code,
        ]);

        return $this->project;
    }

    public function store()
    {
        if ($this->project_existing_address == 'CLIENT_PROJECT') {
            $client_address = $this->component->client_addresses->first();
            $this->address = $client_address['address'];
            $this->address_2 = $client_address['address_2'];
            $this->city = $client_address['city'];
            $this->state = $client_address['state'];
            $this->zip_code = $client_address['zip_code'];
        }

        $this->validate();

        return Project::create([
            'project_name' => $this->project_name,
            'client_id' => $this->client_id,
            'address' => $this->address,
            'address_2' => $this->address_2,
            'city' => $this->city,
            'state' => $this->state,
            'zip_code' => $this->zip_code,
        ]);
    }
}
