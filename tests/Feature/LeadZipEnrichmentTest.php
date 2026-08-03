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
        ->shouldReceive('geocodeAddress')
        ->once()
        ->with('104 N Plum Grove Rd, Palatine, IL')
        ->andReturn([
            'address' => '104 N Plum Grove Rd',
            'city' => 'Palatine',
            'state' => 'IL',
            'zip_code' => '60067',
        ]);

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

    // The real service refuses an unanchored street (it would otherwise
    // resolve "511 Sherwood Dr" to Rolla, Missouri); mock that refusal.
    $this->mock(GeoapifyService::class)->shouldReceive('geocodeAddress')->andReturn(null);

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

it('creates no client from a bare street address with nothing to anchor it', function () {
    $vendor = Vendor::factory()->create();

    // The refusal lives in the geocoder itself now: an unanchored street would
    // otherwise come back as Rolla, Missouri with full confidence.
    $this->mock(GeoapifyService::class)->shouldReceive('geocodeAddress')->andReturn(null);

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

    // The contact is whole, so it exists; the address isn't, so no client does.
    expect(User::where('email', 'bare.zip-test@example.com')->exists())->toBeTrue()
        ->and(Client::query()->where('address', '123 Main St')->exists())->toBeFalse();
});

it('provisions both people on a household lead into one client', function () {
    // Real lead: "Amy Dusto and Chris Ecker" / "443-333-0697 / 339-222-0798".
    // The two-number phone string used to fail normalizePhone() outright, so
    // provisioning bailed and the lead got no user and no client at all.
    $vendor = Vendor::factory()->create();

    $lead = Lead::create([
        'date' => now(),
        'origin' => 'Email',
        'belongs_to_vendor_id' => $vendor->id,
        'created_by_user_id' => makeLeadCreator()->id,
        'lead_data' => [
            'name' => 'Amy Dusto and Chris Ecker',
            'email' => 'amy.dusto.household@example.com',
            'cc_emails' => ['ecker.chris.r@example.com'],
            'phone' => '443-333-0697 / 339-222-0798',
            'address' => '5647 N Magnolia Ave',
            'city' => 'Chicago',
            'state' => 'IL',
            'zip' => '60660',
        ],
    ]);

    app(LeadContactProvisioner::class)->provision($lead);

    $lead->refresh();
    $primary = User::find($lead->user_id);

    // The lead belongs to whoever wrote in.
    expect($primary->first_name)->toBe('Amy')
        ->and($primary->last_name)->toBe('Dusto')
        ->and($primary->email)->toBe('amy.dusto.household@example.com')
        ->and($primary->cell_phone)->toBe('4433330697');

    $client = $primary->clients()->firstOrFail();
    $members = $client->users()->orderBy('users.id')->get();

    expect($members)->toHaveCount(2);

    $second = $members->firstWhere('first_name', 'Chris');
    expect($second)->not->toBeNull()
        ->and($second->last_name)->toBe('Ecker')
        // Paired with the second number, in the order they were written.
        ->and($second->cell_phone)->toBe('3392220798')
        // His own address, from the CC — contacts are only created whole.
        ->and($second->email)->toBe('ecker.chris.r@example.com')
        ->and($second->hasRoutableEmail())->toBeTrue();

    // The city arrives as its own field; without folding it in the client
    // landed with no city and the ZIP lookup never ran.
    expect($client->city)->toBe('Chicago')
        ->and((string) $client->zip_code)->toBe('60660');
});

it('shares a surname across a couple written as "Mark & Gail Brodson"', function () {
    $provisioner = app(LeadContactProvisioner::class);

    expect($provisioner->splitContacts('Mark & Gail Brodson'))
        ->toBe([['Mark', 'Brodson'], ['Gail', 'Brodson']])
        ->and($provisioner->splitContacts('Amy Dusto and Chris Ecker'))
        ->toBe([['Amy', 'Dusto'], ['Chris', 'Ecker']])
        ->and($provisioner->splitContacts('Zachary Wong'))
        ->toBe([['Zachary', 'Wong']]);
});

