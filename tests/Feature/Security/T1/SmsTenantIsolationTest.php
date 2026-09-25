<?php

use App\Livewire\Sms\SendScheduleModal;
use App\Livewire\Sms\SmsConversation;
use App\Livewire\Sms\SmsNewThread;
use App\Models\Client;
use App\Models\Project;
use App\Models\SmsGroupThread;
use App\Models\Task;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;

uses(RefreshDatabase::class);

function sec1_sms_company(string $label): array
{
    $vendor = Vendor::factory()->create(['business_name' => "SMS Co {$label}"]);
    $vendor->forceFill(['registration' => ['registered' => true]])->save();

    $user = new User();
    $user->forceFill([
        'first_name' => $label,
        'last_name' => 'Admin',
        'email' => 'sec1-sms-' . strtolower($label) . '-' . uniqid() . '@example.test',
        'cell_phone' => fake()->unique()->numerify('224555####'),
        'primary_vendor_id' => $vendor->id,
        'registration' => ['registered' => true],
    ]);
    $user->save();
    $vendor->users()->attach($user->id, ['role_id' => 1]);

    $client = Client::factory()->create();
    $client->vendors()->attach($vendor->id);

    return compact('vendor', 'user', 'client');
}

function sec1_sms_thread(array $company, array $overrides = []): SmsGroupThread
{
    return SmsGroupThread::create(array_merge([
        'from_number' => '+1224555' . random_int(1000, 9999),
        'participants' => ['+12245550001'],
        'vendor_id' => $company['vendor']->id,
        'client_id' => $company['client']->id,
        'last_activity_at' => now(),
    ], $overrides));
}

// ── threadId / isClientUser are Locked ──────────────────────────────────

