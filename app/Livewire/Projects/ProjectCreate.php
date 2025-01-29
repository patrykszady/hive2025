<?php

namespace App\Livewire\Projects;

use App\Livewire\Forms\ProjectForm;
use App\Models\Client;
use App\Models\Project;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
// use Illuminate\Support\Facades\Validator;
use Livewire\Component;

class ProjectCreate extends Component
{
    use AuthorizesRequests;

    public ProjectForm $form;

    public Project $project;

    public $existing_client = null;

    public $client_addresses = [];

    public $view_text = [
        'card_title' => 'Create Project',
        'button_text' => 'Create',
        'form_submit' => 'save',
    ];

    protected $listeners = ['newProject', 'editProject'];

    public function updated($field, $value)
    {
        // dd($field, $value);
        $this->validateOnly($field);
        if ($field == 'form.client_id') {
            if ($value) {
                $this->resetAddress();
                $client = $this->clients->where('id', $value)->first();
                $this->client_addresses = $client->projects;

                if ($this->client_addresses->isEmpty()) {
                    $this->client_addresses =
                        collect([
                            collect([
                                // 'CLIENT_PROJECT' => 'CLIENT_PROJECT',
                                'address' => $client['address'],
                                'address_2' => $client['address_2'],
                                'city' => $client['city'],
                                'state' => $client['state'],
                                'zip_code' => $client['zip_code'],
                            ]),
                        ]);
                } else {
                    $this->client_addresses = $this->client_addresses->unique('address');
                }
            } else {
                $this->form->reset();
                $this->resetValidation();
            }
        }

        if ($field == 'form.project_existing_address') {
            if ($value && $value != 'NEW') {
                if ($value == 'CLIENT_PROJECT') {
                    $project_address = $this->client_addresses->first();
                } else {
                    $project_address = $this->client_addresses->where('id', $value)->first();
                }

                $this->form->address = $project_address['address'];
                $this->form->address_2 = $project_address['address_2'];
                $this->form->city = $project_address['city'];
                $this->form->state = $project_address['state'];
                $this->form->zip_code = $project_address['zip_code'];
            } elseif ($value == 'NEW') {
                $this->form->reset('address', 'address_2', 'city', 'zip_code', 'state');
                $this->resetValidation();
            } else {
                $this->resetAddress();
            }
        }
    }

    #[Computed]
    public function clients()
    {
        return Client::orderBy('created_at', 'DESC')->get();
    }

    public function resetAddress()
    {
        $this->form->reset('address', 'address_2', 'city', 'zip_code', 'state', 'project_existing_address');
        $this->resetValidation();
    }

    // public function resetModal()
    // {
    //     $this->form->reset();
    //     $this->resetValidation();
    // }

    public function newProject($client_id)
    {
        $this->existing_client = $this->clients->where('id', $client_id)->first();

        if (! $this->existing_client) {
            $this->form->client_id = null;
            $this->client_addresses =
                collect([
                    collect([
                        // 'CLIENT_PROJECT' => 'CLIENT_PROJECT',
                        'address' => null,
                        'address_2' => null,
                        'city' => null,
                        'state' => null,
                        'zip_code' => null,
                    ]),
                ]);
        } else {
            $this->form->client_id = $this->existing_client->id;
            $this->client_addresses = $this->existing_client->projects;

            if ($this->client_addresses->isEmpty()) {
                $this->client_addresses =
                    collect([
                        collect([
                            // 'CLIENT_PROJECT' => 'CLIENT_PROJECT',
                            'address' => $this->existing_client['address'],
                            'address_2' => $this->existing_client['address_2'],
                            'city' => $this->existing_client['city'],
                            'state' => $this->existing_client['state'],
                            'zip_code' => $this->existing_client['zip_code'],
                        ]),
                    ]);

                // $client_address = $this->client_addresses->first();

                $this->form->project_existing_address = 'CLIENT_PROJECT';
                // $this->form->address = $client_address->address;
                // $this->form->address_2 = $client_address->address_2;
                // $this->form->city = $client_address->city;
                // $this->form->state = $client_address->state;
                // $this->form->zip_code = $client_address->zip_code;
            } else {
                $this->client_addresses = $this->client_addresses->unique('address');
                $client_address = $this->client_addresses->first();

                $this->form->project_existing_address = $client_address->id;
                $this->form->address = $client_address->address;
                $this->form->address_2 = $client_address->address_2;
                $this->form->city = $client_address->city;
                $this->form->state = $client_address->state;
                $this->form->zip_code = $client_address->zip_code;
            }

            // dd($this->client_addresses->first());
        }

        $this->modal('project_form_modal')->show();
    }

    public function editProject(Project $project)
    {
        $this->project = $project;

        $this->form->setProject($this->project);
        $this->existing_client = $this->project->client;

        $this->view_text = [
            'card_title' => 'Update Project',
            'button_text' => 'Update',
            'form_submit' => 'edit',
        ];

        $this->modal('project_form_modal')->show();
    }

    public function save()
    {
        $project = $this->form->store();

        //9-1-2023 NOTIFICATIONS when we redirect with Livewire...
        return redirect(route('projects.show', $project->id));
    }

    public function edit()
    {
        $project = $this->form->update();

        $this->modal('project_form_modal')->close();

        $this->dispatch('notify',
            type: 'success',
            content: 'Project Updated',
            // route: 'clients/' . $client->id
        );

        $this->dispatch('refreshComponent')->to('projects.project-show');
    }

    public function render()
    {
        return view('livewire.projects.form');
    }
}
