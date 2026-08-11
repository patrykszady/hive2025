<?php

use App\Livewire\Sms\SendScheduleModal;
use App\Models\Client;
use App\Models\Lead;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\ShortLink;
use App\Models\SmsGroupThread;
use App\Models\Task;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// The preview is time-of-day aware (after closing, "Today" drops off) — pin
// the clock to mid-morning so fixtures with today-tasks read deterministically.
beforeEach(function (): void {
    Carbon\Carbon::setTestNow(Carbon\Carbon::create(2026, 8, 10, 10, 0, 0, 'America/Chicago'));
});

afterEach(function (): void {
    Carbon\Carbon::setTestNow();
});

it('uses vendor short name in schedule greeting for vendor-subject threads', function (): void {
    $ownerVendor = Vendor::factory()->create([
        'business_name' => 'GS Construction',
    ]);

    $ownerUser = User::query()->create([
        'first_name' => 'Owner',
        'last_name' => 'User',
        'email' => 'owner.schedule-modal@example.com',
        'cell_phone' => '2245550099',
        'primary_vendor_id' => $ownerVendor->id,
    ]);

    $this->actingAs($ownerUser);

    $subjectVendor = Vendor::factory()->create([
        'business_name' => 'Smartech Electric',
    ]);
    $subjectVendor->short_name = 'Smartech';
    $subjectVendor->save();

    $subjectUser = User::query()->create([
        'first_name' => 'Pawel',
        'last_name' => 'Bach',
        'email' => 'pawel.vendor-thread@example.com',
        'cell_phone' => '2245550001',
        'primary_vendor_id' => $subjectVendor->id,
    ]);

    $subjectVendor->users()->attach($subjectUser->id, [
        'is_employed' => true,
        'role_id' => 1,
    ]);

    $client = Client::factory()->create();
    $project = Project::query()->create([
        'project_name' => 'Vendor Thread Project',
        'client_id' => $client->id,
        'address' => '3154 Violet Ln',
        'city' => 'Northbrook',
        'state' => 'IL',
        'zip_code' => 60062,
        'belongs_to_vendor_id' => $ownerVendor->id,
    ]);

    Task::query()->create([
        'title' => 'Foundation',
        'project_id' => $project->id,
        'vendor_id' => $subjectVendor->id,
        'type' => 'Task',
        'start_date' => today(),
        'end_date' => today(),
    ]);

    $thread = SmsGroupThread::query()->create([
        'name' => 'Pawel Bach',
        'from_number' => '+12245554444',
        'participants' => ['+12245550001'],
        'vendor_id' => $ownerVendor->id,
        'subject_vendor_id' => $subjectVendor->id,
        'last_activity_at' => now(),
    ]);

    $preview = Livewire::test(SendScheduleModal::class)
        ->call('open', $thread->id)
        ->get('previewMessage');

    expect($preview)->toStartWith('Hi Smartech,')
        ->and($preview)->toContain('Upcoming tasks:')
        // Greeting flows straight into the intro — no blank line between them.
        ->and($preview)->toContain(",\nUpcoming tasks:")
        ->and($preview)->not->toContain("\n\nUpcoming tasks:")
        ->and($preview)->toContain('- Foundation (Vendor Thread Project)')
        ->and($preview)->toContain('Confirm Schedule:');
});

it('uses client schedule wording for client threads', function (): void {
    $vendor = Vendor::factory()->create(['business_name' => 'GS Construction']);

    $user = User::query()->create([
        'first_name' => 'Owner',
        'last_name' => 'User',
        'email' => 'owner.schedule-modal-client@example.com',
        'cell_phone' => '2245550098',
        'primary_vendor_id' => $vendor->id,
    ]);

    $this->actingAs($user);

    $client = Client::factory()->create();
    $clientUsers = collect([
        ['first_name' => 'Carri', 'last_name' => 'Jones'],
        ['first_name' => 'Debra', 'last_name' => 'Jones'],
        ['first_name' => 'Alan', 'last_name' => 'Jones'],
    ])->map(function (array $attributes) {
        return User::query()->create(array_merge($attributes, [
            'email' => strtolower($attributes['first_name']) . '.client.schedule@example.com',
            'cell_phone' => fake()->unique()->numerify('224555####'),
            'primary_vendor_id' => null,
        ]));
    });

    $client->users()->attach($clientUsers->pluck('id')->all());

    $project = Project::query()->create([
        'project_name' => 'Client Schedule Project',
        'client_id' => $client->id,
        'address' => '100 Main St',
        'city' => 'Cary',
        'state' => 'IL',
        'zip_code' => 60013,
        'belongs_to_vendor_id' => $vendor->id,
    ]);

    Task::query()->create([
        'title' => 'Demo',
        'project_id' => $project->id,
        'vendor_id' => $vendor->id,
        'type' => 'Task',
        'start_date' => today(),
        'end_date' => today(),
    ]);

    Task::query()->create([
        'title' => 'Carpentry',
        'project_id' => $project->id,
        'vendor_id' => $vendor->id,
        'type' => 'Task',
    ]);

    $thread = SmsGroupThread::query()->create([
        'name' => 'Client Schedule Thread',
        'from_number' => '+12245554444',
        'participants' => ['+12245550001'],
        'vendor_id' => $vendor->id,
        'client_id' => $client->id,
        'last_activity_at' => now(),
    ]);

    $preview = Livewire::test(SendScheduleModal::class)
        ->call('open', $thread->id)
        ->get('previewMessage');

    expect($preview)->toStartWith('Hi Carri, Debra & Alan,')
        ->and($preview)->toContain('Upcoming tasks:')
        ->and($preview)->toContain('Pending:')
        ->and($preview)->toContain('View schedule:')
        ->and($preview)->not->toContain('Confirm Tasks:')
        ->and($preview)->not->toContain('Confirm Schedule:');
});

it('uses client nickname in schedule greeting', function (): void {
    $vendor = Vendor::factory()->create(['business_name' => 'GS Construction']);

    $user = User::query()->create([
        'first_name' => 'Owner',
        'last_name' => 'User',
        'email' => 'owner.schedule-modal-client-nickname@example.com',
        'cell_phone' => '2245550197',
        'primary_vendor_id' => $vendor->id,
    ]);

    $this->actingAs($user);

    $client = Client::factory()->create();
    $bonnie = User::query()->create([
        'first_name' => 'Bonnie',
        'last_name' => 'Bates',
        'email' => 'bonnie.client.schedule@example.com',
        'cell_phone' => '2245554011',
        'primary_vendor_id' => null,
    ]);
    $bradley = User::query()->create([
        'first_name' => 'Bradley',
        'nickname' => 'Brad',
        'last_name' => 'Bates',
        'email' => 'brad.client.schedule@example.com',
        'cell_phone' => '2245554012',
        'primary_vendor_id' => null,
    ]);
    $client->users()->attach([$bonnie->id, $bradley->id]);

    $project = Project::query()->create([
        'project_name' => 'Client Nickname Schedule Project',
        'client_id' => $client->id,
        'address' => '100 Main St',
        'city' => 'Cary',
        'state' => 'IL',
        'zip_code' => 60013,
        'belongs_to_vendor_id' => $vendor->id,
    ]);

    Task::query()->create([
        'title' => 'Demo',
        'project_id' => $project->id,
        'vendor_id' => $vendor->id,
        'type' => 'Task',
        'start_date' => today(),
        'end_date' => today(),
    ]);

    $thread = SmsGroupThread::query()->create([
        'name' => 'Bonnie & Bradley Bates',
        'from_number' => '+12245554444',
        'participants' => ['+12245554011', '+12245554012'],
        'vendor_id' => $vendor->id,
        'client_id' => $client->id,
        'last_activity_at' => now(),
    ]);

    $preview = Livewire::test(SendScheduleModal::class)
        ->call('open', $thread->id)
        ->get('previewMessage');

    expect($preview)->toStartWith('Hi Bonnie & Brad,')
        ->and($preview)->not->toContain('Hi Bonnie & Bradley,');
});