it('reads every phone number out of a shared phone field', function () {
    $provisioner = app(LeadContactProvisioner::class);

    expect($provisioner->normalizePhones('443-333-0697 / 339-222-0798'))
        ->toBe(['4433330697', '3392220798'])
        ->and($provisioner->normalizePhones('8478993292 and 6123879202'))
        ->toBe(['8478993292', '6123879202'])
        ->and($provisioner->normalizePhones('1-224-735-4200'))
        ->toBe(['2247354200'])
        ->and($provisioner->normalizePhones('not a phone'))
        ->toBe([]);
});

it('prefers a stated zip over geocoding the street', function () {
    // "5647 N Magnolia Ave, Chicago" geocodes to 60642; the email said 60660.
    // The geocoder is still consulted (the state is missing) — but what the
    // sender wrote down must survive it.
    $vendor = Vendor::factory()->create();

    $this->mock(GeoapifyService::class)
        ->shouldReceive('geocodeAddress')
        ->andReturn([
            'address' => '5647 N Magnolia Ave',
            'city' => 'Chicago',
            'state' => 'IL',
            'zip_code' => '60642',
        ]);

    $lead = Lead::create([
        'date' => now(),
        'origin' => 'Email',
        'belongs_to_vendor_id' => $vendor->id,
        'created_by_user_id' => makeLeadCreator()->id,
        'lead_data' => [
            'name' => 'Stated Zip',
            'email' => 'stated.zip@example.com',
            'phone' => '2245550199',
            'address' => '5647 N Magnolia Ave',
            'city' => 'Chicago',
            'state' => 'IL',
            'zip' => '60660',
        ],
    ]);

    app(LeadContactProvisioner::class)->provision($lead);

    expect((string) User::find($lead->fresh()->user_id)->clients()->firstOrFail()->zip_code)->toBe('60660');
});

it('creates no contact for an enquiry with no phone number', function () {
    // Real lead 142 (Rob Rothbaum): an email enquiry with an address but no
    // phone. A contact is only created when it's whole — name, email AND
    // phone — so this one waits for someone to add the number by hand.
    $vendor = Vendor::factory()->create();

    $this->mock(GeoapifyService::class)->shouldReceive('geocodeAddress')->andReturn(null);

    $lead = Lead::create([
        'date' => now(),
        'origin' => 'Email',
        'belongs_to_vendor_id' => $vendor->id,
        'created_by_user_id' => makeLeadCreator()->id,
        'lead_data' => [
            'name' => 'Rob Rothbaum',
            'email' => 'robert.rothbaum.nophone@example.com',
            'address' => '960 Danielson Ct',
            'city' => 'Gurnee',
        ],
    ]);

    app(LeadContactProvisioner::class)->provision($lead);

    expect($lead->fresh()->user_id)->toBeNull()
        ->and(User::where('last_name', 'Rothbaum')->exists())->toBeFalse()
        // …and no half-built client either.
        ->and(Client::where('address', '960 Danielson Ct')->exists())->toBeFalse();
});

it('creates nothing at all when contacts are incomplete', function () {
    $vendor = Vendor::factory()->create();
    $this->mock(GeoapifyService::class)->shouldReceive('geocodeAddress')->andReturn(null);

    foreach (['First Contact', 'Second Contact'] as $index => $name) {
        $lead = Lead::create([
            'date' => now(),
            'origin' => 'Email',
            'belongs_to_vendor_id' => $vendor->id,
            'created_by_user_id' => makeLeadCreator()->id,
            'lead_data' => [
                'name' => $name,
                'email' => 'nophone.'.$index.'@example.com',
                'address' => ($index + 100).' Separate St',
                'city' => 'Gurnee',
            ],
        ]);

        app(LeadContactProvisioner::class)->provision($lead);

        expect($lead->fresh()->user_id)->toBeNull();
    }

    expect(User::where('email', 'like', 'nophone.%')->exists())->toBeFalse();
});

