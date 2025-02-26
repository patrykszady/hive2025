<?php

namespace App\Livewire\Clients;

use App\Livewire\Forms\ClientForm;
use App\Models\Client;
use App\Models\User;
use Flux;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

class ClientCreate extends Component
{
    use AuthorizesRequests;

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

    // public $address = NULL;
    // public $address_selection = NULL;
    // public $addresses = [];

    public $team_member = false;

    protected $listeners = ['addUser', 'resetModal', 'editClient', 'newClient'];

    public function rules()
    {
        return [
            // 'address' => 'nullable',
            // 'address_selection' => 'nullable',
            // 'user.full_name' => 'nullable',
            // 'client.name' => 'nullable',
        ];
    }

    public function updated($field, $value)
    {
        // if ($field === 'address'){
        //     $response = \GoogleMaps::load('geocoding')
        //         ->setParam(['address' => $value])
        //         ->get();

        //     $result = collect(json_decode($response))->toArray();

        //     if(!empty($result)){
        //         $this->addresses = $result['results'];
        //     }
        // }

        // if ($field === 'address_selection'){
        //     $address = collect($this->addresses[$value]->address_components);
        //     // Define the types you want to filter by
        //     $typesToFilter = ['street_number', 'route', 'locality', 'administrative_area_level_1', 'postal_code'];

        //     $address_array = [];

        //     $address->each(function ($item) use ($typesToFilter, &$address_array) {
        //         foreach ($item->types as $type) {
        //             if (in_array($type, $typesToFilter)) {
        //                 $address_array[$type] = $item->short_name;
        //             }
        //         }
        //     });

        //     $this->form->address = $address_array['street_number'] . ' ' . $address_array['route'];
        //     $this->form->city = $address_array['locality'];
        //     $this->form->state = $address_array['administrative_area_level_1'];
        //     $this->form->zip_code = $address_array['postal_code'];
        // }

        if ($this->user_client_id != 'NEW') {
            if (is_null($this->user_client_id)) {
                $this->view_text['button_text'] = 'Update Client';
            } else {
                $this->view_text['button_text'] = 'View Existing Client';
            }
        } else {
            $this->view_text['button_text'] = 'Create Client';
        }

        $this->validateOnly($field);
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
        // if(is_numeric($team_member)){
        //     $this->team_member = $team_member;

        //     $this->user = User::findOrFail($this->team_member);
        // }else{
        //     //role and hourly here for new vendor?
        //     $this->team_member = 'index';
        // }

        $this->modal('client_form_modal')->show();
    }

    public function newClient()
    {
        $this->user_client_id = 'NEW';

        // if(is_numeric($team_member)){
        //     $this->team_member = $team_member;

        //     $this->user = User::findOrFail($this->team_member);
        // }else{
        //     //role and hourly here for new vendor?
        //     $this->team_member = 'index';
        // }

        // $this->address = TRUE;
    }

    // // Everthing in top pulbic should be reset here
    // public function resetModal()
    // {

    //     // $this->client = Client::make();
    //     // $this->user = NULL;
    //     // $this->address = NULL;
    // }

    // public function add_user_to_client()
    // {
    //     //ADD USER TO CLIENT
    //     $this->form->user->clients()->attach($this->client->id);

    //     Flux::toast(
    //         duration: 5000,
    //         position: 'top right',
    //         variant: 'success',
    //         heading: 'User Added to Client.',
    //         // route / href / wire:click
    //         text: '',
    //     );

    //     $this->modal('client_form_modal')->close();

    //     $this->dispatch('refreshComponent')->to('clients.clients-show');
    //     $this->dispatch('refreshComponent')->to('users.users-index');
    // }

    public function editClient(Client $client)
    {
        // dd('in editClient');
        // $this->resetModal();

        $this->client = $client;

        // if(!$expense->splits->isEmpty()){
        //     $this->hasSplits($expense->splits);
        // }

        $this->form->setClient($this->client);

        $this->view_text = [
            'card_title' => 'Update Client',
            'button_text' => 'Update',
            'form_submit' => 'edit',
        ];
        // $this->form->setExpense($expense);

        // $this->view_text = [
        //     'card_title' => 'Update Expense',
        //     'button_text' => 'Update',
        //     'form_submit' => 'edit',
        // ];

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

    public function save()
    {
        // dd($this);
        //if existing Client ... redirect to that with Livewire.navigate
        if (is_numeric($this->user_client_id)) {
            $this->modal('client_form_modal')->close();

            return $this->redirect('/clients/'.$this->user_client_id, navigate: true);

        }
        //12-3-22 authorize
        if (! is_numeric($this->user_client_id)) {
            $client = $this->form->store();

            // $this->dispatch('notify',
            //     type: 'success',
            //     content: 'Client Created',
            //     route: 'clients/' . $client->id
            // );
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
                // $this->dispatch('notify',
                //     type: 'success',
                //     content: 'Client Added',
                //     route: 'clients/' . $client->id
                // );
            }
        }

        $this->modal('client_form_modal')->close();
        $this->dispatch('refreshComponent')->to('clients.clients-index');
    }

    public function render()
    {
        return view('livewire.clients.form');
    }
}