it('uses vendor user nickname and preferred language when vendor short name is missing', function (): void {
    $ownerVendor = Vendor::factory()->create([
        'business_name' => 'GS Construction',
    ]);

    $ownerUser = User::query()->create([
        'first_name' => 'Owner',
        'last_name' => 'User',
        'preferred_language' => 'Polish',
        'email' => 'owner.schedule-modal-language@example.com',
        'cell_phone' => '2245550198',
        'primary_vendor_id' => $ownerVendor->id,
    ]);

    $this->actingAs($ownerUser);

    $subjectVendor = Vendor::factory()->create([
        'business_name' => 'RG Tile',
    ]);
    $subjectVendor->short_name = null;
    $subjectVendor->save();

    $subjectUser = User::query()->create([
        'first_name' => 'Grzegorz',
        'last_name' => 'Szady',
        'nickname' => 'Gresiek',
        'preferred_language' => 'Polish',
        'email' => 'gresiek.vendor-thread@example.com',
        'cell_phone' => '2245550002',
        'primary_vendor_id' => $subjectVendor->id,
    ]);

    $subjectVendor->users()->attach($subjectUser->id, [
        'is_employed' => true,
        'role_id' => 1,
    ]);

    $client = Client::factory()->create();
    $project = Project::query()->create([
        'project_name' => 'Vendor Thread Project',
        'client_id' => $client->id,
        'address' => '3154 Violet Ln',
        'city' => 'Northbrook',
        'state' => 'IL',
        'zip_code' => 60062,
        'belongs_to_vendor_id' => $ownerVendor->id,
    ]);

    Task::query()->create([
        'title' => 'Foundation',
        'project_id' => $project->id,
        'vendor_id' => $subjectVendor->id,
        'type' => 'Task',
        'start_date' => today(),
        'end_date' => today(),
    ]);

    $thread = SmsGroupThread::query()->create([
        'name' => 'Vendor Thread',
        'from_number' => '+12245554444',
        'participants' => ['+12245550002'],
        'vendor_id' => $ownerVendor->id,
        'subject_vendor_id' => $subjectVendor->id,
        'last_activity_at' => now(),
    ]);

    $preview = Livewire::test(SendScheduleModal::class)
        ->call('open', $thread->id)
        ->get('previewMessage');

    $expectedTodayHeading = 'Today ' . today()->format('D m/d') . ':';

    expect($preview)->toStartWith('Hi Rg Tile,')
        ->and($preview)->toContain('Upcoming tasks:')
        ->and($preview)->toContain('Confirm Schedule:')
        ->and($preview)->toContain($expectedTodayHeading)
        ->and($preview)->not->toContain('Potwierdz zadania:')
        ->and($preview)->not->toContain('Potwierdz plan:');
});

it('renders schedule modal task cards as clickable edit actions', function (): void {
    $ownerVendor = Vendor::factory()->create(['business_name' => 'GS Construction']);

    $ownerUser = User::query()->create([
        'first_name' => 'Owner',
        'last_name' => 'User',
        'email' => 'owner.schedule-modal-click@example.com',
        'cell_phone' => '2245550199',
        'primary_vendor_id' => $ownerVendor->id,
    ]);

    $this->actingAs($ownerUser);

    $subjectVendor = Vendor::factory()->create(['business_name' => 'Smartech Electric']);
    $subjectVendor->short_name = 'Smartech';
    $subjectVendor->save();

    $client = Client::factory()->create();
    $project = Project::query()->create([
        'project_name' => 'Vendor Thread Project',
        'client_id' => $client->id,
        'address' => '3154 Violet Ln',
        'city' => 'Northbrook',
        'state' => 'IL',
        'zip_code' => 60062,
        'belongs_to_vendor_id' => $ownerVendor->id,
    ]);

    Task::query()->create([
        'title' => 'Clickable Task',
        'project_id' => $project->id,
        'vendor_id' => $subjectVendor->id,
        'type' => 'Task',
        'start_date' => today(),
        'end_date' => today(),
    ]);

    $thread = SmsGroupThread::query()->create([
        'name' => 'Vendor Thread',
        'from_number' => '+12245554444',
        'participants' => ['+12245550001'],
        'vendor_id' => $ownerVendor->id,
        'subject_vendor_id' => $subjectVendor->id,
        'last_activity_at' => now(),
    ]);

    Livewire::test(SendScheduleModal::class)
        ->call('open', $thread->id)
        ->assertSeeHtml("tasks.task-create', 'editTask'");
});

it('pads the scrollable task list so task card borders are not clipped', function (): void {
    $ownerVendor = Vendor::factory()->create(['business_name' => 'GS Construction']);

    $ownerUser = User::query()->create([
        'first_name' => 'Owner',
        'last_name' => 'User',
        'email' => 'owner.schedule-modal-padding@example.com',
        'cell_phone' => '2245550188',
        'primary_vendor_id' => $ownerVendor->id,
    ]);

    $this->actingAs($ownerUser);

    $subjectVendor = Vendor::factory()->create(['business_name' => 'Smartech Electric']);
    $subjectVendor->short_name = 'Smartech';
    $subjectVendor->save();

    $client = Client::factory()->create();
    $project = Project::query()->create([
        'project_name' => 'Vendor Thread Project',
        'client_id' => $client->id,
        'address' => '3154 Violet Ln',
        'city' => 'Northbrook',
        'state' => 'IL',
        'zip_code' => 60062,
        'belongs_to_vendor_id' => $ownerVendor->id,
    ]);

    Task::query()->create([
        'title' => 'Pending Task',
        'project_id' => $project->id,
        'vendor_id' => $subjectVendor->id,
        'type' => 'Task',
    ]);

    $thread = SmsGroupThread::query()->create([
        'name' => 'Vendor Thread',
        'from_number' => '+12245554444',
        'participants' => ['+12245550001'],
        'vendor_id' => $ownerVendor->id,
        'subject_vendor_id' => $subjectVendor->id,
        'last_activity_at' => now(),
    ]);

    Livewire::test(SendScheduleModal::class)
        ->call('open', $thread->id)
        ->assertSeeHtml('overflow-y-auto px-1 py-1');
});

it('shows a Later section for tasks scheduled beyond the next-up day', function (): void {
    $vendor = Vendor::factory()->create(['business_name' => 'GS Construction']);

    $user = User::query()->create([
        'first_name' => 'Owner',
        'last_name' => 'User',
        'email' => 'owner.schedule-modal-later@example.com',
        'cell_phone' => '2245550177',
        'primary_vendor_id' => $vendor->id,
    ]);

    $this->actingAs($user);

    $client = Client::factory()->create();
    $project = Project::query()->create([
        'project_name' => 'Later Tasks Project',
        'client_id' => $client->id,
        'address' => '100 Main St',
        'city' => 'Cary',
        'state' => 'IL',
        'zip_code' => 60013,
        'belongs_to_vendor_id' => $vendor->id,
    ]);

    // Next-up task: first future day beyond the 3-day preview window.
    Task::query()->create([
        'title' => 'Next Up Task',
        'project_id' => $project->id,
        'vendor_id' => $vendor->id,
        'type' => 'Task',
        'start_date' => today()->addDays(4),
        'end_date' => today()->addDays(4),
    ]);

    // Later task: several days beyond the next-up day.
    Task::query()->create([
        'title' => 'Later Task',
        'project_id' => $project->id,
        'vendor_id' => $vendor->id,
        'type' => 'Task',
        'start_date' => today()->addDays(7),
        'end_date' => today()->addDays(7),
    ]);

    $thread = SmsGroupThread::query()->create([
        'name' => 'Client Thread',
        'from_number' => '+12245554444',
        'participants' => ['+12245550001'],
        'vendor_id' => $vendor->id,
        'client_id' => $client->id,
        'last_activity_at' => now(),
    ]);

    Livewire::test(SendScheduleModal::class)
        ->call('open', $thread->id)
        ->assertSee('Next Up Task')
        ->assertSee('Later Task')
        ->assertSee('Next task in');
});

