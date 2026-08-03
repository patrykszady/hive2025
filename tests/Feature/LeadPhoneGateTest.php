<?php

use App\Livewire\Leads\LeadCreate;
use App\Models\Client;
use App\Models\CompanyEmail;
use App\Models\Lead;
use App\Models\User;
use App\Models\Vendor;
use App\Services\GeoapifyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * An emailed enquiry can arrive without a phone number. We never invent one,
 * so the contact stays incomplete — and the Message tab stays shut — until
 * someone types the number in.
 */
function phonelessLeadFixture(): array
{
    $vendor = Vendor::factory()->create(['options' => (object) ['short_name' => 'GS']]);

    $admin = new User();
    $admin->forceFill([
        'first_name' => 'Patryk',
        'last_name' => 'Sender',
        'email' => 'phone-gate-admin.'.uniqid().'@example.test',
        'cell_phone' => fake()->unique()->numerify('224555####'),
        'primary_vendor_id' => $vendor->id,
    ]);
    $admin->save();
    $vendor->users()->attach($admin->id, ['role_id' => 1]);
    CompanyEmail::create(['vendor_id' => $vendor->id, 'email' => $admin->email, 'grant_id' => '']);

    $contact = User::query()->create([
        'first_name' => 'Rob',
        'last_name' => 'Rothbaum',
        'email' => 'rob.phone-gate.'.uniqid().'@example.com',
        'cell_phone' => null,
    ]);

    $client = Client::factory()->create();
    $client->vendors()->attach($vendor->id);
    $client->users()->attach($contact->id);

    $lead = Lead::create([
        'date' => now(),
        'origin' => 'Email',
        'user_id' => $contact->id,
        'belongs_to_vendor_id' => $vendor->id,
        'created_by_user_id' => $contact->id,
        'lead_data' => [
            'name' => 'Rob Rothbaum',
            'email' => $contact->email,
            'address' => '960 Danielson Ct',
            'city' => 'Gurnee',
            'message' => 'Basement renovation',
        ],
    ]);
    $lead->statuses()->create(['title' => 'New', 'belongs_to_vendor_id' => $vendor->id]);

    return ['admin' => $admin, 'contact' => $contact, 'lead' => $lead];
}

it('hides the Message tab until a phone number is on file', function () {
    $fx = phonelessLeadFixture();

    $component = Livewire::actingAs($fx['admin'])
        ->test(LeadCreate::class)
        ->call('editLead', $fx['lead']);

    expect($component->instance()->needsPhone)->toBeTrue();

    $component->assertDontSee('name="messages"', false)
        ->assertSee("This enquiry didn't include a phone number", false);
});

it('saves a hand-entered phone onto the lead and the contact, then opens the tab', function () {
    $fx = phonelessLeadFixture();

    $component = Livewire::actingAs($fx['admin'])
        ->test(LeadCreate::class)
        ->call('editLead', $fx['lead'])
        ->set('phoneEntry', '(847) 555-0134')
        ->call('saveContactPhone')
        ->assertHasNoErrors();

    expect($fx['contact']->fresh()->cell_phone)->toBe('8475550134')
        ->and($fx['lead']->fresh()->lead_data['phone'])->toBe('8475550134')
        ->and($component->instance()->needsPhone)->toBeFalse();

    $component->assertSee('name="messages"', false);
});

it('refuses a phone number that is not ten digits', function () {
    $fx = phonelessLeadFixture();

    Livewire::actingAs($fx['admin'])
        ->test(LeadCreate::class)
        ->call('editLead', $fx['lead'])
        ->set('phoneEntry', '555-0134')
        ->call('saveContactPhone')
        ->assertHasErrors('phoneEntry');

    expect($fx['contact']->fresh()->cell_phone)->toBeNull();
});

it('refuses a phone number another contact already uses', function () {
    $fx = phonelessLeadFixture();

    User::query()->create([
        'first_name' => 'Someone',
        'last_name' => 'Else',
        'email' => 'taken.'.uniqid().'@example.com',
        'cell_phone' => '8475550134',
    ]);

    Livewire::actingAs($fx['admin'])
        ->test(LeadCreate::class)
        ->call('editLead', $fx['lead'])
        ->set('phoneEntry', '8475550134')
        ->call('saveContactPhone')
        ->assertHasErrors('phoneEntry');

    expect($fx['contact']->fresh()->cell_phone)->toBeNull();
});

