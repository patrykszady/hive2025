<?php

use App\Models\Client;
use App\Models\Lead;
use App\Models\User;
use App\Models\Vendor;
use App\Services\GeoapifyService;
use App\Services\LeadContactProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeLeadCreator(): User
{
    return User::query()->create([
        'first_name' => 'Lead',
        'last_name' => 'Creator',
        'email' => 'lead.creator.' . uniqid() . '@example.com',
        'cell_phone' => fake()->unique()->numerify('224666####'),
    ]);
}

it('geocodes a missing zip code when provisioning a client from a lead', function () {
    $vendor = Vendor::factory()->create();

    $this->mock(GeoapifyService::class)
        ->shouldReceive('lookupZipCode')
        ->once()
        ->with('104 N Plum Grove Rd, Palatine, IL, USA')
        ->andReturn('60067');

    $lead = Lead::create([
        'date' => now(),
        'origin' => 'gs.construction',
        'belongs_to_vendor_id' => $vendor->id,
        'created_by_user_id' => makeLeadCreator()->id,
        'lead_data' => [
            'name' => 'Jane Homeowner',
            'email' => 'jane.zip-test@example.com',
            'phone' => '2245550142',
            'address' => '104 N Plum Grove Rd, Palatine, IL, USA',
        ],
    ]);

    app(LeadContactProvisioner::class)->provision($lead);

    $client = Client::query()->where('address', '104 N Plum Grove Rd')->first();

    expect($client)->not->toBeNull()
        ->and($client->city)->toBe('Palatine')
        ->and($client->state)->toBe('IL')
        ->and((string) $client->zip_code)->toBe('60067');
});

it('keeps the parsed zip and skips geocoding when the lead address already has one', function () {
    $vendor = Vendor::factory()->create();

    $this->mock(GeoapifyService::class)
        ->shouldReceive('lookupZipCode')
        ->never();

    $lead = Lead::create([
        'date' => now(),
        'origin' => 'gs.construction',
        'belongs_to_vendor_id' => $vendor->id,
        'created_by_user_id' => makeLeadCreator()->id,
        'lead_data' => [
            'name' => 'John Homeowner',
            'email' => 'john.zip-test@example.com',
            'phone' => '2245550143',
            'address' => '5 Oak St, Barrington, IL 60010, USA',
        ],
    ]);

    app(LeadContactProvisioner::class)->provision($lead);

    $client = Client::query()->where('address', '5 Oak St')->first();

    expect($client)->not->toBeNull()
        ->and((string) $client->zip_code)->toBe('60010');
});

it('does not geocode a bare street address with nothing to anchor it', function () {
    $vendor = Vendor::factory()->create();

    $this->mock(GeoapifyService::class)
        ->shouldReceive('lookupZipCode')
        ->never();

    $lead = Lead::create([
        'date' => now(),
        'origin' => 'gs.construction',
        'belongs_to_vendor_id' => $vendor->id,
        'created_by_user_id' => makeLeadCreator()->id,
        'lead_data' => [
            'name' => 'Bare Street',
            'email' => 'bare.zip-test@example.com',
            'phone' => '2245550144',
            'address' => '123 Main St',
        ],
    ]);

    app(LeadContactProvisioner::class)->provision($lead);

    expect(Client::query()->where('address', '123 Main St')->first()?->zip_code)->toBeNull();
});