it('gives the partner the address they were CC\'d from, not a placeholder', function () {
    // Real lead 143: Amy wrote in and CC'd Chris. His address was captured on
    // the ingest row and then dropped, so he got an invented placeholder.
    $vendor = Vendor::factory()->create();
    $this->mock(GeoapifyService::class)->shouldReceive('geocodeAddress')->andReturn(null);

    $lead = Lead::create([
        'date' => now(),
        'origin' => 'Email',
        'belongs_to_vendor_id' => $vendor->id,
        'created_by_user_id' => makeLeadCreator()->id,
        'lead_data' => [
            'name' => 'Amy Dusto and Chris Ecker',
            'email' => 'amy.dusto.cc@example.com',
            'cc_emails' => ['ecker.chris.r@example.com'],
            'phone' => '443-333-0697 / 339-222-0798',
            'address' => '5647 N Magnolia Ave',
            'city' => 'Chicago',
            'state' => 'IL',
            'zip' => '60660',
        ],
    ]);

    app(LeadContactProvisioner::class)->provision($lead);

    $client = User::find($lead->fresh()->user_id)->clients()->firstOrFail();
    $chris = $client->users()->where('first_name', 'Chris')->firstOrFail();

    expect($chris->email)->toBe('ecker.chris.r@example.com')
        ->and($chris->hasRoutableEmail())->toBeTrue()
        ->and($chris->cell_phone)->toBe('3392220798');
});

it('never hands a partner an address that is not theirs', function () {
    // A CC can be anyone — a realtor, an architect. Matching is by name, so an
    // unrelated address is left alone rather than filed under the partner.
    $vendor = Vendor::factory()->create();
    $this->mock(GeoapifyService::class)->shouldReceive('geocodeAddress')->andReturn(null);

    $lead = Lead::create([
        'date' => now(),
        'origin' => 'Email',
        'belongs_to_vendor_id' => $vendor->id,
        'created_by_user_id' => makeLeadCreator()->id,
        'lead_data' => [
            'name' => 'Amy Dusto and Chris Ecker',
            'email' => 'amy.stranger@example.com',
            'cc_emails' => ['realtor@bigrealty.example.com'],
            'phone' => '443-333-0697 / 339-222-0798',
            'address' => '77 Unrelated St',
            'city' => 'Chicago',
            'state' => 'IL',
            'zip' => '60614',
        ],
    ]);

    app(LeadContactProvisioner::class)->provision($lead);

    $client = User::find($lead->fresh()->user_id)->clients()->firstOrFail();

    // Nothing matched his name, so there is no address for him — and a contact
    // is never created half-built. The stranger's address goes nowhere.
    expect($client->users()->where('first_name', 'Chris')->exists())->toBeFalse()
        ->and(User::where('email', 'realtor@bigrealty.example.com')->exists())->toBeFalse();
});

it('adds the partner once their address arrives on a later enquiry', function () {
    $vendor = Vendor::factory()->create();
    $this->mock(GeoapifyService::class)->shouldReceive('geocodeAddress')->andReturn(null);

    $base = [
        'name' => 'Amy Dusto and Chris Ecker',
        'email' => 'amy.upgrade@example.com',
        'phone' => '443-333-0697 / 339-222-0798',
        'address' => '5647 N Magnolia Ave',
        'city' => 'Chicago',
        'state' => 'IL',
        'zip' => '60660',
    ];

    $first = Lead::create([
        'date' => now(),
        'origin' => 'Email',
        'belongs_to_vendor_id' => $vendor->id,
        'created_by_user_id' => makeLeadCreator()->id,
        'lead_data' => $base,
    ]);

    app(LeadContactProvisioner::class)->provision($first);

    $client = User::find($first->fresh()->user_id)->clients()->firstOrFail();

    // No address for Chris on the first enquiry — Amy only.
    expect($client->users()->count())->toBe(1);

    $second = Lead::create([
        'date' => now(),
        'origin' => 'Email',
        'belongs_to_vendor_id' => $vendor->id,
        'created_by_user_id' => makeLeadCreator()->id,
        'lead_data' => $base + ['cc_emails' => ['ecker.chris.r@example.com']],
    ]);

    app(LeadContactProvisioner::class)->provision($second);

    $chris = $client->fresh()->users()->where('first_name', 'Chris')->first();

    expect($chris)->not->toBeNull()
        ->and($chris->email)->toBe('ecker.chris.r@example.com')
        ->and($chris->cell_phone)->toBe('3392220798');
});
