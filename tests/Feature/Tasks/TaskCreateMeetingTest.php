<?php

use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\Vendor;
use App\Livewire\Forms\TaskForm;
use App\Livewire\Tasks\TaskCreate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('defaults meeting location type to in_person', function (): void {
    $form = new TaskForm(new TaskCreate(), 'form');

    expect($form->meeting_location_type)->toBe('in_person');
});

it('can set meeting location type to virtual', function (): void {
    $form = new TaskForm(new TaskCreate(), 'form');
    $form->meeting_location_type = 'virtual';

    expect($form->meeting_location_type)->toBe('virtual');
});

it('starts with empty meeting participants', function (): void {
    $form = new TaskForm(new TaskCreate(), 'form');

    expect($form->meeting_participants)->toBe([]);
});

it('meeting_location_type only accepts valid values', function (): void {
    $form = new TaskForm(new TaskCreate(), 'form');

    $form->meeting_location_type = 'virtual';
    expect($form->meeting_location_type)->toBe('virtual');

    $form->meeting_location_type = 'in_person';
    expect($form->meeting_location_type)->toBe('in_person');
});

it('meeting_participants is an array', function (): void {
    $form = new TaskForm(new TaskCreate(), 'form');
    $form->meeting_participants = ['john@example.com', 'jane@example.com'];

    expect($form->meeting_participants)
        ->toBeArray()
        ->toHaveCount(2)
        ->toContain('john@example.com')
        ->toContain('jane@example.com');
});

it('addMeetingParticipant validates email and adds to list', function (): void {
    $component = app(TaskCreate::class);

    // Use reflection to initialize the form property
    $component->form = new TaskForm($component, 'form');

    $component->addMeetingParticipant('john@example.com');
    expect($component->form->meeting_participants)->toBe(['john@example.com']);
});

it('addMeetingParticipant normalizes email to lowercase', function (): void {
    $component = app(TaskCreate::class);
    $component->form = new TaskForm($component, 'form');

    $component->addMeetingParticipant('John@Example.COM');
    expect($component->form->meeting_participants)->toBe(['john@example.com']);
});

it('addMeetingParticipant prevents duplicates', function (): void {
    $component = app(TaskCreate::class);
    $component->form = new TaskForm($component, 'form');

    $component->addMeetingParticipant('john@example.com');
    $component->addMeetingParticipant('john@example.com');

    expect($component->form->meeting_participants)->toBe(['john@example.com']);
});

it('addMeetingParticipant rejects invalid emails', function (): void {
    $component = app(TaskCreate::class);
    $component->form = new TaskForm($component, 'form');

    $component->addMeetingParticipant('not-an-email');
    expect($component->form->meeting_participants)->toBe([]);
});

it('addMeetingParticipant rejects empty string', function (): void {
    $component = app(TaskCreate::class);
    $component->form = new TaskForm($component, 'form');

    $component->addMeetingParticipant('');
    expect($component->form->meeting_participants)->toBe([]);
});

it('removeMeetingParticipant removes by index and reindexes', function (): void {
    $component = app(TaskCreate::class);
    $component->form = new TaskForm($component, 'form');

    $component->addMeetingParticipant('john@example.com');
    $component->addMeetingParticipant('jane@example.com');
    $component->addMeetingParticipant('bob@example.com');

    $component->removeMeetingParticipant(1);

    expect($component->form->meeting_participants)
        ->toBe(['john@example.com', 'bob@example.com']);
});

it('includes selected vendor contact and excludes owner company business email in default meeting participants', function (): void {
    $ownerVendor = Vendor::factory()->create([
        'business_name' => 'GS Construction',
        'business_email' => 'crew@gs.construction',
    ]);

    $selectedVendor = Vendor::factory()->create([
        'business_name' => 'PMG Carpentry',
        'business_email' => 'pmg@example.test',
    ]);

    $client = Client::factory()->create();

    $project = Project::withoutEvents(fn () => Project::create([
        'project_name' => 'Framing/Foundation Consult',
        'client_id' => $client->id,
        'address' => '3154 Violet Ln',
        'city' => 'Northbrook',
        'state' => 'IL',
        'zip_code' => '60062',
        'belongs_to_vendor_id' => $ownerVendor->id,
    ]));

    $component = Livewire::test(TaskCreate::class)
        ->set('form.type', 'Meet')
        ->set('form.vendor_id', $selectedVendor->id)
        ->set('form.project_id', $project->id);

    $participants = $component->get('form.meeting_participants');

    expect($participants)
        ->toContain('pmg@example.test')
        ->not->toContain('crew@gs.construction');
});