it('includes a Later summary line in the schedule message', function (): void {
    $vendor = Vendor::factory()->create(['business_name' => 'GS Construction']);

    $user = User::query()->create([
        'first_name' => 'Owner',
        'last_name' => 'User',
        'email' => 'owner.schedule-modal-later-msg@example.com',
        'cell_phone' => '2245550166',
        'primary_vendor_id' => $vendor->id,
    ]);

    $this->actingAs($user);

    $client = Client::factory()->create();
    $project = Project::query()->create([
        'project_name' => 'Later Message Project',
        'client_id' => $client->id,
        'address' => '100 Main St',
        'city' => 'Cary',
        'state' => 'IL',
        'zip_code' => 60013,
        'belongs_to_vendor_id' => $vendor->id,
    ]);

    // Next-up task: first future day beyond the 3-day preview window.
    Task::query()->create([
        'title' => 'Next Up Task',
        'project_id' => $project->id,
        'vendor_id' => $vendor->id,
        'type' => 'Task',
        'start_date' => today()->addDays(4),
        'end_date' => today()->addDays(4),
    ]);

    // Two later tasks on different days beyond the next-up day.
    Task::query()->create([
        'title' => 'Later A',
        'project_id' => $project->id,
        'vendor_id' => $vendor->id,
        'type' => 'Task',
        'start_date' => today()->addDays(7),
        'end_date' => today()->addDays(7),
    ]);

    Task::query()->create([
        'title' => 'Later B',
        'project_id' => $project->id,
        'vendor_id' => $vendor->id,
        'type' => 'Task',
        'start_date' => today()->addDays(9),
        'end_date' => today()->addDays(9),
    ]);

    $thread = SmsGroupThread::query()->create([
        'name' => 'Client Thread',
        'from_number' => '+12245554444',
        'participants' => ['+12245550001'],
        'vendor_id' => $vendor->id,
        'client_id' => $client->id,
        'last_activity_at' => now(),
    ]);

    $preview = Livewire::test(SendScheduleModal::class)
        ->call('open', $thread->id)
        ->get('previewMessage');

    expect($preview)
        ->toContain('Next up')
        ->toContain('Later (2 tasks)')
        ->not->toContain('Upcoming tasks:');
});

it('refreshes editable schedule message text after task updates', function (): void {
    $ownerVendor = Vendor::factory()->create(['business_name' => 'GS Construction']);

    $ownerUser = User::query()->create([
        'first_name' => 'Owner',
        'last_name' => 'User',
        'email' => 'owner.schedule-modal-refresh@example.com',
        'cell_phone' => '2245550299',
        'primary_vendor_id' => $ownerVendor->id,
    ]);

    $this->actingAs($ownerUser);

    $subjectVendor = Vendor::factory()->create(['business_name' => 'Smartech Electric']);
    $subjectVendor->short_name = 'Smartech';
    $subjectVendor->save();

    $client = Client::factory()->create();
    $project = Project::query()->create([
        'project_name' => 'Vendor Thread Project',
        'client_id' => $client->id,
        'address' => '3154 Violet Ln',
        'city' => 'Northbrook',
        'state' => 'IL',
        'zip_code' => 60062,
        'belongs_to_vendor_id' => $ownerVendor->id,
    ]);

    $task = Task::query()->create([
        'title' => 'Before Update Title',
        'project_id' => $project->id,
        'vendor_id' => $subjectVendor->id,
        'type' => 'Task',
        'start_date' => today(),
        'end_date' => today(),
    ]);

    $thread = SmsGroupThread::query()->create([
        'name' => 'Vendor Thread',
        'from_number' => '+12245554444',
        'participants' => ['+12245550001'],
        'vendor_id' => $ownerVendor->id,
        'subject_vendor_id' => $subjectVendor->id,
        'last_activity_at' => now(),
    ]);

    $component = Livewire::test(SendScheduleModal::class)
        ->call('open', $thread->id);

    expect($component->get('editableMessage'))->toContain('Before Update Title');

    $task->update(['title' => 'After Update Title']);

    $component
        ->dispatch('refreshSchedulePreview')
        ->assertSet('editableMessage', $component->instance()->previewMessage);

    expect($component->get('editableMessage'))->toContain('After Update Title');
});

it('hides reminder tasks in vendor schedule previews', function (): void {
    $ownerVendor = Vendor::factory()->create(['business_name' => 'GS Construction']);

    $ownerUser = User::query()->create([
        'first_name' => 'Owner',
        'last_name' => 'User',
        'email' => 'owner.schedule-modal-reminders@example.com',
        'cell_phone' => '2245550188',
        'primary_vendor_id' => $ownerVendor->id,
    ]);

    $this->actingAs($ownerUser);

    $subjectVendor = Vendor::factory()->create(['business_name' => 'RG Tile']);
    $subjectVendor->short_name = 'RG Tile';
    $subjectVendor->save();

    $client = Client::factory()->create();
    $project = Project::query()->create([
        'project_name' => 'Vendor Reminder Filter Project',
        'client_id' => $client->id,
        'address' => '999 Main St',
        'city' => 'Palatine',
        'state' => 'IL',
        'zip_code' => 60067,
        'belongs_to_vendor_id' => $ownerVendor->id,
    ]);

    Task::query()->create([
        'title' => 'Install Tile',
        'project_id' => $project->id,
        'vendor_id' => $subjectVendor->id,
        'belongs_to_vendor_id' => $ownerVendor->id,
        'type' => 'Task',
        'start_date' => today(),
        'end_date' => today(),
    ]);

    Task::withoutEvents(function () use ($project, $subjectVendor, $ownerVendor, $ownerUser): void {
        Task::query()->create([
            'title' => 'Vendor Internal Reminder',
            'project_id' => $project->id,
            'vendor_id' => $subjectVendor->id,
            'belongs_to_vendor_id' => $subjectVendor->id,
            'created_by_user_id' => $ownerUser->id,
            'order' => 10,
            'type' => 'Reminder',
            'start_date' => today(),
            'end_date' => today(),
        ]);

        Task::query()->create([
            'title' => 'GS Reminder',
            'project_id' => $project->id,
            'vendor_id' => $subjectVendor->id,
            'belongs_to_vendor_id' => $ownerVendor->id,
            'created_by_user_id' => $ownerUser->id,
            'order' => 11,
            'type' => 'Reminder',
            'start_date' => today(),
            'end_date' => today(),
        ]);
    });

    $thread = SmsGroupThread::query()->create([
        'name' => 'Vendor Reminder Thread',
        'from_number' => '+12245554444',
        'participants' => ['+12245550077'],
        'vendor_id' => $ownerVendor->id,
        'subject_vendor_id' => $subjectVendor->id,
        'last_activity_at' => now(),
    ]);

    $preview = Livewire::test(SendScheduleModal::class)
        ->call('open', $thread->id)
        ->get('previewMessage');

    expect($preview)
        ->toContain('Install Tile')
        ->not->toContain('GS Reminder')
        ->not->toContain('Vendor Internal Reminder');
});

