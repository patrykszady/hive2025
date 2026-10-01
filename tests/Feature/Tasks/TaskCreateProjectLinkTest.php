<?php

use App\Livewire\Tasks\TaskCreate;
use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * @return array{vendor: Vendor, user: User, project: Project}
 */
function makeTaskProjectLinkFixture(): array
{
    $vendor = Vendor::factory()->create(['business_name' => 'GS Construction']);

    $user = User::query()->create([
        'first_name' => 'Owner',
        'last_name' => 'User',
        'email' => 'owner.project-link-'.uniqid().'@example.com',
        'cell_phone' => (string) random_int(2000000000, 9999999999),
        'primary_vendor_id' => $vendor->id,
    ]);
    $vendor->users()->attach($user->id, ['is_employed' => true, 'role_id' => 1]);

    test()->actingAs($user);

    $client = Client::factory()->create();
    $client->vendors()->attach($vendor->id);

    $project = Project::query()->create([
        'project_name' => 'Project '.uniqid(),
        'client_id' => $client->id,
        'belongs_to_vendor_id' => $vendor->id,
        'address' => '123 Main St',
        'city' => 'Chicago',
        'state' => 'IL',
        'zip_code' => 60601,
    ]);

    return ['vendor' => $vendor, 'user' => $user, 'project' => $project];
}

it('shows an icon-only open-project link pointing at the selected project', function (): void {
    ['project' => $project] = makeTaskProjectLinkFixture();

    Livewire::test(TaskCreate::class)
        ->call('addTask', $project->id)
        ->assertSeeHtml('aria-label="Open project"')
        ->assertSeeHtml(route('projects.show', $project));
});

it('hides the open-project link when no project is selected', function (): void {
    makeTaskProjectLinkFixture();

    Livewire::test(TaskCreate::class)
        ->call('addTask')
        ->assertDontSeeHtml('aria-label="Open project"');
});
