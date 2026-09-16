<?php

use App\Livewire\Leads\LeadCreate;
use App\Models\Client;
use App\Models\CompanyEmail;
use App\Models\Lead;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * A company with an admin, two client contacts, a staff member and a rival
 * company's customer — and one lead of its own that has no user linked.
 */
function leadPickerFixture(): array
{
    $vendor = Vendor::factory()->create(['options' => (object) ['short_name' => 'GS']]);
    $admin = new User;
    $admin->forceFill([
        'first_name' => 'Patryk', 'last_name' => 'Sender',
        'email' => 'picker-admin.'.uniqid().'@example.test',
        'cell_phone' => fake()->unique()->numerify('224555####'),
        'primary_vendor_id' => $vendor->id,
    ]);
    $admin->save();
    $vendor->users()->attach($admin->id, ['role_id' => 1]);
    CompanyEmail::create(['vendor_id' => $vendor->id, 'email' => $admin->email, 'grant_id' => '']);

    $kevin = User::query()->create(['first_name' => 'Kevin', 'last_name' => 'Rodriguez', 'email' => 'kevin.r@example.com', 'cell_phone' => '2245550101']);
    $kate = User::query()->create(['first_name' => 'Kate', 'last_name' => 'Rowe', 'email' => 'kate.rowe.2245550102@'.User::PLACEHOLDER_EMAIL_DOMAIN, 'cell_phone' => '2245550102']);
    $client = Client::factory()->create(['address' => '12 Oak St', 'city' => 'Park Ridge', 'state' => 'IL', 'zip_code' => '60068']);
    $client->vendors()->attach($vendor->id);
    $client->users()->attach([$kevin->id, $kate->id]);

    // Staff of this company: never a lead's contact.
    $staff = User::query()->create(['first_name' => 'Kevin', 'last_name' => 'Staff', 'email' => 'kevin.staff@example.test', 'cell_phone' => '2245550103']);
    $vendor->users()->attach($staff->id, ['role_id' => 2]);

    // Another company's customer: not this company's business.
    $rival = Vendor::factory()->create();
    $stranger = User::query()->create(['first_name' => 'Kevin', 'last_name' => 'Elsewhere', 'email' => 'kevin.elsewhere@example.com', 'cell_phone' => '2245550104']);
    $rivalClient = Client::factory()->create();
    $rivalClient->vendors()->attach($rival->id);
    $rivalClient->users()->attach($stranger->id);

    $lead = Lead::create([
        'date' => now(), 'origin' => 'Manual', 'belongs_to_vendor_id' => $vendor->id, 'created_by_user_id' => $admin->id,
        'lead_data' => ['name' => 'Kev', 'address' => '45 Elm Ave', 'city' => 'Park Ridge', 'state' => 'IL', 'zip' => '60068', 'message' => 'Kitchen'],
    ]);
    $lead->statuses()->create(['title' => 'New', 'belongs_to_vendor_id' => $vendor->id]);

    test()->actingAs($admin);

    return compact('vendor', 'admin', 'kevin', 'kate', 'client', 'staff', 'stranger', 'lead');
}

it('offers the company\'s own contacts in the dropdown, never its staff or another company\'s customers', function () {
    $fx = leadPickerFixture();

    $component = Livewire::test(LeadCreate::class)->dispatch('editLead', lead: $fx['lead']->id);

    $choices = collect($component->get('userChoices'));
    expect($choices->pluck('label')->all())->toBe([
        'Kate Rowe — (224) 555-0102',
        'Kevin Rodriguez — kevin.r@example.com',
    ])->and($component->get('selectedUserId'))->toBeNull();

    // A lead already linked to someone outside the list still shows them, first.
    $fx['lead']->forceFill(['user_id' => $fx['admin']->id])->saveQuietly();
    $component = Livewire::test(LeadCreate::class)->dispatch('editLead', lead: $fx['lead']->id);
    expect(collect($component->get('userChoices'))->pluck('name')->first())->toBe('Patryk Sender')
        ->and((int) $component->get('selectedUserId'))->toBe($fx['admin']->id);
});

