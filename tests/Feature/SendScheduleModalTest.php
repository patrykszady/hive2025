<?php

use App\Livewire\Sms\SendScheduleModal;
use App\Models\Client;
use App\Models\Project;
use App\Models\SmsGroupThread;
use App\Models\Task;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

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

    expect($preview)->toStartWith('Hello Smartech,')
        ->and($preview)->toContain('Upcoming tasks:')
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

    expect($preview)->toStartWith('Hello Rg Tile,')
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
