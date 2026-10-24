<?php

use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
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

it('participantSearchResults is exactly availableMeetingContacts (minus selected) until a search narrows it', function (): void {
    $ownerVendor = Vendor::factory()->create();
    $client = Client::factory()->create();
    $clientContact = User::factory()->create([
        'first_name' => 'Mark',
        'last_name' => 'Brodson',
        'email' => 'mark.brodson@example.test',
    ]);
    $client->users()->attach($clientContact->id);

    $project = Project::withoutEvents(fn () => Project::create([
        'project_name' => '3154 Violet',
        'client_id' => $client->id,
        'address' => '3154 Violet Ln',
        'city' => 'Northbrook',
        'state' => 'IL',
        'zip_code' => '60062',
        'belongs_to_vendor_id' => $ownerVendor->id,
    ]));
    // Project::client() reads this pivot, not projects.client_id — the same
    // row ProjectObserver::created() writes outside of withoutEvents().
    $project->vendors()->attach($ownerVendor->id, ['client_id' => $client->id]);

    $component = Livewire::test(TaskCreate::class)
        ->set('form.type', 'Meet')
        ->set('form.project_id', $project->id);

    // Mark is the client's contact, so he's auto-selected as a participant
    // already (client contacts are always invited by default) — exactly
    // today's behavior, untouched by widening the search.
    expect($component->get('form.meeting_participants'))->toContain('mark.brodson@example.test');

    $selected = $component->get('form.meeting_participants');
    $expected = collect($component->instance()->availableMeetingContacts)
        ->reject(fn (array $contact) => in_array($contact['email'], $selected, true))
        ->values()
        ->all();

    // With nothing typed, the dropdown is exactly what it has always been:
    // the project's people minus whoever is already a pill.
    expect($component->instance()->participantSearchResults)->toBe($expected);
});

it('participantSearchResults finds anyone the company knows once a search term narrows it down, and nobody else', function (): void {
    $ownerVendor = Vendor::factory()->create();
    $creator = User::factory()->create(['primary_vendor_id' => $ownerVendor->id]);
    $ownerVendor->users()->attach($creator->id, ['role_id' => 1, 'is_employed' => 1]);
    $client = Client::factory()->create();

    $project = Project::withoutEvents(fn () => Project::create([
        'project_name' => '3154 Violet',
        'client_id' => $client->id,
        'address' => '3154 Violet Ln',
        'city' => 'Northbrook',
        'state' => 'IL',
        'zip_code' => '60062',
        'belongs_to_vendor_id' => $ownerVendor->id,
    ]));

    // Known to the company, though not to this project: another client's
    // homeowner, a vendor's employee, a past employee.
    $otherClient = Client::factory()->create();
    $otherClient->vendors()->attach($ownerVendor->id);
    $otherHomeowner = User::factory()->create(['first_name' => 'Zyxariana', 'last_name' => 'Homeowner', 'email' => 'zyxariana.homeowner@example.test']);
    $otherClient->users()->attach($otherHomeowner->id);

    $sub = Vendor::factory()->create();
    $ownerVendor->vendors()->attach($sub->id);
    $subEmployee = User::factory()->create(['first_name' => 'Zyxariana', 'last_name' => 'Plumber', 'email' => 'zyxariana.plumber@example.test']);
    $sub->users()->attach($subEmployee->id, ['role_id' => 2, 'is_employed' => 1]);

    $pastEmployee = User::factory()->create(['first_name' => 'Zyxariana', 'last_name' => 'Former', 'email' => 'zyxariana.former@example.test']);
    $ownerVendor->users()->attach($pastEmployee->id, ['role_id' => 2, 'is_employed' => 0]);

    // Another company's people stay out of reach.
    $otherCompany = Vendor::factory()->create();
    $strangersClient = Client::factory()->create();
    $strangersClient->vendors()->attach($otherCompany->id);
    $strangerHomeowner = User::factory()->create(['first_name' => 'Zyxariana', 'last_name' => 'Stranger', 'email' => 'zyxariana.stranger@example.test']);
    $strangersClient->users()->attach($strangerHomeowner->id);
    $strangerStaff = User::factory()->create(['first_name' => 'Zyxariana', 'last_name' => 'Rival', 'email' => 'zyxariana.rival@example.test']);
    $otherCompany->users()->attach($strangerStaff->id, ['role_id' => 1, 'is_employed' => 1]);
    $unattached = User::factory()->create(['first_name' => 'Zyxariana', 'last_name' => 'Nobody', 'email' => 'zyxariana.nobody@example.test']);

    $component = Livewire::actingAs($creator)
        ->test(TaskCreate::class)
        ->set('form.type', 'Meet')
        ->set('form.project_id', $project->id)
        ->set('participantSearch', 'zyxari');

    expect(collect($component->instance()->participantSearchResults)->pluck('email')->sort()->values()->all())
        ->toBe(['zyxariana.former@example.test', 'zyxariana.homeowner@example.test', 'zyxariana.plumber@example.test']);

    // A stranger's email typed in by hand is still invited, but their name is not given away.
    $component->call('addMeetingParticipant', $strangerHomeowner->email);
    expect($component->instance()->meetingParticipantDisplayName($strangerHomeowner->email))->toBe($strangerHomeowner->email);
});

