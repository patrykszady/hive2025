<?php

use App\Livewire\Client\ScheduleIndex;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('pending tasks are collapsed by default on the client schedule page', function (): void {
    $client = Client::factory()->create();
    $vendor = Vendor::factory()->create();

    $project = Project::withoutEvents(fn () => Project::create([
        'project_name' => 'Client Schedule Project',
        'client_id' => $client->id,
        'belongs_to_vendor_id' => $vendor->id,
        'address' => '100 Main St',
        'city' => 'Cary',
        'state' => 'IL',
        'zip_code' => '60013',
    ]));

    $project->forceFill(['schedule_token' => 'test-client-schedule-token'])->saveQuietly();

    Task::withoutEvents(fn () => Task::create([
        'title' => 'Unscheduled Task',
        'type' => 'task',
        'order' => 1,
        'project_id' => $project->id,
        'belongs_to_vendor_id' => $vendor->id,
        'created_by_user_id' => 1,
        'vendor_status' => Task::VENDOR_STATUS_REQUESTED,
    ]));

    $html = Livewire::test(ScheduleIndex::class, ['token' => $project->schedule_token])->html();

    expect($html)->toContain('Pending Tasks');
    expect($html)->not->toContain('<flux:accordion.item expanded');
});

/**
 * @return array{project: Project, vendor: Vendor}
 */
function clientScheduleFixture(string $token): array
{
    $client = Client::factory()->create();
    $vendor = Vendor::factory()->create();

    $project = Project::withoutEvents(fn () => Project::create([
        'project_name' => 'Signed In Schedule Project',
        'client_id' => $client->id,
        'belongs_to_vendor_id' => $vendor->id,
        'address' => '200 Main St',
        'city' => 'Cary',
        'state' => 'IL',
        'zip_code' => '60013',
    ]));

    $project->forceFill(['schedule_token' => $token])->saveQuietly();

    return ['project' => $project, 'vendor' => $vendor];
}

function signedInVendorUser(Vendor $vendor): User
{
    $user = User::query()->create([
        'first_name' => 'Signed',
        'last_name' => 'In',
        'email' => 'signed.in-'.uniqid().'@example.test',
        'cell_phone' => (string) random_int(2000000000, 9999999999),
        'primary_vendor_id' => $vendor->id,
    ]);
    $vendor->users()->attach($user->id, ['is_employed' => true, 'role_id' => 1]);

    return $user;
}

test('a signed-in person stays on the client schedule instead of going to the dashboard', function (): void {
    ['project' => $project, 'vendor' => $vendor] = clientScheduleFixture('signed-in-schedule-token');
    $project->vendors()->attach($vendor->id, ['client_id' => $project->client_id]);

    $this->actingAs(signedInVendorUser($vendor));

    Livewire::test(ScheduleIndex::class, ['token' => $project->schedule_token])
        ->assertNoRedirect()
        ->assertSet('valid', true)
        ->assertSet('projectId', $project->id);
});

test('the schedule link works for a signed-in person outside the project', function (): void {
    ['project' => $project] = clientScheduleFixture('outside-schedule-token');

    $this->actingAs(signedInVendorUser(Vendor::factory()->create()));

    Livewire::test(ScheduleIndex::class, ['token' => $project->schedule_token])
        ->assertNoRedirect()
        ->assertSet('valid', true)
        ->assertSet('projectId', $project->id);
});