it('shows the new number on the client card straight away', function () {
    $fx = phonelessLeadFixture();

    // Give the contact a client, so the card is what renders the phone.
    $client = Client::factory()->create(['address' => '960 Danielson Ct', 'city' => 'Gurnee']);
    $client->users()->attach($fx['contact']->id);

    Livewire::actingAs($fx['admin'])
        ->test(LeadCreate::class)
        ->call('editLead', $fx['lead'])
        ->assertDontSee('(847) 555-0134')
        ->set('phoneEntry', '8475550134')
        ->call('saveContactPhone')
        ->assertHasNoErrors()
        // Rendered from $client->users — stale unless the save happens first.
        ->assertSee('(847) 555-0134');
});

it('creates the contact and client when the phone is the missing piece', function () {
    // An enquiry with no phone AND no usable email provisions nothing at all;
    // typing the number in is what makes a contact possible.
    $vendor = Vendor::factory()->create(['options' => (object) ['short_name' => 'GS']]);

    $admin = new User();
    $admin->forceFill([
        'first_name' => 'Patryk',
        'last_name' => 'Sender',
        'email' => 'phone-gate-admin2.'.uniqid().'@example.test',
        'cell_phone' => fake()->unique()->numerify('224555####'),
        'primary_vendor_id' => $vendor->id,
    ]);
    $admin->save();
    $vendor->users()->attach($admin->id, ['role_id' => 1]);
    CompanyEmail::create(['vendor_id' => $vendor->id, 'email' => $admin->email, 'grant_id' => '']);

    $lead = Lead::create([
        'date' => now(),
        'origin' => 'Email',
        'belongs_to_vendor_id' => $vendor->id,
        'created_by_user_id' => $admin->id,
        'lead_data' => [
            'name' => 'Nora Newcontact',
            'email' => 'nora.newcontact@example.com',
            'address' => '12 Fresh Start Rd',
            'city' => 'Gurnee',
            'state' => 'IL',
            'zip' => '60031',
            'message' => 'Kitchen',
        ],
    ]);
    $lead->statuses()->create(['title' => 'New', 'belongs_to_vendor_id' => $vendor->id]);

    expect($lead->user_id)->toBeNull();

    Livewire::actingAs($admin)
        ->test(LeadCreate::class)
        ->call('editLead', $lead)
        ->set('phoneEntry', '2245550178')
        ->call('saveContactPhone')
        ->assertHasNoErrors();

    $lead->refresh();
    $contact = User::find($lead->user_id);

    expect($contact)->not->toBeNull()
        ->and($contact->first_name)->toBe('Nora')
        ->and($contact->cell_phone)->toBe('2245550178');

    $client = $contact->clients()->first();

    expect($client)->not->toBeNull()
        ->and($client->address)->toBe('12 Fresh Start Rd');
});

it('keeps the prompt and the error on screen when the number is rejected', function () {
    // The gate used to read the bound input, so typing anything at all —
    // including garbage — hid the prompt, took the error message's own field
    // with it, and opened the Message tab without saving a thing.
    $fx = phonelessLeadFixture();

    $component = Livewire::actingAs($fx['admin'])
        ->test(LeadCreate::class)
        ->call('editLead', $fx['lead'])
        ->set('phoneEntry', '555-0134')
        ->call('saveContactPhone')
        ->assertHasErrors('phoneEntry');

    expect($component->instance()->needsPhone)->toBeTrue();

    $component->assertSee("This enquiry didn't include a phone number", false)
        ->assertSee('Enter a 10-digit phone number.', false)
        ->assertSee('saveContactPhone', false)
        ->assertDontSee('name="messages"', false);

    expect($fx['contact']->fresh()->cell_phone)->toBeNull();
});

it('does not unlock the tab from a half-typed number flushed by another action', function () {
    // wire:model on the entry box is deferred: Livewire ships the dirty value
    // with the NEXT request of any kind (the status select, say). Bound to the
    // gate's own property, "224" was enough to open the Message tab.
    $fx = phonelessLeadFixture();

    $component = Livewire::actingAs($fx['admin'])
        ->test(LeadCreate::class)
        ->call('editLead', $fx['lead'])
        ->set(['phoneEntry' => '224', 'lead_status' => 'Contacted']);

    expect($component->instance()->needsPhone)->toBeTrue();

    $component->assertDontSee('name="messages"', false)
        ->assertSee("This enquiry didn't include a phone number", false);

    expect($fx['contact']->fresh()->cell_phone)->toBeNull();
});

