<?php

use App\Models\Client;
use App\Models\Lead;
use App\Models\Project;
use App\Models\ProjectStatus;
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
function appTaskScheduleHelpersTeamUser(int $role = 1): array
{
    $vendor = Vendor::query()->find(1) ?? Vendor::factory()->create(['id' => 1, 'business_name' => 'GS Construction']);
    $user = User::factory()->create(['primary_vendor_id' => $vendor->id]);
    $vendor->users()->attach($user->id, ['role_id' => $role]);

    return compact('vendor', 'user');
}

/** @return array{vendor: Vendor, project: Project, client: Client} */
function appTaskScheduleHelpersFixture(Vendor $vendor, array $overrides = []): array
{
    $client = Client::factory()->create();
    $client->vendors()->attach($vendor->id);

    $project = Project::withoutEvents(fn () => Project::create(array_merge([
        'project_name' => 'Kitchen Remodel',
        'client_id' => $client->id,
        'address' => '123 Main St',
        'city' => 'Northbrook',
        'state' => 'IL',
        'zip_code' => '60062',
        'belongs_to_vendor_id' => $vendor->id,
    ], $overrides)));

    $project->vendors()->attach($vendor->id, ['client_id' => $client->id]);

    return compact('vendor', 'project', 'client');
}

// --- Preferred times (App\Livewire\Tasks\TaskCreate::servicePreferredSlots()/homeownerAvailability()) ---

it('needs a token to read preferred times', function () {
    $this->getJson('/api/app/v1/tasks/preferred-times?project_id=1')->assertUnauthorized();
});

it('exposes the homeowner preferred slots grouped by day, like TaskCreate::servicePreferredSlots()', function () {
    ['vendor' => $vendor, 'user' => $user] = appTaskScheduleHelpersTeamUser();
    Sanctum::actingAs($user, ['app']);

    ['project' => $project] = appTaskScheduleHelpersFixture($vendor, [
        'service_availability' => [
            'slots' => [
                ['date' => '2026-07-16', 'time' => '7-9 AM'],
                ['date' => '2026-07-16', 'time' => '1-3 PM'],
                ['date' => '2026-07-20', 'time' => 'Anytime'],
            ],
            'submitted_at' => now()->toIso8601String(),
        ],
    ]);

    $response = $this->getJson("/api/app/v1/tasks/preferred-times?project_id={$project->id}")->assertOk();

    $slots = $response->json('data.service_preferred_slots');
    expect($slots)->toHaveCount(2)
        ->and($slots[0]['date'])->toBe('2026-07-16')
        ->and(collect($slots[0]['times'])->pluck('time')->all())->toBe(['7-9 AM', '1-3 PM'])
        // No live form to check an "applied" state against — the app
        // computes that itself from its own in-progress dates/time_settings.
        ->and($slots[0]['times'][0])->not->toHaveKey('applied')
        ->and($response->json('data.service_preferred_submitted_at'))->not->toBeNull()
        ->and($response->json('data.homeowner_availability'))->toBeNull();
});

it('hides preferred slots once the task already has a start date', function () {
    ['vendor' => $vendor, 'user' => $user] = appTaskScheduleHelpersTeamUser();
    Sanctum::actingAs($user, ['app']);

    ['project' => $project] = appTaskScheduleHelpersFixture($vendor, [
        'service_availability' => [
            'slots' => [['date' => '2026-07-16', 'time' => '1-3 PM']],
            'submitted_at' => now()->toIso8601String(),
        ],
    ]);

    $task = Task::withoutEvents(fn () => Task::create([
        'title' => 'Scheduled task',
        'project_id' => $project->id,
        'belongs_to_vendor_id' => $vendor->id,
        'created_by_user_id' => $user->id,
        'type' => 'Task',
        'order' => 0,
        'start_date' => '2026-06-30',
        'end_date' => '2026-06-30',
    ]));

    $response = $this->getJson("/api/app/v1/tasks/preferred-times?project_id={$project->id}&task_id={$task->id}")->assertOk();

    expect($response->json('data.service_preferred_slots'))->toBe([]);
});

