<?php

use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['scout.driver' => null]);
});

/** @return array{vendor: Vendor, user: User} */
function appTaskVendorAvailabilityTeamUser(int $role = 1): array
{
    $vendor = Vendor::query()->find(1) ?? Vendor::factory()->create(['id' => 1, 'business_name' => 'GS Construction']);
    $user = User::factory()->create(['primary_vendor_id' => $vendor->id]);
    $vendor->users()->attach($user->id, ['role_id' => $role]);

    return compact('vendor', 'user');
}

function appTaskVendorAvailabilityProject(Vendor $vendor): Project
{
    $client = Client::factory()->create();
    $client->vendors()->attach($vendor->id);

    $project = Project::withoutEvents(fn () => Project::create([
        'project_name' => 'Kitchen Remodel',
        'client_id' => $client->id,
        'address' => '123 Main St',
        'city' => 'Northbrook',
        'state' => 'IL',
        'zip_code' => '60062',
        'belongs_to_vendor_id' => $vendor->id,
    ]));

    $project->vendors()->attach($vendor->id, ['client_id' => $client->id]);

    return $project;
}

function appTaskVendorAvailabilityTask(Project $project, Vendor $vendor, User $user, array $overrides = []): Task
{
    return Task::withoutEvents(fn () => Task::create(array_merge([
        'title' => 'Install cabinets',
        'type' => 'Task',
        'order' => 0,
        'project_id' => $project->id,
        'belongs_to_vendor_id' => $vendor->id,
        'created_by_user_id' => $user->id,
    ], $overrides)));
}

it('needs a token to confirm vendor availability', function () {
    $this->postJson('/api/app/v1/tasks/1/vendor-availability/confirm')->assertUnauthorized();
});

it('confirms a requested vendor, like TaskCreate::confirmVendorAvailability()', function () {
    ['vendor' => $vendor, 'user' => $user] = appTaskVendorAvailabilityTeamUser();
    Sanctum::actingAs($user, ['app']);

    $project = appTaskVendorAvailabilityProject($vendor);
    $subVendor = Vendor::factory()->create();
    $task = appTaskVendorAvailabilityTask($project, $vendor, $user, [
        'vendor_id' => $subVendor->id,
        'vendor_status' => Task::VENDOR_STATUS_REQUESTED,
    ]);

    $response = $this->postJson("/api/app/v1/tasks/{$task->id}/vendor-availability/confirm")->assertOk();

    expect($task->fresh()->vendor_status)->toBe(Task::VENDOR_STATUS_CONFIRMED)
        ->and($response->json('data.vendor_status'))->toBe(Task::VENDOR_STATUS_CONFIRMED)
        ->and($response->json('data.vendor_status_ui.label'))->toBe('Confirmed');
});

it('rejects a requested vendor, like TaskCreate::rejectVendorAvailability()', function () {
    ['vendor' => $vendor, 'user' => $user] = appTaskVendorAvailabilityTeamUser();
    Sanctum::actingAs($user, ['app']);

    $project = appTaskVendorAvailabilityProject($vendor);
    $subVendor = Vendor::factory()->create();
    $task = appTaskVendorAvailabilityTask($project, $vendor, $user, [
        'vendor_id' => $subVendor->id,
        'vendor_status' => Task::VENDOR_STATUS_PROPOSED,
    ]);

    $response = $this->postJson("/api/app/v1/tasks/{$task->id}/vendor-availability/reject")->assertOk();

    expect($task->fresh()->vendor_status)->toBe(Task::VENDOR_STATUS_REJECTED)
        ->and($response->json('data.vendor_status'))->toBe(Task::VENDOR_STATUS_REJECTED);
});

