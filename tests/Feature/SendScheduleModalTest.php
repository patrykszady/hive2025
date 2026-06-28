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

    expect($preview)->toStartWith('Hi Smartech,')
        ->and($preview)->toContain('Confirm Tasks:')
        ->and($preview)->toContain('Confirm Schedule:');
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