it('renders exactly one phone box for a lead with no contact yet', function () {
    // The legacy fallback block carried its own wire:model.live="phone" input,
    // so a lead with no linked user showed two boxes and one keystroke in the
    // wrong one bypassed the gate.
    $vendor = Vendor::factory()->create(['options' => (object) ['short_name' => 'GS']]);

    $admin = new User();
    $admin->forceFill([
        'first_name' => 'Patryk',
        'last_name' => 'Sender',
        'email' => 'phone-gate-admin3.'.uniqid().'@example.test',
        'cell_phone' => fake()->unique()->numerify('224555####'),
        'primary_vendor_id' => $vendor->id,
    ]);
    $admin->save();
    $vendor->users()->attach($admin->id, ['role_id' => 1]);
    CompanyEmail::create(['vendor_id' => $vendor->id, 'email' => $admin->email, 'grant_id' => '']);

    $lead = Lead::create([
        'date' => now(),
        'origin' => 'Email',
        'belongs_to_vendor_id' => $vendor->id,
        'created_by_user_id' => $admin->id,
        'lead_data' => ['name' => 'No Contact Yet', 'address' => '5 Nowhere Ln', 'city' => 'Gurnee'],
    ]);
    $lead->statuses()->create(['title' => 'New', 'belongs_to_vendor_id' => $vendor->id]);

    $html = Livewire::actingAs($admin)
        ->test(LeadCreate::class)
        ->call('editLead', $lead)
        ->html();

    expect(substr_count($html, 'name="phone"'))->toBe(0)
        ->and(substr_count($html, 'name="phoneEntry"'))->toBe(1)
        // and never type=number for a phone
        ->and($html)->not->toContain('type="number"');
});

it('offers the matching addresses near the office instead of guessing one', function () {
    // "511 Sherwood Dr" is a real address in Addison (12.0 mi from the office)
    // AND Streamwood (13.4 mi). Taking the nearest would have filed this lead
    // under the wrong town — the correct one here is Streamwood.
    $vendor = Vendor::factory()->create(['options' => (object) ['short_name' => 'GS']]);

    $admin = new User();
    $admin->forceFill([
        'first_name' => 'Patryk',
        'last_name' => 'Sender',
        'email' => 'addr-admin.'.uniqid().'@example.test',
        'cell_phone' => fake()->unique()->numerify('224555####'),
        'primary_vendor_id' => $vendor->id,
    ]);
    $admin->save();
    $vendor->users()->attach($admin->id, ['role_id' => 1]);
    CompanyEmail::create(['vendor_id' => $vendor->id, 'email' => $admin->email, 'grant_id' => '']);

    // Contact is complete; only the address is short — and no client yet.
    $contact = User::query()->create([
        'first_name' => 'Michael',
        'last_name' => 'Flores',
        'email' => 'mikflo.'.uniqid().'@example.com',
        'cell_phone' => '6308354185',
    ]);

    $lead = Lead::create([
        'date' => now(),
        'origin' => 'gs.construction',
        'user_id' => $contact->id,
        'belongs_to_vendor_id' => $vendor->id,
        'created_by_user_id' => $contact->id,
        'lead_data' => [
            'name' => 'Michael Flores',
            'email' => $contact->email,
            'phone' => '6308354185',
            'address' => '511 Sherwood Dr',
            'message' => 'New front door',
        ],
    ]);
    $lead->statuses()->create(['title' => 'New', 'belongs_to_vendor_id' => $vendor->id]);

    $fx = ['admin' => $admin, 'contact' => $contact, 'lead' => $lead];

    $this->mock(GeoapifyService::class)
        ->shouldReceive('geocodeAddress')->andReturn(null)
        ->shouldReceive('nearbyAddressCandidates')
        ->andReturn([
            ['address' => '511 Sherwood Drive', 'city' => 'Addison', 'state' => 'IL', 'zip_code' => '60101', 'miles' => 12.0],
            ['address' => '511 Sherwood Drive', 'city' => 'Streamwood', 'state' => 'IL', 'zip_code' => '60107', 'miles' => 13.4],
        ]);

    $component = Livewire::actingAs($fx['admin'])
        ->test(LeadCreate::class)
        ->call('editLead', $lead);

    expect($component->instance()->missingContactInfo)->toContain('a full address')
        ->and($component->instance()->addressCandidates)->toHaveCount(2);

    // Both are offered, nearest first, and the tab stays shut until one is chosen.
    $component->assertSee('Addison', false)
        ->assertSee('Streamwood', false)
        ->assertDontSee('name="messages"', false);

    // Pick the right one.
    $component->call('selectAddressCandidate', 1);

    $data = $lead->fresh()->lead_data;

    expect($data['city'])->toBe('Streamwood')
        ->and($data['state'])->toBe('IL')
        ->and($data['zip'])->toBe('60107');

    $client = $fx['contact']->fresh()->clients()->first();

    expect($client)->not->toBeNull()
        ->and($client->city)->toBe('Streamwood')
        ->and((string) $client->zip_code)->toBe('60107')
        ->and($component->instance()->missingContactInfo)->toBe([]);
});