it('resets a confirmed vendor back to requested, like TaskCreate::resetVendorAvailability()', function () {
    ['vendor' => $vendor, 'user' => $user] = appTaskVendorAvailabilityTeamUser();
    Sanctum::actingAs($user, ['app']);

    $project = appTaskVendorAvailabilityProject($vendor);
    $subVendor = Vendor::factory()->create();
    $task = appTaskVendorAvailabilityTask($project, $vendor, $user, [
        'vendor_id' => $subVendor->id,
        'vendor_status' => Task::VENDOR_STATUS_CONFIRMED,
    ]);

    $response = $this->postJson("/api/app/v1/tasks/{$task->id}/vendor-availability/reset")->assertOk();

    expect($task->fresh()->vendor_status)->toBe(Task::VENDOR_STATUS_REQUESTED)
        ->and($response->json('data.vendor_status'))->toBe(Task::VENDOR_STATUS_REQUESTED);
});

it('refuses to confirm a task with no vendor assigned', function () {
    ['vendor' => $vendor, 'user' => $user] = appTaskVendorAvailabilityTeamUser();
    Sanctum::actingAs($user, ['app']);

    $project = appTaskVendorAvailabilityProject($vendor);
    $task = appTaskVendorAvailabilityTask($project, $vendor, $user);

    $this->postJson("/api/app/v1/tasks/{$task->id}/vendor-availability/confirm")
        ->assertStatus(422)
        ->assertJsonValidationErrors(['vendor_status']);
});

it('refuses to confirm a task that is already confirmed or rejected', function () {
    ['vendor' => $vendor, 'user' => $user] = appTaskVendorAvailabilityTeamUser();
    Sanctum::actingAs($user, ['app']);

    $project = appTaskVendorAvailabilityProject($vendor);
    $subVendor = Vendor::factory()->create();
    $task = appTaskVendorAvailabilityTask($project, $vendor, $user, [
        'vendor_id' => $subVendor->id,
        'vendor_status' => Task::VENDOR_STATUS_REJECTED,
    ]);

    $this->postJson("/api/app/v1/tasks/{$task->id}/vendor-availability/confirm")
        ->assertStatus(422)
        ->assertJsonValidationErrors(['vendor_status']);

    expect($task->fresh()->vendor_status)->toBe(Task::VENDOR_STATUS_REJECTED);
});

it('refuses to reset a task that is not confirmed', function () {
    ['vendor' => $vendor, 'user' => $user] = appTaskVendorAvailabilityTeamUser();
    Sanctum::actingAs($user, ['app']);

    $project = appTaskVendorAvailabilityProject($vendor);
    $subVendor = Vendor::factory()->create();
    $task = appTaskVendorAvailabilityTask($project, $vendor, $user, [
        'vendor_id' => $subVendor->id,
        'vendor_status' => Task::VENDOR_STATUS_REQUESTED,
    ]);

    $this->postJson("/api/app/v1/tasks/{$task->id}/vendor-availability/reset")
        ->assertStatus(422)
        ->assertJsonValidationErrors(['vendor_status']);
});

it('403s another company trying to confirm vendor availability on a task it cannot update', function () {
    ['vendor' => $vendor, 'user' => $user] = appTaskVendorAvailabilityTeamUser();

    $project = appTaskVendorAvailabilityProject($vendor);
    $subVendor = Vendor::factory()->create();
    $task = appTaskVendorAvailabilityTask($project, $vendor, $user, [
        'vendor_id' => $subVendor->id,
        'vendor_status' => Task::VENDOR_STATUS_REQUESTED,
    ]);

    $otherVendor = Vendor::factory()->create();
    $otherUser = User::factory()->create(['primary_vendor_id' => $otherVendor->id]);
    $otherVendor->users()->attach($otherUser->id, ['role_id' => 1]);

    Sanctum::actingAs($otherUser, ['app']);

    $this->postJson("/api/app/v1/tasks/{$task->id}/vendor-availability/confirm")->assertForbidden();

    expect($task->fresh()->vendor_status)->toBe(Task::VENDOR_STATUS_REQUESTED);
});
