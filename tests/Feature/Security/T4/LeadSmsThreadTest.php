<?php

use App\Livewire\Leads\LeadCreate;
use App\Models\Client;
use App\Models\Lead;
use App\Models\SmsGroupThread;
use App\Models\User;
use App\Models\Vendor;
use App\Services\GroupSmsService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * A lead belonging to vendor A whose contact's phone number ALSO appears on
 * an SmsGroupThread that belongs entirely to vendor B (a different company's
 * conversation with a phone number that happens to match — plausible when a
 * homeowner is dealt with by two contractors).
 */
function sec4_leadSmsFixture(): array
{
    $vendorA = Vendor::factory()->create();
    $vendorA->forceFill(['business_type' => 'LLC', 'registration' => ['registered' => true]])->save();
    $adminA = User::factory()->create();
    $adminA->primary_vendor_id = $vendorA->id;
    $adminA->registration = ['registered' => true];
    $adminA->save();
    $vendorA->users()->attach($adminA->id, ['role_id' => 1]);

    $vendorB = Vendor::factory()->create();
    $vendorB->forceFill(['business_type' => 'LLC', 'registration' => ['registered' => true]])->save();

    $phone = '+13125550100';

    $lead = Lead::withoutEvents(fn () => Lead::create([
        'date' => now(),
        'origin' => 'Email',
        'lead_data' => ['name' => 'Homeowner', 'phone' => '3125550100'],
        'belongs_to_vendor_id' => $vendorA->id,
        'created_by_user_id' => $adminA->id,
    ]));

    $foreignThread = SmsGroupThread::create([
        'from_number' => '+13125550199',
        'participants' => [$phone],
        'vendor_id' => $vendorB->id,
        'last_activity_at' => now(),
    ]);

    return compact('vendorA', 'vendorB', 'adminA', 'lead', 'foreignThread', 'phone');
}

it('never texts into another tenant thread just because the phone number matches', function () {
    $fx = sec4_leadSmsFixture();

    // Flux::toast() reaches for the "current" Livewire component, which a
    // plain direct method call (bypassing the view-rendering bug worked
    // around elsewhere in this suite) doesn't set up — swap the facade so
    // the toast call is a no-op instead.
    \Flux\Flux::shouldReceive('toast')->andReturnNull();

    $sms = \Mockery::mock(GroupSmsService::class);
    $sms->shouldNotReceive('sendToThread');
    $sms->shouldReceive('sendNewGroup')->once()->andReturn(new SmsGroupThread());

    $this->actingAs($fx['adminA']);

    $component = new LeadCreate();
    $component->lead = $fx['lead'];

    $component->textScheduleLink($sms);
});

it('texts into the same tenant thread when one already exists for that number', function () {
    $fx = sec4_leadSmsFixture();

    \Flux\Flux::shouldReceive('toast')->andReturnNull();

    // A thread for the SAME vendor and the SAME number — legitimate reuse.
    $ownThread = SmsGroupThread::create([
        'from_number' => '+13125550199',
        'participants' => [$fx['phone']],
        'vendor_id' => $fx['vendorA']->id,
        'last_activity_at' => now(),
    ]);
    $ownThread->threadParticipants()->create([
        'phone_number' => $fx['phone'],
        'opted_in_at' => now(),
    ]);

    $sms = \Mockery::mock(GroupSmsService::class);
    $sms->shouldReceive('sendToThread')->once()->withArgs(fn ($thread) => $thread->id === $ownThread->id);
    $sms->shouldNotReceive('sendNewGroup');

    $this->actingAs($fx['adminA']);

    $component = new LeadCreate();
    $component->lead = $fx['lead'];

    $component->textScheduleLink($sms);
});

it('findExistingContact never names a client belonging to another tenant', function () {
    $fx = sec4_leadSmsFixture();

    $contact = User::factory()->create(['email' => 'shared.contact@example.test']);
    $foreignClient = Client::factory()->create(['business_name' => 'Vendor B Household']);
    $foreignClient->vendors()->attach($fx['vendorB']->id);
    $foreignClient->users()->attach($contact->id);

    $this->actingAs($fx['adminA']);

    $component = new LeadCreate();
    $component->email = $contact->email;
    $component->phone = null;

    $method = new ReflectionMethod(LeadCreate::class, 'findExistingContact');
    $method->setAccessible(true);
    $result = $method->invoke($component);

    // The user is found (users are a shared table), but the label must NOT
    // claim them as "existing client" of a company they have no client
    // record with — that identifies a stranger's household to this tenant.
    expect($result)->not->toBeNull()
        ->and($result['kind'])->toBe('user')
        ->and($result['client_id'])->toBeNull()
        ->and($result['label'])->not->toContain('Vendor B Household');
});

it('getFromUserDisplayName is not directly callable as a Livewire action', function () {
    expect((new ReflectionMethod(LeadCreate::class, 'getFromUserDisplayName'))->isPublic())->toBeFalse();
});