it('dispatches addTask when using the date-level add-task action', function (): void {
    $ownerVendor = Vendor::factory()->create(['business_name' => 'GS Construction']);

    $ownerUser = User::query()->create([
        'first_name' => 'Owner',
        'last_name' => 'User',
        'email' => 'owner.schedule-modal-date-add@example.com',
        'cell_phone' => '2245550399',
        'primary_vendor_id' => $ownerVendor->id,
    ]);

    $this->actingAs($ownerUser);

    $subjectVendor = Vendor::factory()->create(['business_name' => 'Smartech Electric']);
    $subjectVendor->short_name = 'Smartech';
    $subjectVendor->save();

    $client = Client::factory()->create();

    $thread = SmsGroupThread::query()->create([
        'name' => 'Vendor Thread',
        'from_number' => '+12245554444',
        'participants' => ['+12245550001'],
        'vendor_id' => $ownerVendor->id,
        'client_id' => $client->id,
        'subject_vendor_id' => $subjectVendor->id,
        'last_activity_at' => now(),
    ]);

    Livewire::test(SendScheduleModal::class)
        ->call('open', $thread->id)
        ->call('openCreateTaskForDate', today()->format('Y-m-d'))
        ->assertDispatched('addTask');
});

it('dispatches addTask when using the tasks-row add action without a date', function (): void {
    $ownerVendor = Vendor::factory()->create(['business_name' => 'GS Construction']);

    $ownerUser = User::query()->create([
        'first_name' => 'Owner',
        'last_name' => 'User',
        'email' => 'owner.schedule-modal-nodate-add@example.com',
        'cell_phone' => '2245550398',
        'primary_vendor_id' => $ownerVendor->id,
    ]);

    $this->actingAs($ownerUser);

    $subjectVendor = Vendor::factory()->create(['business_name' => 'Smartech Electric']);
    $subjectVendor->short_name = 'Smartech';
    $subjectVendor->save();

    $client = Client::factory()->create();

    $thread = SmsGroupThread::query()->create([
        'name' => 'Vendor Thread',
        'from_number' => '+12245554444',
        'participants' => ['+12245550001'],
        'vendor_id' => $ownerVendor->id,
        'client_id' => $client->id,
        'subject_vendor_id' => $subjectVendor->id,
        'last_activity_at' => now(),
    ]);

    Livewire::test(SendScheduleModal::class)
        ->call('open', $thread->id)
        ->call('openCreateTask')
        ->assertDispatched('addTask');
});

it('shows the service call invite block and does not duplicate service call items in Pending', function (): void {
    $vendor = Vendor::factory()->create(['business_name' => 'GS Construction']);
    $vendor->short_name = 'GS Construction';
    $vendor->save();

    $user = User::query()->create([
        'first_name' => 'Owner',
        'last_name' => 'User',
        'email' => 'owner.schedule-modal-servicecall@example.com',
        'cell_phone' => '2245550144',
        'primary_vendor_id' => $vendor->id,
    ]);

    $this->actingAs($user);

    $client = Client::factory()->create();
    $project = Project::query()->create([
        'project_name' => 'Service Call Project',
        'client_id' => $client->id,
        'address' => '100 Main St',
        'city' => 'Cary',
        'state' => 'IL',
        'zip_code' => 60013,
        'belongs_to_vendor_id' => $vendor->id,
    ]);

    ProjectStatus::withoutEvents(fn () => ProjectStatus::create([
        'project_id' => $project->id,
        'belongs_to_vendor_id' => $vendor->id,
        'status_code' => 8,
        'start_date' => now(),
    ]));

    Task::withoutEvents(fn () => Task::create([
        'title' => 'Fix Electrical Outlet',
        'type' => 'Task',
        'order' => 1,
        'project_id' => $project->id,
        'belongs_to_vendor_id' => $vendor->id,
        'created_by_user_id' => 1,
    ]));

    $thread = SmsGroupThread::query()->create([
        'name' => 'Client Thread',
        'from_number' => '+12245554444',
        'participants' => ['+12245550001'],
        'vendor_id' => $vendor->id,
        'client_id' => $client->id,
        'last_activity_at' => now(),
    ]);

    $preview = Livewire::test(SendScheduleModal::class)
        ->call('open', $thread->id)
        ->get('previewMessage');

    expect($preview)
        ->toContain("Share availability with GS Construction for this service call:")
        ->toContain('- Fix Electrical Outlet')
        ->toContain("- Fix Electrical Outlet\nSchedule: ")
        ->not->toContain('View schedule:')
        ->not->toContain("Pending:\n- Fix Electrical Outlet")
        ->not->toContain('(Share availability)');

    expect(strpos($preview, "Share availability with"))
        ->toBeLessThan(strpos($preview, 'Schedule:'));

    expect(strpos($preview, '- Fix Electrical Outlet'))
        ->toBeLessThan(strpos($preview, 'Schedule:'));

    expect(substr_count($preview, 'Schedule:'))
        ->toBe(1);

    expect($preview)->not->toContain('Upcoming tasks:');
});

it('invites the client to pick consult times when a Meet is pending on an Estimate project', function (): void {
    $vendor = Vendor::factory()->create(['business_name' => 'GS Construction']);
    $vendor->short_name = 'GS Construction';
    $vendor->save();

    $user = User::query()->create([
        'first_name' => 'Owner',
        'last_name' => 'User',
        'email' => 'owner.schedule-modal-consult@example.com',
        'cell_phone' => '2245550188',
        'primary_vendor_id' => $vendor->id,
    ]);

    $this->actingAs($user);

    $contact = User::query()->create([
        'first_name' => 'Amy',
        'last_name' => 'Sepiol',
        'email' => 'amy.consult-invite@example.com',
        'cell_phone' => '2245550189',
    ]);

    $client = Client::factory()->create();
    $client->users()->attach($contact->id);

    $project = Project::query()->create([
        'project_name' => 'Basement Stairs',
        'client_id' => $client->id,
        'address' => '100 Main St',
        'city' => 'Cary',
        'state' => 'IL',
        'zip_code' => 60013,
        'belongs_to_vendor_id' => $vendor->id,
    ]);

    // Estimate — the consult hasn't been scheduled yet.
    ProjectStatus::withoutEvents(fn () => ProjectStatus::create([
        'project_id' => $project->id,
        'belongs_to_vendor_id' => $vendor->id,
        'status_code' => 2,
        'start_date' => now(),
    ]));

    Task::withoutEvents(fn () => Task::create([
        'title' => 'GS/Sepiol Consult',
        'type' => 'Meet',
        'order' => 1,
        'project_id' => $project->id,
        'belongs_to_vendor_id' => $vendor->id,
        'created_by_user_id' => $user->id,
    ]));

    $lead = Lead::create([
        'date' => now(),
        'origin' => 'gs.construction',
        'user_id' => $contact->id,
        'belongs_to_vendor_id' => $vendor->id,
        'created_by_user_id' => $contact->id,
        'lead_data' => ['name' => 'Amy Sepiol', 'message' => 'Basement stairs'],
    ]);

    $thread = SmsGroupThread::query()->create([
        'name' => 'Client Thread',
        'from_number' => '+12245554444',
        'participants' => ['+12245550001'],
        'vendor_id' => $vendor->id,
        'client_id' => $client->id,
        'last_activity_at' => now(),
    ]);

    $preview = Livewire::test(SendScheduleModal::class)
        ->call('open', $thread->id)
        ->get('previewMessage');

    expect($preview)
        ->toContain('Pick a consultation time with GS Construction:')
        ->toContain('- GS/Sepiol Consult')
        ->toContain('Times: ')
        // The consult is the invite, not a dead "Pending" line.
        ->not->toContain("Pending:\n- GS/Sepiol Consult");

    // The link resolves to that lead's signed pick-times page — through the
    // shortener when it's enabled, otherwise the signed URL itself.
    $link = trim(Str::after($preview, 'Times: '));
    $destination = ShortLink::where('code', Str::afterLast($link, '/'))->value('destination') ?? $link;

    expect($destination)
        ->toContain('/lead/times/'.$lead->id)
        ->toContain('signature=');
});