it('takes the only nearby address without asking', function () {
    // One match is an answer, not a question — the picker is for genuine
    // ambiguity, and asking about a single option is just a chore.
    $vendor = Vendor::factory()->create(['options' => (object) ['short_name' => 'GS']]);

    $admin = new User();
    $admin->forceFill([
        'first_name' => 'Patryk',
        'last_name' => 'Sender',
        'email' => 'single-addr.'.uniqid().'@example.test',
        'cell_phone' => fake()->unique()->numerify('224555####'),
        'primary_vendor_id' => $vendor->id,
    ]);
    $admin->save();
    $vendor->users()->attach($admin->id, ['role_id' => 1]);
    CompanyEmail::create(['vendor_id' => $vendor->id, 'email' => $admin->email, 'grant_id' => '']);

    $this->mock(GeoapifyService::class)
        ->shouldReceive('geocodeAddress')->andReturn(null)
        ->shouldReceive('nearbyAddressCandidates')->andReturn([
            ['address' => '960 Danielson Court', 'city' => 'Gurnee', 'state' => 'IL', 'zip_code' => '60031', 'miles' => 18.9],
        ]);

    $lead = Lead::create([
        'date' => now(),
        'origin' => 'Email',
        'belongs_to_vendor_id' => $vendor->id,
        'created_by_user_id' => $admin->id,
        'lead_data' => [
            'name' => 'Rob Rothbaum',
            'email' => 'rob.single@example.com',
            'address' => '960 Danielson Ct',
        ],
    ]);
    $lead->statuses()->create(['title' => 'New', 'belongs_to_vendor_id' => $vendor->id]);

    $component = Livewire::actingAs($admin)->test(LeadCreate::class)->call('editLead', $lead);

    $data = $lead->fresh()->lead_data;

    expect($data['city'])->toBe('Gurnee')
        ->and($data['state'])->toBe('IL')
        ->and($data['zip'])->toBe('60031')
        ->and($component->instance()->addressCandidates)->toBe([])
        // Only the phone is outstanding now.
        ->and($component->instance()->missingContactInfo)->toBe(['a phone number']);

    $component->assertDontSee('Which address is this?', false);
});

it('does not report a complete lead address as missing just because no client exists yet', function () {
    // No phone means no contact, which means no client — but the address on
    // the lead is perfectly good, and saying otherwise sent people hunting.
    $vendor = Vendor::factory()->create(['options' => (object) ['short_name' => 'GS']]);

    $admin = new User();
    $admin->forceFill([
        'first_name' => 'Patryk',
        'last_name' => 'Sender',
        'email' => 'addr-complete.'.uniqid().'@example.test',
        'cell_phone' => fake()->unique()->numerify('224555####'),
        'primary_vendor_id' => $vendor->id,
    ]);
    $admin->save();
    $vendor->users()->attach($admin->id, ['role_id' => 1]);
    CompanyEmail::create(['vendor_id' => $vendor->id, 'email' => $admin->email, 'grant_id' => '']);

    $lead = Lead::create([
        'date' => now(),
        'origin' => 'Email',
        'belongs_to_vendor_id' => $vendor->id,
        'created_by_user_id' => $admin->id,
        'lead_data' => [
            'name' => 'Rob Rothbaum',
            'email' => 'rob.addrcomplete@example.com',
            'address' => '960 Danielson Ct',
            'city' => 'Gurnee',
            'state' => 'IL',
            'zip' => '60031',
        ],
    ]);
    $lead->statuses()->create(['title' => 'New', 'belongs_to_vendor_id' => $vendor->id]);

    $component = Livewire::actingAs($admin)->test(LeadCreate::class)->call('editLead', $lead);

    expect($component->instance()->missingContactInfo)->toBe(['a phone number'])
        ->and($lead->fresh()->user_id)->toBeNull();
});