it('locks SmsConversation threadId against direct client writes', function (): void {
    $mine = sec1_sms_company('A');
    $thread = sec1_sms_thread($mine);

    $component = Livewire::actingAs($mine['user'])->test(SmsConversation::class);

    expect(fn () => $component->set('threadId', $thread->id + 999999))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

it('locks SmsConversation isClientUser against direct client writes', function (): void {
    $mine = sec1_sms_company('A2');

    $component = Livewire::actingAs($mine['user'])->test(SmsConversation::class);

    expect(fn () => $component->set('isClientUser', false))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

it('refuses to load another company\'s thread via loadThread', function (): void {
    $mine = sec1_sms_company('A3');
    $theirs = sec1_sms_company('B3');
    $foreignThread = sec1_sms_thread($theirs);

    Livewire::actingAs($mine['user'])
        ->test(SmsConversation::class)
        ->call('loadThread', $foreignThread->id)
        ->assertSet('threadId', null);
});

it('loads a thread belonging to the same company', function (): void {
    $mine = sec1_sms_company('A4');
    $thread = sec1_sms_thread($mine);

    Livewire::actingAs($mine['user'])
        ->test(SmsConversation::class)
        ->call('loadThread', $thread->id)
        ->assertSet('threadId', $thread->id);
});

// ── assignClient: clientId must be this tenant's client ─────────────────

it('refuses to assign a thread to another company\'s client', function (): void {
    $mine = sec1_sms_company('A5');
    $theirs = sec1_sms_company('B5');
    $thread = sec1_sms_thread($mine);

    Livewire::actingAs($mine['user'])
        ->test(SmsConversation::class)
        ->call('loadThread', $thread->id)
        ->set('assignSubjectType', 'client')
        ->set('assignClientId', $theirs['client']->id)
        ->call('assignClient')
        ->assertHasErrors('assignClientId');

    expect($thread->fresh()->client_id)->toBe($mine['client']->id);
});

it('assigns a thread to the company\'s own client', function (): void {
    $mine = sec1_sms_company('A6');
    $otherClient = Client::factory()->create();
    $otherClient->vendors()->attach($mine['vendor']->id);
    $thread = sec1_sms_thread($mine);

    Livewire::actingAs($mine['user'])
        ->test(SmsConversation::class)
        ->call('loadThread', $thread->id)
        ->set('assignSubjectType', 'client')
        ->set('assignClientId', $otherClient->id)
        ->call('assignClient')
        ->assertHasNoErrors('assignClientId');

    expect($thread->fresh()->client_id)->toBe($otherClient->id);
});

// ── SmsNewThread::send: clientId must be this tenant's client ───────────

it('refuses to start a new thread tagged to another company\'s client', function (): void {
    $mine = sec1_sms_company('A7');
    $theirs = sec1_sms_company('B7');

    Livewire::actingAs($mine['user'])
        ->test(SmsNewThread::class)
        ->set('recipientType', 'client')
        ->set('clientId', $theirs['client']->id)
        ->set('recipients', [[
            'number' => '+12245550001',
            'display' => '(224) 555-0001',
            'label' => 'Someone',
        ]])
        ->set('message', 'Hello')
        ->call('send')
        ->assertHasErrors('clientId');

    expect(SmsGroupThread::where('client_id', $theirs['client']->id)->exists())->toBeFalse();
});

// ── SendScheduleModal: open() must check accessibleTo, and is Locked ────

it('refuses to open the schedule modal for another company\'s thread', function (): void {
    $mine = sec1_sms_company('A8');
    $theirs = sec1_sms_company('B8');
    $foreignThread = sec1_sms_thread($theirs);

    Livewire::actingAs($mine['user'])
        ->test(SendScheduleModal::class)
        ->call('open', $foreignThread->id)
        ->assertForbidden();
});

it('opens the schedule modal for the company\'s own thread', function (): void {
    $mine = sec1_sms_company('A9');
    $thread = sec1_sms_thread($mine);

    Livewire::actingAs($mine['user'])
        ->test(SendScheduleModal::class)
        ->call('open', $thread->id)
        ->assertSet('threadId', $thread->id)
        ->assertSet('showModal', true);
});

it('locks SendScheduleModal threadId against direct client writes', function (): void {
    $mine = sec1_sms_company('A10');
    $thread = sec1_sms_thread($mine);

    $component = Livewire::actingAs($mine['user'])->test(SendScheduleModal::class)
        ->call('open', $thread->id);

    expect(fn () => $component->set('threadId', $thread->id + 999999))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

// ── SendScheduleModal::clientProjectIds: a sub shared by two GCs ────────

it('does not pull another GC\'s tasks for a sub-vendor thread into the schedule preview', function (): void {
    $gcA = sec1_sms_company('GcA');
    $gcB = sec1_sms_company('GcB');
    $sub = Vendor::factory()->create(['business_name' => 'Shared Sub']);

    // withoutEvents(): no user is signed in yet while building this fixture,
    // and ProjectObserver::creating() needs one — attach the project_vendor
    // pivot manually instead, same relationship a real create() leaves.
    $projectA = Project::withoutEvents(fn () => Project::create([
        'project_name' => 'GC A Project', 'client_id' => $gcA['client']->id,
        'address' => '1 A St', 'city' => 'Chicago', 'state' => 'IL', 'zip_code' => '60601',
        'belongs_to_vendor_id' => $gcA['vendor']->id,
    ]));
    $projectA->vendors()->attach($gcA['vendor']->id, ['client_id' => $gcA['client']->id]);

    $projectB = Project::withoutEvents(fn () => Project::create([
        'project_name' => 'GC B Project', 'client_id' => $gcB['client']->id,
        'address' => '2 B St', 'city' => 'Chicago', 'state' => 'IL', 'zip_code' => '60601',
        'belongs_to_vendor_id' => $gcB['vendor']->id,
    ]));
    $projectB->vendors()->attach($gcB['vendor']->id, ['client_id' => $gcB['client']->id]);

    // withoutEvents(): TaskObserver::creating() also needs a signed-in user
    // (and would overwrite belongs_to_vendor_id from it).
    Task::withoutEvents(fn () => Task::create([
        'title' => 'A\'s task for the sub', 'project_id' => $projectA->id,
        'vendor_id' => $sub->id, 'belongs_to_vendor_id' => $gcA['vendor']->id,
        'created_by_user_id' => $gcA['user']->id, 'order' => 0,
        'type' => 'Task', 'start_date' => today(), 'end_date' => today(),
    ]));
    Task::withoutEvents(fn () => Task::create([
        'title' => 'B\'s task for the same sub', 'project_id' => $projectB->id,
        'vendor_id' => $sub->id, 'belongs_to_vendor_id' => $gcB['vendor']->id,
        'created_by_user_id' => $gcB['user']->id, 'order' => 0,
        'type' => 'Task', 'start_date' => today(), 'end_date' => today(),
    ]));

    $threadWithSub = SmsGroupThread::create([
        'from_number' => '+12245559999',
        'participants' => ['+12245550001'],
        'vendor_id' => $gcA['vendor']->id,
        'subject_vendor_id' => $sub->id,
        'last_activity_at' => now(),
    ]);

    $component = Livewire::actingAs($gcA['user'])
        ->test(SendScheduleModal::class)
        ->call('open', $threadWithSub->id);

    expect($component->instance()->clientProjectIds())
        ->toContain($projectA->id)
        ->not->toContain($projectB->id);
});
