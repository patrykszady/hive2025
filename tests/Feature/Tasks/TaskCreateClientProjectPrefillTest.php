<?php

use App\Livewire\Tasks\TaskCreate;
use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\User;
use App\Models\Vendor;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * @return array{vendor: Vendor, user: User, client: Client}
 */
function makeTaskPrefillFixture(): array
{
    $vendor = Vendor::factory()->create(['business_name' => 'GS Construction']);

    $user = User::query()->create([
        'first_name' => 'Owner',
        'last_name' => 'User',
        'email' => 'owner.prefill-' . uniqid() . '@example.com',
        'cell_phone' => (string) random_int(2000000000, 9999999999),
        'primary_vendor_id' => $vendor->id,
    ]);
    $vendor->users()->attach($user->id, ['is_employed' => true, 'role_id' => 1]);

    test()->actingAs($user);

    $client = Client::factory()->create();
    $client->vendors()->attach($vendor->id);

    return ['vendor' => $vendor, 'user' => $user, 'client' => $client];
}

function makeClientProject(Client $client, Vendor $vendor, int $statusCode, string $createdAt): Project
{
    $project = Project::query()->create([
        'project_name' => 'Project ' . uniqid(),
        'client_id' => $client->id,
        'belongs_to_vendor_id' => $vendor->id,
        'address' => '123 Main St',
        'city' => 'Chicago',
        'state' => 'IL',
        'zip_code' => 60601,
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);

    $project->vendors()->attach($vendor->id, ['client_id' => $client->id]);

    // Projects auto-create an initial status on creation; replace it so the
    // project's latest status is exactly the one under test.
    ProjectStatus::withoutGlobalScopes()->where('project_id', $project->id)->delete();

    ProjectStatus::create([
        'project_id' => $project->id,
        'belongs_to_vendor_id' => $vendor->id,
        'status_code' => $statusCode,
        'start_date' => Carbon::parse($createdAt)->toDateString(),
    ]);

    return $project;
}

it('pre-fills the project with the latest active project when creating a task for a client', function (): void {
    ['vendor' => $vendor, 'client' => $client] = makeTaskPrefillFixture();

    makeClientProject($client, $vendor, 7, '2026-01-01'); // Complete (oldest)
    $activeProject = makeClientProject($client, $vendor, 6, '2026-02-01'); // Active
    makeClientProject($client, $vendor, 2, '2026-03-01'); // Estimate (newest, but not active)

    Livewire::test(TaskCreate::class)
        ->call('addTask', null, null, null, [], $client->id)
        ->assertSet('form.project_id', $activeProject->id);
});

it('falls back to the latest open project when the client has no active project', function (): void {
    ['vendor' => $vendor, 'client' => $client] = makeTaskPrefillFixture();

    makeClientProject($client, $vendor, 7, '2026-01-01'); // Complete
    makeClientProject($client, $vendor, 2, '2026-02-01'); // Estimate
    $newestOpen = makeClientProject($client, $vendor, 5, '2026-03-01'); // Scheduled (newest open)
    makeClientProject($client, $vendor, 10, '2026-04-01'); // Cancelled (newest, but terminal)

    Livewire::test(TaskCreate::class)
        ->call('addTask', null, null, null, [], $client->id)
        ->assertSet('form.project_id', $newestOpen->id);
});

it('falls back to the latest project when every client project is complete or cancelled', function (): void {
    ['vendor' => $vendor, 'client' => $client] = makeTaskPrefillFixture();

    makeClientProject($client, $vendor, 7, '2026-01-01'); // Complete
    $newest = makeClientProject($client, $vendor, 10, '2026-02-01'); // Cancelled (newest)

    Livewire::test(TaskCreate::class)
        ->call('addTask', null, null, null, [], $client->id)
        ->assertSet('form.project_id', $newest->id);
});

it('does not override an explicitly provided project id', function (): void {
    ['vendor' => $vendor, 'client' => $client] = makeTaskPrefillFixture();

    $explicit = makeClientProject($client, $vendor, 7, '2026-01-01'); // Complete
    makeClientProject($client, $vendor, 6, '2026-02-01'); // Active

    Livewire::test(TaskCreate::class)
        ->call('addTask', $explicit->id, null, null, [], $client->id)
        ->assertSet('form.project_id', $explicit->id);
});