it('merges selected vendor contact when editing an existing meet task', function (): void {
    $ownerVendor = Vendor::factory()->create([
        'business_name' => 'GS Construction',
        'business_email' => 'crew@gs.construction',
    ]);

    $selectedVendor = Vendor::factory()->create([
        'business_name' => 'PMG Carpentry',
        'business_email' => 'pmg@example.test',
    ]);

    $client = Client::factory()->create();

    $project = Project::withoutEvents(fn () => Project::create([
        'project_name' => 'Framing/Foundation Consult',
        'client_id' => $client->id,
        'address' => '3154 Violet Ln',
        'city' => 'Northbrook',
        'state' => 'IL',
        'zip_code' => '60062',
        'belongs_to_vendor_id' => $ownerVendor->id,
    ]));

    $task = Task::withoutEvents(fn () => Task::create([
        'title' => 'Framing/Foundation Consult',
        'type' => 'Meet',
        'order' => 1,
        'project_id' => $project->id,
        'vendor_id' => $selectedVendor->id,
        'user_ids' => [],
        'notes' => null,
        'belongs_to_vendor_id' => $ownerVendor->id,
        'created_by_user_id' => 1,
        'options' => [
            'meeting_participants' => ['external@example.test'],
        ],
    ]));

    $component = Livewire::test(TaskCreate::class)
        ->call('editTask', $task->id);

    $participants = $component->get('form.meeting_participants');

    expect($participants)
        ->toContain('external@example.test')
        ->toContain('pmg@example.test');
});

it('removes owner company business email from legacy participants when editing meet task', function (): void {
    $ownerVendor = Vendor::factory()->create([
        'business_name' => 'GS Construction',
        'business_email' => 'crew@gs.construction',
    ]);

    $selectedVendor = Vendor::factory()->create([
        'business_name' => 'PMG Carpentry',
        'business_email' => 'pmg@example.test',
    ]);

    $client = Client::factory()->create();

    $project = Project::withoutEvents(fn () => Project::create([
        'project_name' => 'Framing/Foundation Consult',
        'client_id' => $client->id,
        'address' => '3154 Violet Ln',
        'city' => 'Northbrook',
        'state' => 'IL',
        'zip_code' => '60062',
        'belongs_to_vendor_id' => $ownerVendor->id,
    ]));

    $task = Task::withoutEvents(fn () => Task::create([
        'title' => 'Framing/Foundation Consult',
        'type' => 'Meet',
        'order' => 1,
        'project_id' => $project->id,
        'vendor_id' => $selectedVendor->id,
        'user_ids' => [],
        'notes' => null,
        'belongs_to_vendor_id' => $ownerVendor->id,
        'created_by_user_id' => 1,
        'options' => [
            'meeting_participants' => ['external@example.test', 'crew@gs.construction'],
        ],
    ]));

    $component = Livewire::test(TaskCreate::class)
        ->call('editTask', $task->id);

    $participants = $component->get('form.meeting_participants');

    expect($participants)
        ->toContain('external@example.test')
        ->toContain('pmg@example.test')
        ->not->toContain('crew@gs.construction');
});

it('gives a Meet half an hour: the end follows the start by 30 minutes', function (): void {
    $date = '2026-05-19';

    $component = Livewire::test(TaskCreate::class)
        ->set('form.type', 'Meet')
        ->set('form.dates', [$date])
        ->set('form.time_settings', [$date => ['use_time' => true, 'start_time' => null, 'end_time' => null]])
        ->set("form.time_settings.{$date}.start_time", '14:00');

    expect($component->get("form.time_settings.{$date}.end_time"))->toBe('14:30');
});

it('opens the end picker at start + 30 for a Meet, and at the start itself otherwise', function (): void {
    $date = '2026-05-19';

    $meet = Livewire::test(TaskCreate::class)
        ->set('form.type', 'Meet')
        ->set('form.time_settings', [$date => ['use_time' => true, 'start_time' => '14:00', 'end_time' => '14:30']]);

    expect($meet->instance()->minimumEndTime($date))->toBe('14:30');

    $other = Livewire::test(TaskCreate::class)
        ->set('form.type', 'Service')
        ->set('form.time_settings', [$date => ['use_time' => true, 'start_time' => '14:00', 'end_time' => '14:00']]);

    expect($other->instance()->minimumEndTime($date))->toBe('14:00');
});

it('keeps mirroring the start for task types that are not Meet', function (): void {
    $date = '2026-05-19';

    $component = Livewire::test(TaskCreate::class)
        ->set('form.type', 'Service')
        ->set('form.dates', [$date])
        ->set('form.time_settings', [$date => ['use_time' => true, 'start_time' => null, 'end_time' => null]])
        ->set("form.time_settings.{$date}.start_time", '14:00');

    expect($component->get("form.time_settings.{$date}.end_time"))->toBe('14:00');
});

it('does not push a late Meet into the next day', function (): void {
    $date = '2026-05-19';

    $component = Livewire::test(TaskCreate::class)
        ->set('form.type', 'Meet')
        ->set('form.dates', [$date])
        ->set('form.time_settings', [$date => ['use_time' => true, 'start_time' => null, 'end_time' => null]])
        ->set("form.time_settings.{$date}.start_time", '23:45');

    // 00:15 tomorrow is not an end time for today's meeting — keep the mirror.
    expect($component->get("form.time_settings.{$date}.end_time"))->toBe('23:45');
});
