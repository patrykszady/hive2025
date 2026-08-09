<?php

use App\Models\Lead;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function manualLeadFixture(): array
{
    $vendor = Vendor::factory()->create();
    $vendor->forceFill(['business_type' => 'LLC', 'registration' => ['registered' => true]])->save();

    $user = new User();
    $user->forceFill([
        'first_name' => 'Patryk',
        'last_name' => 'Szady',
        'email' => 'manual.'.uniqid().'@example.test',
        'cell_phone' => fake()->unique()->numerify('224555####'),
        'primary_vendor_id' => $vendor->id,
        'registration' => ['registered' => true],
    ]);
    $user->save();
    $vendor->users()->attach($user->id, ['role_id' => 1]);

    return ['vendor' => $vendor, 'user' => $user];
}

it('creates a lead by hand for someone who called in', function () {
    $fx = manualLeadFixture();

    $component = Livewire::actingAs($fx['user'])
        ->test(\App\Livewire\Leads\LeadCreate::class)
        ->call('addLead')
        ->set('full_name', 'Kathy Moseler')
        ->set('phone', '(760) 685-3015')
        ->set('email', 'kathy@example.test')
        ->call('save')
        ->assertHasNoErrors();

    $lead = Lead::withoutGlobalScopes()->latest('id')->firstOrFail();

    expect($lead->origin)->toBe('Manual')
        ->and($lead->belongs_to_vendor_id)->toBe($fx['vendor']->id)
        ->and($lead->lead_data['name'])->toBe('Kathy Moseler')
        ->and($lead->lead_data['phone'])->toBe('7606853015')
        ->and($lead->statuses()->count())->toBe(1)
        ->and($lead->statuses()->first()->title)->toBe('New');

    // The modal flips straight into the working view on the new lead, where
    // the schedule link works with no project attached.
    expect($component->instance()->lead->id)->toBe($lead->id)
        ->and($component->instance()->scheduleLink())->toMatch('#(/l/|/lead/times/)#');
});

it('requires a way to reach the person', function () {
    $fx = manualLeadFixture();

    Livewire::actingAs($fx['user'])
        ->test(\App\Livewire\Leads\LeadCreate::class)
        ->call('addLead')
        ->set('full_name', 'No Contact')
        ->call('save')
        ->assertHasErrors(['email']);

    expect(Lead::withoutGlobalScopes()->count())->toBe(0);
});

it('resets stale fields when opening the blank form', function () {
    $fx = manualLeadFixture();

    $existing = Lead::withoutEvents(fn () => Lead::create([
        'date' => now(), 'origin' => 'Email',
        'lead_data' => ['name' => 'Old Person', 'email' => 'old@example.test'],
        'belongs_to_vendor_id' => $fx['vendor']->id,
        'created_by_user_id' => $fx['user']->id,
    ]));

    $component = Livewire::actingAs($fx['user'])
        ->test(\App\Livewire\Leads\LeadCreate::class)
        ->call('editLead', $existing->id)
        ->call('addLead');

    expect($component->instance()->lead)->toBeNull()
        ->and($component->instance()->full_name)->toBeNull()
        ->and($component->instance()->email)->toBeNull();
});

it('fills the schedule link placeholder in templates', function () {
    $fx = manualLeadFixture();

    $lead = Lead::withoutEvents(fn () => Lead::create([
        'date' => now(), 'origin' => 'Manual',
        'lead_data' => ['name' => 'Kathy Moseler', 'email' => 'kathy@example.test'],
        'belongs_to_vendor_id' => $fx['vendor']->id,
        'created_by_user_id' => $fx['user']->id,
    ]));

    $component = Livewire::actingAs($fx['user'])
        ->test(\App\Livewire\Leads\LeadCreate::class)
        ->call('editLead', $lead->id);

    $method = new \ReflectionMethod(\App\Livewire\Leads\LeadCreate::class, 'replacePlaceholders');
    $method->setAccessible(true);
    $rendered = $method->invoke($component->instance(), 'Book here: {{schedule_link}}');

    // The shortener is disabled in tests, so the raw signed URL stands in.
    expect($rendered)->toMatch('#Book here: https?://\S+(/l/|/lead/times/)\S*#');
});
