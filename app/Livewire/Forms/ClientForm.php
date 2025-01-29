<?php

namespace App\Livewire\Forms;

use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Rule;
use Livewire\Form;

class ClientForm extends Form
{
    use AuthorizesRequests;

    public ?User $user;

    public ?Client $client;

    #[Rule('nullable')]
    public $client_name = '';

    #[Rule('nullable|min:3')]
    public $business_name = null;

    #[Rule('required|min:3')]
    public $address = null;

    #[Rule('nullable|min:1')]
    public $address_2 = null;

    #[Rule('required|min:3')]
    public $city = null;

    #[Rule('required|min:2|max:2')]
    public $state = null;

    #[Rule('required|digits:5', as: 'zip code')]
    public $zip_code = null;

    #[Rule('nullable|min:1')]
    public $source = null;

    public function setUser(User $user)
    {
        $this->user = $user;
        $this->client_name = $user->full_name;
    }

    public function setClient(Client $client)
    {
        $this->client = $client;

        $this->address = $client->address;
        $this->address_2 = $client->address_2;
        $this->city = $client->city;
        $this->state = $client->state;
        $this->zip_code = $client->zip_code;
        $this->source = $client->source;
        $this->client_name = $client->name;
    }

    public function update()
    {
        $this->validate();

        $this->client->update([
            'business_name' => $this->business_name,
            'address' => $this->address,
            'address_2' => $this->address_2,
            'city' => $this->city,
            'state' => $this->state,
            'zip_code' => $this->zip_code,
        ]);

        $this->client->vendors()->updateExistingPivot(auth()->user()->vendor->id, ['source' => $this->source]);

        //ADD USER TO CLIENT
        // $this->user->clients()->attach($client->id);
        // //Add new Client to the logged-in-vendor
        // auth()->user()->vendor->clients()->attach($client->id);

        // $this->reset();
        return $this->client;
    }

    public function store()
    {
        $this->validate();

        $client = Client::create([
            'business_name' => $this->business_name,
            'address' => $this->address,
            'address_2' => $this->address_2,
            'city' => $this->city,
            'state' => $this->state,
            'zip_code' => $this->zip_code,
        ]);

        //ADD USER TO CLIENT
        $this->user->clients()->attach($client->id);
        //Add new Client to the logged-in-vendor
        //with pivot Source
        auth()->user()->vendor->clients()->attach($client->id);

        $client->vendors()->updateExistingPivot(auth()->user()->vendor->id, ['source' => $this->source]);

        $this->reset();

        return $client;
    }
}
