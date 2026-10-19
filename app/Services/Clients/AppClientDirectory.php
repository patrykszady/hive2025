<?php

namespace App\Services\Clients;

use App\Models\Client;
use App\Models\User;
use App\Services\NylasContactSyncService;
use App\Services\People\AppPersonResolver;
use App\Services\SmsThreadLinker;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The app's "New Client" / "Edit Client" / "Add Client Person" flows —
 * reuses the exact rules App\Livewire\Clients\ClientCreate,
 * App\Livewire\Forms\ClientForm and the client branch of
 * App\Livewire\Users\UserCreate apply on the web, so a client (and the
 * people on it) created or edited from the app is identical to one made
 * there.
 */
class AppClientDirectory
{
    public function __construct(
        private AppPersonResolver $people,
        private NylasContactSyncService $nylas,
        private SmsThreadLinker $threadLinker,
    ) {
    }

    /** The person already on file for this phone number, if any — App\Livewire\Users\UserCreate::user_cell_find(). */
    public function findPersonByPhone(string $cellPhone): ?User
    {
        return $this->people->findByPhone($cellPhone);
    }

    /**
     * The clients $person already belongs to that this vendor can see —
     * Client's own ClientScope narrows this to OUR clients automatically,
     * same as the "Existing Clients" picker
     * App\Livewire\Clients\ClientCreate::addUser() builds before letting an
     * admin create a duplicate record for someone we already serve.
     *
     * @return Collection<int, Client>
     */
    public function existingClientsFor(User $person): Collection
    {
        return $person->clients()->get();
    }

    /**
     * App\Livewire\Forms\ClientForm::store() — create the client, attach
     * $person (found-or-created via AppPersonResolver) and the signed-in
     * vendor, record the referral source, sync Nylas contacts, and backfill
     * any unmatched inbound SMS threads.
     *
     * @param  array{cell_phone: string, first_name?: ?string, last_name?: ?string, email?: ?string, nickname?: ?string, preferred_language?: ?string, business_name?: ?string, address?: ?string, address_2?: ?string, city?: ?string, state?: ?string, zip_code?: ?string, source?: ?string}  $data
     */
    public function createClient(User $actingUser, array $data): Client
    {
        $person = $this->people->resolveOrCreate($data);

        $client = Client::create([
            'business_name' => $data['business_name'] ?? null,
            'address' => $data['address'] ?? null,
            'address_2' => $data['address_2'] ?? null,
            'city' => $data['city'] ?? null,
            'state' => $data['state'] ?? null,
            'zip_code' => $data['zip_code'] ?? null,
        ]);

        $person->clients()->attach($client->id);
        $actingUser->vendor->clients()->attach($client->id);
        $client->vendors()->updateExistingPivot($actingUser->vendor->id, ['source' => $data['source'] ?? null]);

        $this->nylas->syncUserContactsForClient($person, $client);
        $this->threadLinker->linkThreadsForClient($client->refresh());

        return $client;
    }

    /**
     * App\Livewire\Forms\ClientForm::update() — business details, address,
     * and this vendor's own referral-source note on the client.
     *
     * @param  array{business_name?: ?string, address?: ?string, address_2?: ?string, city?: ?string, state?: ?string, zip_code?: ?string, source?: ?string}  $data
     */
    public function updateClient(Client $client, User $actingUser, array $data): Client
    {
        $client->update([
            'business_name' => $data['business_name'] ?? null,
            'address' => $data['address'] ?? null,
            'address_2' => $data['address_2'] ?? null,
            'city' => $data['city'] ?? null,
            'state' => $data['state'] ?? null,
            'zip_code' => $data['zip_code'] ?? null,
        ]);

        $client->vendors()->updateExistingPivot($actingUser->vendor->id, ['source' => $data['source'] ?? null]);

        return $client;
    }

    /**
     * Add a person to an existing client — the client branch of
     * App\Livewire\Users\UserCreate::save() (model.type === 'client', a
     * real client id, not NEW). A phone match already on this client is
     * refused exactly like user_cell_find's inline error.
     *
     * @param  array{cell_phone: string, first_name?: ?string, last_name?: ?string, email?: ?string, nickname?: ?string, preferred_language?: ?string}  $data
     */
    public function addPerson(Client $client, array $data): User
    {
        $existingByPhone = $this->people->findByPhone($data['cell_phone']);

        if ($existingByPhone && $client->users()->where('user_id', $existingByPhone->id)->exists()) {
            throw ValidationException::withMessages([
                'cell_phone' => $existingByPhone->first_name.' already belongs to this client.',
            ]);
        }

        $person = $this->people->resolveOrCreate($data);

        if (! $person->clients()->where('client_id', $client->id)->exists()) {
            $person->clients()->attach($client->id);
            $this->nylas->syncUserContactsForClient($person, $client);
        }

        return $person;
    }
}
