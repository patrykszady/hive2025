<?php

namespace App\Livewire\Clients;

use App\Models\Client;
use App\Models\User;

use App\Livewire\Forms\ClientForm;
use App\Services\GooglePlacesService;
use App\Traits\HandlesAddresses;

use Flux;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

class ClientCreate extends Component
{
    use AuthorizesRequests, HandlesAddresses;

    public ClientForm $form;

    public Client $client;

    public User $user;

    public $client_name = null;

    public $user_clients = [];

    public $user_client_id = null;

    public $view_text = [
        'card_title' => 'Add Client',
        'button_text' => 'Create Client',
        'form_submit' => 'save',
    ];

    public $team_member = false;

    protected $listeners = ['addUser', 'editClient', 'newClient'];
    protected $googlePlacesService;

    //boot instead of public function mount(GooglePlacesService $googlePlacesService)
    //so that protected $googlePlacesService can be initialized
    public function boot(GooglePlacesService $googlePlacesService)
    {
        $this->bootHandlesAddresses($googlePlacesService);
    }

    public function addUser(User $user, $client_id)
    {
        if (is_numeric($client_id)) {
            $this->client = Client::findOrFail($client_id);
            $this->user_client_id = $this->client->id;
            $this->client_name = $this->client->name;

            $this->view_text = [
                'card_title' => 'Add User to Client',
                'button_text' => 'Add User',
                'form_submit' => 'add_user_to_client',
            ];
        } else {
            $this->user_clients = $user->clients()->withoutGlobalScopes()->with('vendors')->get()->keyBy('id');
            $this->user_client_id = 'NEW';
        }

        $this->form->setUser($user);
        // If you have a UserForm instance, set it there too
        // $this->userForm->setUser($user); // if you have this

        $this->modal('client_form_modal')->show();
    }

    public function newClient()
    {
        $this->user_client_id = 'NEW';
    }

    public function editClient(Client $client)
    {
        $this->client = $client;
        $this->form->setClient($this->client);
        // Ensure user_client_id is set so form details render
        $this->user_client_id = $client->id;

        $this->view_text = [
            'card_title' => 'Update Client',
            'button_text' => 'Update',
            'form_submit' => 'edit',
        ];

        $this->modal('client_form_modal')->show();
    }

    public function edit()
    {
        $client = $this->form->update();

        $this->modal('client_form_modal')->close();

        $this->dispatch('notify',
            type: 'success',
            content: 'Client Updated',
            route: 'clients/'.$client->id
        );

        $this->dispatch('refreshComponent')->to('clients.clients-show');
    }

    public function add_user_to_client()
    {
        // Attach the user to the client
        if ($this->form->user && $this->client) {
            $this->client->users()->syncWithoutDetaching([$this->form->user->id]);

            $this->modal('client_form_modal')->close();

            $this->dispatch('notify',
                type: 'success',
                content: 'User added to Client'
            );

            $this->dispatch('refreshComponent')->to('clients.clients-show');
        }
    }

    public function save()
    {
        //if existing Client ... redirect to that with Livewire.navigate
        if (is_numeric($this->user_client_id)) {
            $this->modal('client_form_modal')->close();
            return $this->redirect('/clients/'.$this->user_client_id, navigate: true);
        }

        $this->modal('client_form_modal')->close();
        $this->dispatch('refreshComponent')->to('clients.clients-show');

        //new client
        if (! is_numeric($this->user_client_id)) {
            $client = $this->form->store();

            return $this->redirect('/clients/'.$client->id, navigate: true);
        } else {
            $auth_user_vendor = auth()->user()->vendor;
            $client = $this->user_clients[$this->user_client_id];
            $client_vendors = $client->vendors()->pluck('vendors.id')->toArray();

            $auth_vendor_in_client = in_array($auth_user_vendor->id, $client_vendors);

            if ($auth_vendor_in_client) {
                $this->dispatch('notify',
                    type: 'success',
                    content: 'This Client Exists',
                    route: 'clients/'.$client->id
                );
            } else {
                $auth_user_vendor->clients()->attach($client->id);
            }
        }

        $this->dispatch('refreshComponent')->to('clients.clients-index');
    }

    public function render()
    {
        return view('livewire.clients.form');
    }
}