it('keeps a pending Meet in Pending when the project is past Estimate', function (): void {
    $vendor = Vendor::factory()->create(['business_name' => 'GS Construction']);
    $vendor->short_name = 'GS Construction';
    $vendor->save();

    $user = User::query()->create([
        'first_name' => 'Owner',
        'last_name' => 'User',
        'email' => 'owner.schedule-modal-consult-active@example.com',
        'cell_phone' => '2245550190',
        'primary_vendor_id' => $vendor->id,
    ]);

    $this->actingAs($user);

    $contact = User::query()->create([
        'first_name' => 'Amy',
        'last_name' => 'Sepiol',
        'email' => 'amy.consult-active@example.com',
        'cell_phone' => '2245550191',
    ]);

    $client = Client::factory()->create();
    $client->users()->attach($contact->id);

    $project = Project::query()->create([
        'project_name' => 'Basement Stairs',
        'client_id' => $client->id,
        'address' => '100 Main St',
        'city' => 'Cary',
        'state' => 'IL',
        'zip_code' => 60013,
        'belongs_to_vendor_id' => $vendor->id,
    ]);

    ProjectStatus::withoutEvents(fn () => ProjectStatus::create([
        'project_id' => $project->id,
        'belongs_to_vendor_id' => $vendor->id,
        'status_code' => 6,
        'start_date' => now(),
    ]));

    Task::withoutEvents(fn () => Task::create([
        'title' => 'Walkthrough',
        'type' => 'Meet',
        'order' => 1,
        'project_id' => $project->id,
        'belongs_to_vendor_id' => $vendor->id,
        'created_by_user_id' => $user->id,
    ]));

    Lead::create([
        'date' => now(),
        'origin' => 'gs.construction',
        'user_id' => $contact->id,
        'belongs_to_vendor_id' => $vendor->id,
        'created_by_user_id' => $contact->id,
        'lead_data' => ['name' => 'Amy Sepiol', 'message' => 'Basement stairs'],
    ]);

    $thread = SmsGroupThread::query()->create([
        'name' => 'Client Thread',
        'from_number' => '+12245554444',
        'participants' => ['+12245550001'],
        'vendor_id' => $vendor->id,
        'client_id' => $client->id,
        'last_activity_at' => now(),
    ]);

    $preview = Livewire::test(SendScheduleModal::class)
        ->call('open', $thread->id)
        ->get('previewMessage');

    expect($preview)
        ->not->toContain('Pick a consultation time')
        ->toContain('- Walkthrough');
});

it('uses plural service call wording when multiple service call tasks exist', function (): void {
    $vendor = Vendor::factory()->create(['business_name' => 'GS Construction']);
    $vendor->short_name = 'GS Construction';
    $vendor->save();

    $user = User::query()->create([
        'first_name' => 'Owner',
        'last_name' => 'User',
        'email' => 'owner.schedule-modal-servicecall-multiple@example.com',
        'cell_phone' => '2245550145',
        'primary_vendor_id' => $vendor->id,
    ]);

    $this->actingAs($user);

    $client = Client::factory()->create();
    $project = Project::query()->create([
        'project_name' => 'Service Call Project',
        'client_id' => $client->id,
        'address' => '100 Main St',
        'city' => 'Cary',
        'state' => 'IL',
        'zip_code' => 60013,
        'belongs_to_vendor_id' => $vendor->id,
    ]);

    ProjectStatus::withoutEvents(fn () => ProjectStatus::create([
        'project_id' => $project->id,
        'belongs_to_vendor_id' => $vendor->id,
        'status_code' => 8,
        'start_date' => now(),
    ]));

    Task::withoutEvents(fn () => Task::create([
        'title' => 'Fix Electrical Outlet',
        'type' => 'Task',
        'order' => 1,
        'project_id' => $project->id,
        'belongs_to_vendor_id' => $vendor->id,
        'created_by_user_id' => 1,
    ]));

    Task::withoutEvents(fn () => Task::create([
        'title' => 'Inspect Breaker Panel',
        'type' => 'Task',
        'order' => 2,
        'project_id' => $project->id,
        'belongs_to_vendor_id' => $vendor->id,
        'created_by_user_id' => 1,
    ]));

    $thread = SmsGroupThread::query()->create([
        'name' => 'Client Thread',
        'from_number' => '+12245554444',
        'participants' => ['+12245550001'],
        'vendor_id' => $vendor->id,
        'client_id' => $client->id,
        'last_activity_at' => now(),
    ]);

    $preview = Livewire::test(SendScheduleModal::class)
        ->call('open', $thread->id)
        ->get('previewMessage');

    expect($preview)
        ->toContain("Share availability with GS Construction for these service calls:")
        ->toContain('- Fix Electrical Outlet')
        ->toContain('- Inspect Breaker Panel')
        ->toContain("- Inspect Breaker Panel\nSchedule: ")
        ->not->toContain('View schedule:');

    expect(strpos($preview, '- Inspect Breaker Panel'))
        ->toBeLessThan(strpos($preview, 'Schedule:'));

    expect(substr_count($preview, 'Schedule:'))
        ->toBe(1);
});

it('does not add share availability text for non service call client threads', function (): void {
    $vendor = Vendor::factory()->create(['business_name' => 'GS Construction']);

    $user = User::query()->create([
        'first_name' => 'Owner',
        'last_name' => 'User',
        'email' => 'owner.schedule-modal-non-servicecall@example.com',
        'cell_phone' => '2245550133',
        'primary_vendor_id' => $vendor->id,
    ]);

    $this->actingAs($user);

    $client = Client::factory()->create();
    $project = Project::query()->create([
        'project_name' => 'Active Project',
        'client_id' => $client->id,
        'address' => '100 Main St',
        'city' => 'Cary',
        'state' => 'IL',
        'zip_code' => 60013,
        'belongs_to_vendor_id' => $vendor->id,
    ]);

    ProjectStatus::withoutEvents(fn () => ProjectStatus::create([
        'project_id' => $project->id,
        'belongs_to_vendor_id' => $vendor->id,
        'status_code' => 6,
        'start_date' => now(),
    ]));

    Task::withoutEvents(fn () => Task::create([
        'title' => 'Fix Electrical Outlet',
        'type' => 'Task',
        'order' => 1,
        'project_id' => $project->id,
        'belongs_to_vendor_id' => $vendor->id,
        'created_by_user_id' => 1,
    ]));

    $thread = SmsGroupThread::query()->create([
        'name' => 'Client Thread',
        'from_number' => '+12245554444',
        'participants' => ['+12245550001'],
        'vendor_id' => $vendor->id,
        'client_id' => $client->id,
        'last_activity_at' => now(),
    ]);

    $preview = Livewire::test(SendScheduleModal::class)
        ->call('open', $thread->id)
        ->get('previewMessage');

    expect($preview)
        ->not->toContain('(Share availability)')
        ->not->toContain('Share times with');
});

