<?php

use App\Livewire\Projects\ProjectsIndex;
use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// =========================================================================
// The sidebar's Projects link, clicked while already on /projects, clears
// the page's filters (Patryk, 2026-11-06): on production the remembered
// filters outlive a tab, so a bare /projects kept coming back as it was
// left and the status cards looked broken. app.js turns that click into
// the index-filters-clear event; the page forgets and shows its defaults.
// =========================================================================

/** @return array{user: User, vendor: Vendor, client: Client} */
function clearFiltersFixture(): array
{
    $vendor = Vendor::factory()->create(['business_type' => 'GC']);
    $user = User::query()->create([
        'first_name' => 'Index', 'last_name' => 'Admin',
        'email' => 'index-clear-'.uniqid().'@example.test',
        'cell_phone' => '224'.rand(1000000, 9999999),
        'primary_vendor_id' => $vendor->id,
    ]);
    $user->vendors()->attach($vendor->id, ['role_id' => 1]);
    test()->actingAs($user);

    $client = Client::factory()->create();
    $client->vendors()->attach($vendor->id);

    return ['user' => $user, 'vendor' => $vendor, 'client' => $client];
}

function projectIn(Client $client, Vendor $vendor, int $statusCode): Project
{
    $project = Project::factory()->create(['address' => '3154 Violet', 'project_name' => 'Home Renovation', 'client_id' => $client->id, 'belongs_to_vendor_id' => $vendor->id]);
    $project->vendors()->attach($vendor->id, ['client_id' => $client->id]);
    ProjectStatus::withoutGlobalScopes()->where('project_id', $project->id)->delete();
    ProjectStatus::create(['project_id' => $project->id, 'belongs_to_vendor_id' => $vendor->id, 'status_code' => $statusCode, 'start_date' => '2026-10-01']);

    return $project;
}

it('clears the remembered filters and goes back to the defaults on the sidebar event', function (): void {
    ['user' => $user, 'vendor' => $vendor, 'client' => $client] = clearFiltersFixture();
    projectIn($client, $vendor, 6);

    $component = Livewire::test(ProjectsIndex::class)
        ->set('project_status_title', [3])
        ->set('client_id', $client->id)
        ->set('project_name_search', 'violet');

    $key = sprintf('index-filters:v2:%d:%d:0:%s', $user->id, $vendor->id, ProjectsIndex::class);
    expect(Cache::get($key))->toMatchArray(['project_status_title' => [3], 'client_id' => $client->id, 'project_name_search' => 'violet']);

    $component->dispatch('index-filters-clear')
        ->assertSet('project_status_title', [6])
        ->assertSet('client_id', null)
        ->assertSet('project_name_search', '');

    // What the cleared page remembers now is its defaults, so the next
    // bare visit opens the same way.
    expect(Cache::get($key))->toMatchArray(['project_status_title' => [6], 'client_id' => null, 'project_name_search' => '']);
});

it('falls back to every status when no job is active', function (): void {
    ['vendor' => $vendor, 'client' => $client] = clearFiltersFixture();
    projectIn($client, $vendor, 7);

    Livewire::test(ProjectsIndex::class)
        ->set('project_status_title', [7])
        ->dispatch('index-filters-clear')
        ->assertSet('project_status_title', []);
});

it('ships the sidebar click that fires the event', function (): void {
    $js = (string) file_get_contents(resource_path('js/app.js'));

    expect($js)->toContain("[data-flux-sidebar] a[href]")
        ->toContain("target.pathname !== location.pathname")
        ->toContain("window.Livewire.dispatch('index-filters-clear')");
});