it('a Meet task saves with a participant found through the search, not just the project\'s people', function (): void {
    $ownerVendor = Vendor::factory()->create();

    // TaskObserver::creating() stamps belongs_to_vendor_id/created_by_user_id
    // from the signed-in user — a real save needs one, same as production.
    $creator = User::factory()->create(['primary_vendor_id' => $ownerVendor->id]);
    $ownerVendor->users()->attach($creator->id, ['role_id' => 1, 'is_employed' => 1]);

    $client = Client::factory()->create();

    $project = Project::withoutEvents(fn () => Project::create([
        'project_name' => '3154 Violet',
        'client_id' => $client->id,
        'address' => '3154 Violet Ln',
        'city' => 'Northbrook',
        'state' => 'IL',
        'zip_code' => '60062',
        'belongs_to_vendor_id' => $ownerVendor->id,
    ]));
    $project->vendors()->attach($ownerVendor->id, ['client_id' => $client->id]);

    // The homeowner of another of the company's clients.
    $outsider = User::factory()->create([
        'first_name' => 'Zyxariana',
        'last_name' => 'Quilborne',
        'email' => 'zyxariana.quilborne@example.test',
    ]);
    $otherClient = Client::factory()->create();
    $otherClient->vendors()->attach($ownerVendor->id);
    $otherClient->users()->attach($outsider->id);

    $component = Livewire::actingAs($creator)
        ->test(TaskCreate::class)
        ->call('addTask', $project->id)
        ->set('form.type', 'Meet')
        ->set('form.title', 'Brodson Review')
        ->set('participantSearch', 'zyxari');

    // Found by the search...
    expect(collect($component->instance()->participantSearchResults)->pluck('email'))
        ->toContain($outsider->email);

    // ...added like any other suggestion...
    $component->call('addMeetingParticipant', $outsider->email);

    expect($component->get('form.meeting_participants'))->toContain($outsider->email);
    // The search box clears, and the pill reads their name, not the raw email.
    expect($component->get('participantSearch'))->toBe('');
    $component->assertSee('Zyxariana Quilborne');

    // ...and persisted when the task is actually created.
    $component->call('save');

    $task = Task::query()
        ->where('project_id', $project->id)
        ->where('title', 'Brodson Review')
        ->first();

    expect($task)->not->toBeNull();
    expect((array) ($task->options->meeting_participants ?? []))->toContain($outsider->email);
});

it('keeps a booked Meet\'s attendees when it is opened, re-teamed and saved: no homeowner comes back', function (): void {
    // Task 1209 (2026-10-23): Doug Abbey was taken off the invite at booking;
    // opening the Meet to fix its note put him back, and the save stored him.
    \Illuminate\Support\Facades\Queue::fake();
    $owner = Vendor::factory()->create(['business_name' => 'GS Construction', 'business_email' => 'office@gs.test']);
    $admin = User::factory()->create(['email' => 'patryk@gs.test', 'primary_vendor_id' => $owner->id]);
    $owner->users()->attach($admin->id, ['role_id' => 1, 'is_employed' => 1]);
    $greg = User::factory()->create(['email' => 'greg@gs.test', 'primary_vendor_id' => $owner->id]);
    $owner->users()->attach($greg->id, ['role_id' => 1, 'is_employed' => 1]);

    $this->actingAs($admin);
    $client = Client::factory()->create(['vendor_id' => $owner->id]);
    $client->vendors()->syncWithoutDetaching([$owner->id]);
    $erin = User::factory()->create(['first_name' => 'Erin', 'email' => 'erin@example.com']);
    $doug = User::factory()->create(['first_name' => 'Doug', 'email' => 'doug@example.com']);
    $client->users()->attach([$erin->id, $doug->id]);
    $project = Project::factory()->create(['client_id' => $client->id, 'belongs_to_vendor_id' => $owner->id]);
    // A project's client is reached through the company's link to it.
    if (! $project->vendors()->where('vendors.id', $owner->id)->exists()) {
        $project->vendors()->attach($owner->id, ['client_id' => $client->id]);
    } else {
        $project->vendors()->updateExistingPivot($owner->id, ['client_id' => $client->id]);
    }
    expect($project->fresh()->client?->users->pluck('email')->all())->toContain('doug@example.com');

    $booked = ['patryk@gs.test', 'erin@example.com', 'jenn@designer.test'];
    $task = Task::create([
        'title' => 'Meet', 'type' => 'Meet', 'project_id' => $project->id, 'belongs_to_vendor_id' => $owner->id,
        'start_date' => now()->addDays(3)->format('Y-m-d'), 'end_date' => now()->addDays(3)->format('Y-m-d'),
        'user_ids' => [$admin->id], 'notes' => '12-PM = Arrival Time.',
        'options' => ['dates' => [now()->addDays(3)->format('Y-m-d')], 'time_settings' => [], 'meeting_location_type' => 'in_person', 'meeting_participants' => $booked],
    ]);

    $page = Livewire::actingAs($admin)
        ->test(TaskCreate::class)
        ->call('editTask', $task->id)
        ->assertSet('form.meeting_participants', $booked);

    // Adding Greg to the team brings Greg, not the homeowner taken off.
    $page->set('form.user_ids', [$admin->id, $greg->id]);
    expect($page->get('form.meeting_participants'))->toContain('greg@gs.test')->not->toContain('doug@example.com');

    $page->set('form.notes', '12-1PM = Arrival Time.')->call('edit');

    expect(data_get(Task::find($task->id)->options, 'meeting_participants'))->not->toContain('doug@example.com')
        ->toContain('erin@example.com', 'jenn@designer.test', 'greg@gs.test');
});