it('sets vendor status to requested only after sending the vendor schedule message', function (): void {
    $ownerVendor = Vendor::factory()->create(['business_name' => 'GS Construction']);

    $ownerUser = User::query()->create([
        'first_name' => 'Owner',
        'last_name' => 'User',
        'email' => 'owner.schedule-modal-send@example.com',
        'cell_phone' => '2245550499',
        'primary_vendor_id' => $ownerVendor->id,
    ]);

    $this->actingAs($ownerUser);

    $subjectVendor = Vendor::factory()->create(['business_name' => 'RG Tile']);
    $subjectVendor->short_name = 'RG';
    $subjectVendor->save();

    $client = Client::factory()->create();
    $project = Project::query()->create([
        'project_name' => 'Vendor Thread Project',
        'client_id' => $client->id,
        'address' => '3154 Violet Ln',
        'city' => 'Northbrook',
        'state' => 'IL',
        'zip_code' => 60062,
        'belongs_to_vendor_id' => $ownerVendor->id,
    ]);

    $task = Task::query()->create([
        'title' => 'Status Timing Task',
        'project_id' => $project->id,
        'vendor_id' => $subjectVendor->id,
        'type' => 'Task',
        'start_date' => today(),
        'end_date' => today(),
    ]);

    expect($task->fresh()->vendor_status)->toBeNull();

    $thread = SmsGroupThread::query()->create([
        'name' => 'Vendor Thread',
        'from_number' => '+12245554444',
        'participants' => ['+12245550001'],
        'vendor_id' => $ownerVendor->id,
        'subject_vendor_id' => $subjectVendor->id,
        'last_activity_at' => now(),
    ]);

    $thread->threadParticipants()->create([
        'phone_number' => '+12245550001',
        'opted_in_at' => now(),
    ]);

    Livewire::test(SendScheduleModal::class)
        ->call('open', $thread->id)
        ->set('editableMessage', 'Hi RG, Confirm Tasks:')
        ->call('send');

    expect($task->fresh()->vendor_status)->toBe(Task::VENDOR_STATUS_REQUESTED);
});

it('creates a no-date scheduled draft and does not mark vendor status requested yet', function (): void {
    $ownerVendor = Vendor::factory()->create(['business_name' => 'GS Construction']);

    $ownerUser = User::query()->create([
        'first_name' => 'Owner',
        'last_name' => 'User',
        'email' => 'owner.schedule-modal-no-date@example.com',
        'cell_phone' => '2245550599',
        'primary_vendor_id' => $ownerVendor->id,
    ]);

    $this->actingAs($ownerUser);

    $subjectVendor = Vendor::factory()->create(['business_name' => 'RG Tile']);
    $subjectVendor->short_name = 'RG';
    $subjectVendor->save();

    $client = Client::factory()->create();
    $project = Project::query()->create([
        'project_name' => 'Vendor Thread Project',
        'client_id' => $client->id,
        'address' => '3154 Violet Ln',
        'city' => 'Northbrook',
        'state' => 'IL',
        'zip_code' => 60062,
        'belongs_to_vendor_id' => $ownerVendor->id,
    ]);

    $task = Task::query()->create([
        'title' => 'No Date Scheduling Task',
        'project_id' => $project->id,
        'vendor_id' => $subjectVendor->id,
        'type' => 'Task',
        'start_date' => today(),
        'end_date' => today(),
    ]);

    $thread = SmsGroupThread::query()->create([
        'name' => 'Vendor Thread',
        'from_number' => '+12245554444',
        'participants' => ['+12245550001'],
        'vendor_id' => $ownerVendor->id,
        'subject_vendor_id' => $subjectVendor->id,
        'last_activity_at' => now(),
    ]);

    $thread->threadParticipants()->create([
        'phone_number' => '+12245550001',
        'opted_in_at' => now(),
    ]);

    Livewire::test(SendScheduleModal::class)
        ->call('open', $thread->id)
        ->call('useNoDateSchedule')
        ->set('editableMessage', 'Hi RG, Confirm Schedule:')
        ->call('send');

    $scheduled = \App\Models\SmsMessage::query()
        ->where('thread_id', $thread->id)
        ->where('status', 'scheduled')
        ->latest('id')
        ->first();

    expect($scheduled)->not->toBeNull();
    expect($scheduled->scheduled_at)->toBeNull();
    expect($task->fresh()->vendor_status)->toBeNull();
});

it('does not auto-translate schedule modal message text on send', function (): void {
    $ownerVendor = Vendor::factory()->create(['business_name' => 'GS Construction']);

    $ownerUser = User::query()->create([
        'first_name' => 'Owner',
        'last_name' => 'User',
        'preferred_language' => 'English',
        'email' => 'owner.schedule-modal-no-translate@example.com',
        'cell_phone' => '2245550899',
        'primary_vendor_id' => $ownerVendor->id,
    ]);

    $this->actingAs($ownerUser);

    $subjectVendor = Vendor::factory()->create(['business_name' => 'RG Tile']);
    $subjectVendor->short_name = 'RG Tile';
    $subjectVendor->save();

    $subjectUser = User::query()->create([
        'first_name' => 'Grzegorz',
        'last_name' => 'Szady',
        'preferred_language' => 'Polish',
        'email' => 'rg.schedule-modal-no-translate@example.com',
        'cell_phone' => '2245550898',
        'primary_vendor_id' => $subjectVendor->id,
    ]);

    $subjectVendor->users()->attach($subjectUser->id, [
        'is_employed' => true,
        'role_id' => 1,
    ]);

    $client = Client::factory()->create();
    $project = Project::query()->create([
        'project_name' => 'Primary Bath',
        'client_id' => $client->id,
        'address' => '215 W Huron St',
        'city' => 'Chicago',
        'state' => 'IL',
        'zip_code' => 60654,
        'belongs_to_vendor_id' => $ownerVendor->id,
    ]);

    Task::query()->create([
        'title' => 'Tiles',
        'project_id' => $project->id,
        'vendor_id' => $subjectVendor->id,
        'type' => 'Task',
        'start_date' => today()->addDay(),
        'end_date' => today()->addDay(),
    ]);

    $thread = SmsGroupThread::query()->create([
        'name' => 'Vendor Thread',
        'from_number' => '+12245554444',
        'participants' => ['+12245550001'],
        'vendor_id' => $ownerVendor->id,
        'subject_vendor_id' => $subjectVendor->id,
        'last_activity_at' => now(),
    ]);

    $thread->threadParticipants()->create([
        'phone_number' => '+12245550001',
        'opted_in_at' => now(),
    ]);

    $editable = "Hello RG Tile,\nUpcoming tasks:\n\nTomorrow Wednesday 07/01:\n- Tiles (Primary Bath)\n  215 W Huron St\n  Chicago, IL 60654\n\nConfirm Schedule: https://dev.hive.contractors/v/82539ea820e3a918";

    Livewire::test(SendScheduleModal::class)
        ->call('open', $thread->id)
        ->set('editableMessage', $editable)
        ->call('send');

    $sent = \App\Models\SmsMessage::query()
        ->where('thread_id', $thread->id)
        ->latest('id')
        ->first();

    expect($sent)->not->toBeNull();
    expect((string) $sent->text)->toContain('Hello RG Tile,')
        ->and((string) $sent->text)->toContain('Upcoming tasks:')
        ->and((string) $sent->text)->toContain('Confirm Schedule:')
        ->and((string) $sent->text)->not->toContain('Cześć')
        ->and((string) $sent->text)->not->toContain('Nadchodzące zadania')
        ->and((string) $sent->text)->not->toContain('Potwierdź harmonogram');
});