it('shows the awaiting-client-availability card for a service-call project with no slots yet', function () {
    ['vendor' => $vendor, 'user' => $user] = appTaskScheduleHelpersTeamUser();
    Sanctum::actingAs($user, ['app']);

    ['project' => $project] = appTaskScheduleHelpersFixture($vendor);

    ProjectStatus::query()->create([
        'project_id' => $project->id,
        'belongs_to_vendor_id' => $project->belongs_to_vendor_id,
        'status_code' => 8,
        'start_date' => now(),
    ]);

    $response = $this->getJson("/api/app/v1/tasks/preferred-times?project_id={$project->id}")->assertOk();

    expect($response->json('data.show_awaiting_client_availability_card'))->toBeTrue();
});

it('exposes homeowner availability for a Meet, like TaskCreate::homeownerAvailability()', function () {
    ['vendor' => $vendor, 'user' => $user] = appTaskScheduleHelpersTeamUser();
    Sanctum::actingAs($user, ['app']);

    ['project' => $project, 'client' => $client] = appTaskScheduleHelpersFixture($vendor);
    $clientContact = User::factory()->create(['email' => 'homeowner@example.test']);
    $client->users()->attach($clientContact->id);

    Lead::query()->create([
        'date' => now(),
        'origin' => 'website',
        'belongs_to_vendor_id' => $vendor->id,
        'created_by_user_id' => $user->id,
        'user_id' => $clientContact->id,
        'lead_data' => [
            'availability' => [
                ['date' => now()->addDays(3)->format('Y-m-d'), 'time' => '4-6 PM'],
            ],
            'availability_updated_at' => now()->toIso8601String(),
            'meeting_preference' => 'in_person',
        ],
    ]);

    $response = $this->getJson("/api/app/v1/tasks/preferred-times?project_id={$project->id}&type=Meet")->assertOk();

    $availability = $response->json('data.homeowner_availability');
    expect($availability)->not->toBeNull()
        ->and($availability['times'])->toHaveCount(1)
        ->and($availability['preference'])->toBe('in_person');
});

it('403s a project outside this tenant', function () {
    ['user' => $user] = appTaskScheduleHelpersTeamUser();
    Sanctum::actingAs($user, ['app']);

    $otherVendor = Vendor::factory()->create();
    ['project' => $otherProject] = appTaskScheduleHelpersFixture($otherVendor);

    $this->getJson("/api/app/v1/tasks/preferred-times?project_id={$otherProject->id}")->assertNotFound();
});

// --- Meeting contacts (TaskCreate::availableMeetingContacts()/participantSearchResults()) ---

it('needs a token to search meeting contacts', function () {
    $this->getJson('/api/app/v1/tasks/meeting-contacts')->assertUnauthorized();
});

it('lists the project client and team with nothing searched, like availableMeetingContacts()', function () {
    ['vendor' => $vendor, 'user' => $user] = appTaskScheduleHelpersTeamUser();
    Sanctum::actingAs($user, ['app']);

    ['project' => $project, 'client' => $client] = appTaskScheduleHelpersFixture($vendor);
    $clientContact = User::factory()->create(['first_name' => 'Mark', 'last_name' => 'Brodson', 'email' => 'mark.brodson@example.test']);
    $client->users()->attach($clientContact->id);

    $response = $this->getJson("/api/app/v1/tasks/meeting-contacts?project_id={$project->id}")->assertOk();

    $emails = collect($response->json('data'))->pluck('email');
    expect($emails)->toContain('mark.brodson@example.test')
        ->and($emails)->toContain(strtolower($user->email));
});

it('widens to anyone the company knows once a search term narrows it down', function () {
    ['vendor' => $vendor, 'user' => $user] = appTaskScheduleHelpersTeamUser();
    Sanctum::actingAs($user, ['app']);

    ['project' => $project] = appTaskScheduleHelpersFixture($vendor);

    $otherClient = Client::factory()->create();
    $otherClient->vendors()->attach($vendor->id);
    $otherHomeowner = User::factory()->create(['first_name' => 'Zyxariana', 'last_name' => 'Homeowner', 'email' => 'zyxariana.homeowner@example.test']);
    $otherClient->users()->attach($otherHomeowner->id);

    $response = $this->getJson("/api/app/v1/tasks/meeting-contacts?project_id={$project->id}&search=zyxari")->assertOk();

    expect(collect($response->json('data'))->pluck('email')->all())->toBe(['zyxariana.homeowner@example.test']);
});
