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

    public ?User $user = null;

    public ?Client $client = null;

    #[Rule('nullable')]
    public $client_name = '';

    #[Rule('nullable|min:3')]
    public $business_name = null;

    #[Rule('nullable|min:1')]
    public $source = null;

    public function setUser(User $user): void
    {
        $this->user = $user;
        $this->client_name = $user->full_name;
    }

    public function setClient(Client $client): void
    {
        $this->client = $client;

        $this->component->address_1 = $client->address;
        $this->component->address_2 = $client->address_2;
        $this->component->city = $client->city;
        $this->component->state = $client->state;
        $this->component->zip_code = $client->zip_code;
        $this->source = $client->source;
        $this->client_name = $client->name;
    }

    public function update(): Client
    {
        $this->validate();

        $this->client->update([
            'business_name' => $this->business_name,
            'address' => $this->component->address_1,
            'address_2' => $this->component->address_2,
            'city' => $this->component->city,
            'state' => $this->component->state,
            'zip_code' => $this->component->zip_code,
        ]);

        $this->client->vendors()->updateExistingPivot(auth()->user()->vendor->id, ['source' => $this->source]);

        //ADD USER TO CLIENT
        // $this->user->clients()->attach($client->id);
        // //Add new Client to the logged-in-vendor
        // auth()->user()->vendor->clients()->attach($client->id);

        // $this->reset();
        return $this->client;
    }

    public function store(): Client
    {
        $this->validate();

        if (! $this->user) {
            $this->user = auth()->user();
        }

        $client = Client::create([
            'business_name' => $this->business_name,
            'address' => $this->component->address_1,
            'address_2' => $this->component->address_2,
            'city' => $this->component->city,
            'state' => $this->component->state,
            'zip_code' => $this->component->zip_code,
        ]);

        //ADD USER TO CLIENT
        $this->user->clients()->attach($client->id);
        
        //Add new Client to the logged-in-vendor
        //with pivot Source
        auth()->user()->vendor->clients()->attach($client->id);

        $client->vendors()->updateExistingPivot(auth()->user()->vendor->id, ['source' => $this->source]);

        // Sync to Nylas contacts (after vendor is attached)
        app(\App\Services\NylasContactSyncService::class)->syncUserContactsForClient($this->user, $client);

        $this->reset();

        return $client;
    }
}