it('shows a multi-day task on each day in the preview window when options dates are missing', function (): void {
    $vendor = Vendor::factory()->create(['business_name' => 'GS Construction']);

    $user = User::query()->create([
        'first_name' => 'Owner',
        'last_name' => 'User',
        'email' => 'owner.schedule-modal-multiday@example.com',
        'cell_phone' => '2245550699',
        'primary_vendor_id' => $vendor->id,
    ]);

    $this->actingAs($user);

    $client = Client::factory()->create();
    $project = Project::query()->create([
        'project_name' => 'Client Schedule Project',
        'client_id' => $client->id,
        'address' => '100 Main St',
        'city' => 'Cary',
        'state' => 'IL',
        'zip_code' => 60013,
        'belongs_to_vendor_id' => $vendor->id,
    ]);

    Task::query()->create([
        'title' => 'Demo',
        'project_id' => $project->id,
        'vendor_id' => $vendor->id,
        'type' => 'Task',
        'start_date' => today(),
        'end_date' => today()->copy()->addDay(),
        'options' => [],
    ]);

    $thread = SmsGroupThread::query()->create([
        'name' => 'Client Schedule Thread',
        'from_number' => '+12245554444',
        'participants' => ['+12245550001'],
        'vendor_id' => $vendor->id,
        'client_id' => $client->id,
        'last_activity_at' => now(),
    ]);

    $component = Livewire::test(SendScheduleModal::class)
        ->call('open', $thread->id);

    $grouped = $component->get('groupedUpcomingTasks');
    $todayKey = today()->format('Y-m-d');
    $tomorrowKey = today()->copy()->addDay()->format('Y-m-d');

    expect($grouped->get($todayKey)?->pluck('title')->contains('Demo'))->toBeTrue()
        ->and($grouped->get($tomorrowKey)?->pluck('title')->contains('Demo'))->toBeTrue();
});

it('normalizes non-iso task option dates so all scheduled days are shown', function (): void {
    $vendor = Vendor::factory()->create(['business_name' => 'GS Construction']);

    $user = User::query()->create([
        'first_name' => 'Owner',
        'last_name' => 'User',
        'email' => 'owner.schedule-modal-date-format@example.com',
        'cell_phone' => '2245550799',
        'primary_vendor_id' => $vendor->id,
    ]);

    $this->actingAs($user);

    $client = Client::factory()->create();
    $project = Project::query()->create([
        'project_name' => 'Client Schedule Project',
        'client_id' => $client->id,
        'address' => '100 Main St',
        'city' => 'Cary',
        'state' => 'IL',
        'zip_code' => 60013,
        'belongs_to_vendor_id' => $vendor->id,
    ]);

    $todayKey = today()->format('Y-m-d');
    $tomorrowKey = today()->copy()->addDay()->format('Y-m-d');

    Task::query()->create([
        'title' => 'Demo',
        'project_id' => $project->id,
        'vendor_id' => $vendor->id,
        'type' => 'Task',
        'start_date' => today(),
        'end_date' => today(),
        'options' => [
            'dates' => [$todayKey, today()->copy()->addDay()->format('n/j/y')],
        ],
    ]);

    $thread = SmsGroupThread::query()->create([
        'name' => 'Client Schedule Thread',
        'from_number' => '+12245554444',
        'participants' => ['+12245550001'],
        'vendor_id' => $vendor->id,
        'client_id' => $client->id,
        'last_activity_at' => now(),
    ]);

    $component = Livewire::test(SendScheduleModal::class)
        ->call('open', $thread->id);

    $grouped = $component->get('groupedUpcomingTasks');

    expect($grouped->get($todayKey)?->pluck('title')->contains('Demo'))->toBeTrue()
        ->and($grouped->get($tomorrowKey)?->pluck('title')->contains('Demo'))->toBeTrue();
});

it('includes carry-over multi-day tasks in next up when they are scheduled on the next-up day', function (): void {
    $vendor = Vendor::factory()->create(['business_name' => 'GS Construction']);

    $user = User::query()->create([
        'first_name' => 'Owner',
        'last_name' => 'User',
        'email' => 'owner.schedule-modal-next-up@example.com',
        'cell_phone' => '2245550899',
        'primary_vendor_id' => $vendor->id,
    ]);

    $this->actingAs($user);

    $client = Client::factory()->create();
    $project = Project::query()->create([
        'project_name' => 'Client Schedule Project',
        'client_id' => $client->id,
        'address' => '100 Main St',
        'city' => 'Cary',
        'state' => 'IL',
        'zip_code' => 60013,
        'belongs_to_vendor_id' => $vendor->id,
    ]);

    $dayThree = today()->copy()->addDays(2);
    $dayFour = today()->copy()->addDays(3);

    Task::query()->create([
        'title' => 'Demo',
        'project_id' => $project->id,
        'vendor_id' => $vendor->id,
        'type' => 'Task',
        'start_date' => $dayThree,
        'end_date' => $dayFour,
        'options' => [
            'dates' => [$dayThree->format('Y-m-d'), $dayFour->format('Y-m-d')],
            'time_settings' => [
                $dayThree->format('Y-m-d') => ['use_time' => true, 'start_time' => '08:00', 'end_time' => '08:00'],
                $dayFour->format('Y-m-d') => ['use_time' => true, 'start_time' => '08:00', 'end_time' => '08:00'],
            ],
        ],
    ]);

    Task::query()->create([
        'title' => 'Measure Windows',
        'project_id' => $project->id,
        'vendor_id' => $vendor->id,
        'type' => 'Task',
        'start_date' => $dayFour,
        'end_date' => $dayFour,
        'options' => [
            'dates' => [$dayFour->format('Y-m-d')],
        ],
    ]);

    $thread = SmsGroupThread::query()->create([
        'name' => 'Client Schedule Thread',
        'from_number' => '+12245554444',
        'participants' => ['+12245550001'],
        'vendor_id' => $vendor->id,
        'client_id' => $client->id,
        'last_activity_at' => now(),
    ]);

    $component = Livewire::test(SendScheduleModal::class)
        ->call('open', $thread->id);

    $nextDate = $component->get('nextUpcomingDate');
    $nextTitles = $component->get('nextUpcomingTasks')->pluck('title')->values()->all();

    expect($nextDate)->toBe($dayFour->format('Y-m-d'))
        ->and($nextTitles)->toContain('Demo')
        ->and($nextTitles)->toContain('Measure Windows');
});

it('hides today tasks whose time window has already passed', function (): void {
    \Illuminate\Support\Carbon::setTestNow(
        \Illuminate\Support\Carbon::today(config('app.timezone'))->setTime(14, 0)
    );

    $ownerVendor = Vendor::factory()->create(['business_name' => 'GS Construction']);
    $ownerUser = User::query()->create([
        'first_name' => 'Owner',
        'last_name' => 'User',
        'email' => 'owner.passed-times@example.com',
        'cell_phone' => '2245550099',
        'primary_vendor_id' => $ownerVendor->id,
    ]);
    $this->actingAs($ownerUser);

    $client = Client::factory()->create();
    $project = Project::query()->create([
        'project_name' => 'Passed Time Project',
        'client_id' => $client->id,
        'address' => '1 Main St',
        'city' => 'Cary',
        'state' => 'IL',
        'zip_code' => 60013,
        'belongs_to_vendor_id' => $ownerVendor->id,
    ]);

    $todayKey = today()->format('Y-m-d');

    Task::query()->create([
        'title' => 'Roofer',
        'project_id' => $project->id,
        'type' => 'Task',
        'start_date' => today(),
        'end_date' => today(),
        'options' => [
            'dates' => [$todayKey],
            'time_settings' => [$todayKey => ['use_time' => true, 'start_time' => '07:00', 'end_time' => '07:30']],
        ],
    ]);

    Task::query()->create([
        'title' => 'Rough Inspections',
        'project_id' => $project->id,
        'type' => 'Task',
        'start_date' => today(),
        'end_date' => today(),
        'options' => [
            'dates' => [$todayKey],
            'time_settings' => [$todayKey => ['use_time' => true, 'start_time' => '15:00', 'end_time' => '17:00']],
        ],
    ]);

    $thread = SmsGroupThread::query()->create([
        'name' => 'Client Thread',
        'from_number' => '+12245554444',
        'participants' => ['+12245550001'],
        'vendor_id' => $ownerVendor->id,
        'client_id' => $client->id,
        'last_activity_at' => now(),
    ]);

    $preview = Livewire::test(SendScheduleModal::class)
        ->call('open', $thread->id)
        ->get('previewMessage');

    // At 2PM: the 7-7:30AM roofer arrival is over, the 3-5PM inspection is not.
    expect($preview)->toContain('Rough Inspections')
        ->and($preview)->not->toContain('Roofer');

    \Illuminate\Support\Carbon::setTestNow();
});

