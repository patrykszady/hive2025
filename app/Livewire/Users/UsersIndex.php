<?php

namespace App\Livewire\Users;

use App\Models\Client;
use App\Models\Vendor;
use Livewire\Component;

class UsersIndex extends Component
{
    public Client $client;

    public Vendor $vendor;

    public $users = [];

    public $view = null;

    public $registration = null;

    protected $listeners = ['refreshComponent' => '$refresh', 'refresh'];

    public $view_text = [
        'card_title' => 'Users',
        'button_text' => 'Add User',
    ];

    public function mount()
    {
        if ($this->view == 'clients.show') {
            $this->view_text['card_title'] = 'Client Members';
            $this->users = Client::findOrFail($this->client->id)->users;
        } elseif ($this->view == 'vendors.show') {
            $this->view_text['card_title'] = 'Team Members';
            $this->users = $this->vendor->users()->employed()->get();
        } else {
            dd($this);
        }
    }

    public function refresh()
    {
        $this->registration = false;
        $this->render();
    }

    public function add_user()
    {
        if ($this->view == 'clients.show') {
            $this->dispatch('newMember', model: 'client', model_id: $this->client->id);
        } elseif ($this->view == 'vendors.show') {
            $this->dispatch('newMember', model: 'vendor', model_id: $this->vendor->id);
        }
    }

    public function render()
    {
        return view('livewire.users.index');
    }
}
