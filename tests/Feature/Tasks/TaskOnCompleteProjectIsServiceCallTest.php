<?php

use App\Livewire\Tasks\TaskCreate;
use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\Task;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Tasks\TaskWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// =========================================================================
// 901 Isabella, 2026-11-06: the homeowner texted about a crooked garbage
// cabinet, the office made the task from the thread, and the Kitchen job
// stayed "Complete" until someone changed it by hand nine seconds later.
// A new task on a Complete job IS a service call — the status says so the
// moment the task exists, the way the service-call email intake already
// did it.
// =========================================================================

/** @return array{vendor: Vendor, user: User, client: Client} */
function serviceCallStatusFixture(): array
{
    $vendor = Vendor::factory()->create(['business_name' => 'GS Construction']);
    $user = User::query()->create([
        'first_name' => 'Office', 'last_name' => 'Admin',
        'email' => 'office.service-call-'.uniqid().'@example.com',
        'cell_phone' => (string) random_int(2000000000, 9999999999),
        'primary_vendor_id' => $vendor->id,
    ]);
    $vendor->users()->attach($user->id, ['is_employed' => true, 'role_id' => 1]);
    test()->actingAs($user);

    $client = Client::factory()->create();
    $client->vendors()->attach($vendor->id);

    return ['vendor' => $vendor, 'user' => $user, 'client' => $client];
}

function projectInStatus(Client $client, Vendor $vendor, int $statusCode): Project
{
    $project = Project::query()->create([
        'project_name' => 'Kitchen', 'client_id' => $client->id, 'belongs_to_vendor_id' => $vendor->id,
        'address' => '901 Isabella', 'city' => 'Chicago', 'state' => 'IL', 'zip_code' => 60601,
    ]);
    $project->vendors()->attach($vendor->id, ['client_id' => $client->id]);
    ProjectStatus::withoutGlobalScopes()->where('project_id', $project->id)->delete();
    ProjectStatus::create(['project_id' => $project->id, 'belongs_to_vendor_id' => $vendor->id, 'status_code' => $statusCode, 'start_date' => '2026-07-21']);

    return $project;
}

it('puts a Complete job in Service Call the moment a task is made on it', function (): void {
    ['vendor' => $vendor, 'client' => $client] = serviceCallStatusFixture();
    $kitchen = projectInStatus($client, $vendor, 7);
    $this->travelTo('2026-11-06 14:25:30');

    $task = app(TaskWriter::class)->store(['title' => 'Garbage Cabinet Repair', 'project_id' => $kitchen->id, 'type' => 'Task']);

    expect($task)->toBeInstanceOf(Task::class);
    $latest = $kitchen->fresh()->latestStatus;
    expect($latest->status_code)->toBe(8)
        ->and($latest->start_date->toDateString())->toBe('2026-11-06')
        ->and(ProjectStatus::withoutGlobalScopes()->where('project_id', $kitchen->id)->count())->toBe(2);
});

it('leaves a job that is Active, or already in Service Call, as it is', function (): void {
    ['vendor' => $vendor, 'client' => $client] = serviceCallStatusFixture();
    $active = projectInStatus($client, $vendor, 6);
    $serviceCall = projectInStatus($client, $vendor, 8);

    app(TaskWriter::class)->store(['title' => 'Hang the doors', 'project_id' => $active->id, 'type' => 'Task']);
    app(TaskWriter::class)->store(['title' => 'Adjust the hinge', 'project_id' => $serviceCall->id, 'type' => 'Task']);

    expect($active->fresh()->latestStatus->status_code)->toBe(6)
        ->and(ProjectStatus::withoutGlobalScopes()->where('project_id', $active->id)->count())->toBe(1)
        ->and($serviceCall->fresh()->latestStatus->status_code)->toBe(8)
        ->and(ProjectStatus::withoutGlobalScopes()->where('project_id', $serviceCall->id)->count())->toBe(1);
});

it('does it from the task dialog too — the path the messages screen uses', function (): void {
    ['vendor' => $vendor, 'client' => $client] = serviceCallStatusFixture();
    $kitchen = projectInStatus($client, $vendor, 7);

    Livewire::test(TaskCreate::class)
        ->call('addTask', null, null, null, [], $client->id)
        ->set('form.project_id', $kitchen->id)
        ->set('form.title', 'Garbage Cabinet Repair')
        ->call('save')
        ->assertHasNoErrors();

    expect(Task::withoutGlobalScopes()->where('project_id', $kitchen->id)->where('title', 'Garbage Cabinet Repair')->exists())->toBeTrue()
        ->and($kitchen->fresh()->latestStatus->status_code)->toBe(8);
});