it('drops the Today section once the vendor working day is over', function (): void {
    // 8 PM Chicago — past the default 18:00 close.
    Carbon\Carbon::setTestNow(Carbon\Carbon::create(2026, 8, 10, 20, 0, 0, 'America/Chicago'));

    $vendor = Vendor::factory()->create(['business_name' => 'GS Construction']);

    $user = User::query()->create([
        'first_name' => 'Owner',
        'last_name' => 'User',
        'email' => 'owner.after-hours@example.com',
        'cell_phone' => '2245550097',
        'primary_vendor_id' => $vendor->id,
    ]);

    $this->actingAs($user);

    $client = Client::factory()->create();
    $contact = User::query()->create([
        'first_name' => 'Carri',
        'last_name' => 'Jones',
        'email' => 'carri.after-hours@example.com',
        'cell_phone' => '2245550096',
        'primary_vendor_id' => null,
    ]);
    $client->users()->attach($contact->id);

    $project = Project::query()->create([
        'project_name' => 'After Hours Project',
        'client_id' => $client->id,
        'address' => '100 Main St', 'city' => 'Cary', 'state' => 'IL', 'zip_code' => 60013,
        'belongs_to_vendor_id' => $vendor->id,
    ]);

    Task::query()->create([
        'title' => 'Electrical',
        'project_id' => $project->id, 'vendor_id' => $vendor->id, 'type' => 'Task',
        'start_date' => today(), 'end_date' => today(),
    ]);
    Task::query()->create([
        'title' => 'Follow Up',
        'project_id' => $project->id, 'vendor_id' => $vendor->id, 'type' => 'Task',
        'start_date' => today()->addDay(), 'end_date' => today()->addDay(),
    ]);

    $thread = SmsGroupThread::query()->create([
        'name' => 'After Hours Thread',
        'from_number' => '+12245554444',
        'participants' => ['+12245550096'],
        'vendor_id' => $vendor->id,
        'client_id' => $client->id,
        'last_activity_at' => now(),
    ]);

    $preview = Livewire::test(SendScheduleModal::class)
        ->call('open', $thread->id)
        ->get('previewMessage');

    // No crew is coming today anymore — tomorrow leads the list.
    expect($preview)->not->toContain('Electrical')
        ->and($preview)->not->toContain('Today')
        ->and($preview)->toContain('Follow Up');

    Carbon\Carbon::setTestNow();
});

it('invites the client to pick consult times on a Response project with no Meet drafted', function (): void {
    $vendor = Vendor::factory()->create(['business_name' => 'GS Construction']);
    $vendor->short_name = 'GS Construction';
    $vendor->save();

    $user = User::query()->create([
        'first_name' => 'Owner',
        'last_name' => 'User',
        'email' => 'owner.response-invite@example.com',
        'cell_phone' => '2245550186',
        'primary_vendor_id' => $vendor->id,
    ]);

    $this->actingAs($user);

    $contact = User::query()->create([
        'first_name' => 'Rima',
        'last_name' => 'Patel',
        'email' => 'rima.response-invite@example.com',
        'cell_phone' => '2245550187',
    ]);

    $client = Client::factory()->create();
    $client->users()->attach($contact->id);

    $project = Project::query()->create([
        'project_name' => 'Bathrooms',
        'client_id' => $client->id,
        'address' => '100 Main St', 'city' => 'Cary', 'state' => 'IL', 'zip_code' => 60013,
        'belongs_to_vendor_id' => $vendor->id,
    ]);

    // Response: estimate delivered, homeowner deciding — consults still welcome.
    ProjectStatus::withoutEvents(fn () => ProjectStatus::create([
        'project_id' => $project->id,
        'belongs_to_vendor_id' => $vendor->id,
        'status_code' => 3,
        'start_date' => now(),
    ]));

    Lead::create([
        'date' => now(),
        'origin' => 'gs.construction',
        'user_id' => $contact->id,
        'belongs_to_vendor_id' => $vendor->id,
        'created_by_user_id' => $contact->id,
        'lead_data' => ['name' => 'Rima Patel', 'message' => 'Bathrooms'],
    ]);

    $thread = SmsGroupThread::query()->create([
        'name' => 'Response Thread',
        'from_number' => '+12245554444',
        'participants' => ['+12245550187'],
        'vendor_id' => $vendor->id,
        'client_id' => $client->id,
        'last_activity_at' => now(),
    ]);

    $preview = Livewire::test(SendScheduleModal::class)
        ->call('open', $thread->id)
        ->get('previewMessage');

    expect($preview)
        ->toContain('Pick a consultation time with GS Construction:')
        ->toContain('Times: ');

    // Once a consult is on the calendar the invite disappears.
    Task::withoutEvents(fn () => Task::create([
        'title' => 'GS/Patel Consult',
        'type' => 'Meet',
        'order' => 1,
        'project_id' => $project->id,
        'belongs_to_vendor_id' => $vendor->id,
        'created_by_user_id' => $user->id,
        'start_date' => today()->addDays(2),
        'end_date' => today()->addDays(2),
    ]));

    $previewAfter = Livewire::test(SendScheduleModal::class)
        ->call('open', $thread->id)
        ->get('previewMessage');

    expect($previewAfter)->not->toContain('Pick a consultation time');
});

it('asks a lead-less client to pick times via the schedule page, creating no lead', function (): void {
    $vendor = Vendor::factory()->create(['business_name' => 'GS Construction']);
    $vendor->short_name = 'GS Construction';
    $vendor->save();

    $user = User::query()->create([
        'first_name' => 'Owner',
        'last_name' => 'User',
        'email' => 'owner.leadless-invite@example.com',
        'cell_phone' => '2245550184',
        'primary_vendor_id' => $vendor->id,
    ]);

    $this->actingAs($user);

    $contact = User::query()->create([
        'first_name' => 'Adam',
        'last_name' => 'Krzeczowski',
        'email' => 'adam.leadless@example.com',
        'cell_phone' => '2245550185',
    ]);

    $client = Client::factory()->create();
    $client->users()->attach($contact->id);

    $project = Project::query()->create([
        'project_name' => 'Bathrooms',
        'client_id' => $client->id,
        'address' => '15 N Kaspar Ave', 'city' => 'Arlington Heights', 'state' => 'IL', 'zip_code' => 60005,
        'belongs_to_vendor_id' => $vendor->id,
    ]);

    ProjectStatus::withoutEvents(fn () => ProjectStatus::create([
        'project_id' => $project->id,
        'belongs_to_vendor_id' => $vendor->id,
        'status_code' => 3,
        'start_date' => now(),
    ]));

    $thread = SmsGroupThread::query()->create([
        'name' => 'Leadless Thread',
        'from_number' => '+12245554444',
        'participants' => ['+12245550185'],
        'vendor_id' => $vendor->id,
        'client_id' => $client->id,
        'last_activity_at' => now(),
    ]);

    $preview = Livewire::test(SendScheduleModal::class)
        ->call('open', $thread->id)
        ->get('previewMessage');

    expect($preview)
        ->toContain('Pick a consultation time with GS Construction:')
        ->toContain('Times: ')
        // One link, not a duplicate "View schedule" of the same URL.
        ->not->toContain('View schedule:');

    // The rule that started all this: never invent a lead for the link.
    expect(Lead::query()->count())->toBe(0);
});