it('links the user chosen in the dropdown to an existing lead, takes their email, and puts them on the client at the lead\'s address', function () {
    $fx = leadPickerFixture();

    $component = Livewire::test(LeadCreate::class)
        ->dispatch('editLead', lead: $fx['lead']->id)
        ->assertSee('Add User')
        ->set('selectedUserId', (string) $fx['kevin']->id);

    $lead = $fx['lead']->fresh();
    expect($lead->user_id)->toBe($fx['kevin']->id)
        ->and($lead->lead_data['name'])->toBe('Kevin Rodriguez')
        ->and($lead->lead_data['email'])->toBe('kevin.r@example.com');

    $component->assertSet('full_name', 'Kevin Rodriguez')
        ->assertSet('email', 'kevin.r@example.com')
        ->assertSet('phone', '2245550101')
        ->assertDontSee('Add User')
        ->assertSee('Kevin');

    // The lead's address is a client Kevin is now on, and it is the lead's client.
    $elm = Client::withoutGlobalScopes()->where('address', 'like', '45 Elm%')->first();
    expect($elm)->not->toBeNull()
        ->and($fx['kevin']->clients()->withoutGlobalScopes()->pluck('clients.id')->all())->toContain($elm->id)
        ->and($component->get('client')?->id)->toBe($elm->id);

    // Choosing someone else re-links.
    $component->set('selectedUserId', (string) $fx['kate']->id);
    expect($fx['lead']->fresh()->user_id)->toBe($fx['kate']->id);
    $component->assertSet('full_name', 'Kate Rowe');
});

it('links the chosen user when a new lead is created, without the duplicate warning', function () {
    $fx = leadPickerFixture();

    $component = Livewire::test(LeadCreate::class)
        ->dispatch('addLead')
        ->set('selectedUserId', (string) $fx['kevin']->id)
        ->assertSet('attachUserId', $fx['kevin']->id)
        ->assertSet('full_name', 'Kevin Rodriguez')
        ->assertSee('Will be linked to this existing user')
        ->set('message', 'Basement bar.')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('duplicateMatch', null);

    $lead = Lead::withoutGlobalScopes()->where('belongs_to_vendor_id', $fx['vendor']->id)->latest('id')->first();
    expect($lead->lead_data['name'])->toBe('Kevin Rodriguez')
        ->and($lead->user_id)->toBe($fx['kevin']->id)
        ->and(User::where('first_name', 'Kevin')->where('last_name', 'Rodriguez')->count())->toBe(1);
});

it('adds a user from the lead\'s own details when none is linked, and refuses without a way to reach them', function () {
    $fx = leadPickerFixture();

    $component = Livewire::test(LeadCreate::class)
        ->dispatch('editLead', lead: $fx['lead']->id)
        ->set('full_name', 'Kevin Rieger')
        ->call('addUser')
        ->assertHasErrors(['email']);

    expect($fx['lead']->fresh()->user_id)->toBeNull();

    $component->set('email', 'kevin.rieger@example.com')
        ->call('addUser')
        ->assertHasNoErrors();

    $lead = $fx['lead']->fresh();
    $user = User::where('email', 'kevin.rieger@example.com')->first();
    expect($user)->not->toBeNull()
        ->and($user->first_name)->toBe('Kevin')
        ->and($user->last_name)->toBe('Rieger')
        ->and($lead->user_id)->toBe($user->id)
        ->and($lead->lead_data['email'])->toBe('kevin.rieger@example.com');

    $component->assertDontSee('Add User')->assertSee('Kevin');

    // A second click does nothing: the lead has its user.
    $component->call('addUser');
    expect(User::where('email', 'kevin.rieger@example.com')->count())->toBe(1);
});

it('does not let an arbitrary user id be attached', function () {
    $fx = leadPickerFixture();

    $component = Livewire::test(LeadCreate::class)
        ->dispatch('editLead', lead: $fx['lead']->id)
        ->set('selectedUserId', (string) $fx['stranger']->id)
        ->set('selectedUserId', (string) $fx['staff']->id)
        ->call('attachUser', $fx['stranger']->id);

    expect($fx['lead']->fresh()->user_id)->toBeNull();
    $component->assertSet('selectedUserId', null);
});
