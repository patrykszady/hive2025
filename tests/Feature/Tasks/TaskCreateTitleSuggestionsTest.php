<?php

use App\Livewire\Tasks\TaskCreate;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * @return array{vendor: Vendor, user: User, project: Project}
 */
function makeTitleSuggestionFixture(): array
{
    $vendor = Vendor::factory()->create(['business_name' => 'GS Construction']);

    $user = User::query()->create([
        'first_name' => 'Owner',
        'last_name' => 'User',
        'email' => 'owner.title-suggest-'.uniqid().'@example.com',
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

/**
 * Creates a task directly (bypassing the component) so fixtures can control
 * title/vendor/created_at precisely without going through the full form.
 */
function makeSuggestionTask(Project $project, string $title, ?Vendor $vendor = null, ?string $createdAt = null): Task
{
    $task = Task::create([
        'project_id' => $project->id,
        'title' => $title,
        'type' => 'Task',
        'order' => 0,
        'vendor_id' => $vendor?->id,
        'user_ids' => [],
    ]);

    if ($createdAt) {
        $task->forceFill(['created_at' => $createdAt])->save();
    }

    return $task;
}

it('orders title suggestions by use then recency, de-duplicated case-insensitively', function (): void {
    ['vendor' => $vendor, 'project' => $project] = makeTitleSuggestionFixture();

    // "Plumbing" used 3 times (most), "Drywall" twice, "Paint" once — plus a
    // differently-cased repeat of "Plumbing" that must collapse into it,
    // keeping whichever spelling was used most ("Plumbing").
    makeSuggestionTask($project, 'Plumbing', $vendor, '2026-01-01');
    makeSuggestionTask($project, 'Plumbing', $vendor, '2026-01-05');
    makeSuggestionTask($project, 'plumbing', $vendor, '2026-01-10');
    makeSuggestionTask($project, 'Drywall', null, '2026-01-02');
    makeSuggestionTask($project, 'Drywall', null, '2026-01-03');
    makeSuggestionTask($project, 'Paint', null, '2026-01-20');

    Livewire::test(TaskCreate::class)
        ->call('addTask')
        ->assertSet('titleSuggestions', ['Plumbing', 'Drywall', 'Paint']);
});

it('breaks a count tie by most recent use', function (): void {
    ['vendor' => $vendor, 'project' => $project] = makeTitleSuggestionFixture();

    makeSuggestionTask($project, 'Tile', $vendor, '2026-01-01');
    makeSuggestionTask($project, 'Grout', $vendor, '2026-02-01'); // same count (1), more recent

    Livewire::test(TaskCreate::class)
        ->call('addTask')
        ->assertSet('titleSuggestions', ['Grout', 'Tile']);
});

it('never suggests another tenant\'s titles', function (): void {
    ['vendor' => $vendor, 'user' => $user, 'project' => $project] = makeTitleSuggestionFixture();
    makeSuggestionTask($project, 'My Tenant Task', $vendor);

    // A second, unrelated tenant with its own project + task. Created while
    // acting as ITS OWN user — ProjectObserver::creating() stamps
    // belongs_to_vendor_id from the CURRENTLY authenticated user, regardless
    // of what's passed in, so building this under the first tenant's actor
    // would silently attach it to the first tenant instead.
    ['vendor' => $otherVendor, 'project' => $otherProject] = makeTitleSuggestionFixture();
    makeSuggestionTask($otherProject, 'Other Tenant Secret Task', $otherVendor);

    test()->actingAs($user);

    $suggestions = Livewire::test(TaskCreate::class)
        ->call('addTask')
        ->get('titleSuggestions');

    expect($suggestions)->toContain('My Tenant Task')
        ->and($suggestions)->not->toContain('Other Tenant Secret Task');
});

it('fills the dominant vendor when a suggestion is picked', function (): void {
    ['vendor' => $vendor, 'project' => $project] = makeTitleSuggestionFixture();

    makeSuggestionTask($project, 'Plumbing', $vendor);
    makeSuggestionTask($project, 'Plumbing', $vendor);
    makeSuggestionTask($project, 'Plumbing', $vendor);

    Livewire::test(TaskCreate::class)
        ->call('addTask')
        ->set('form.project_id', $project->id)
        ->call('selectTitleSuggestion', 'Plumbing')
        ->assertSet('form.title', 'Plumbing')
        ->assertSet('form.vendor_id', $vendor->id);
});

it('fills the dominant vendor when blurring onto an exact (case-insensitive) match', function (): void {
    ['vendor' => $vendor, 'project' => $project] = makeTitleSuggestionFixture();

    makeSuggestionTask($project, 'Plumbing', $vendor);
    makeSuggestionTask($project, 'Plumbing', $vendor);

    Livewire::test(TaskCreate::class)
        ->call('addTask')
        ->set('form.project_id', $project->id)
        ->set('form.title', 'PLUMBING') // different casing, simulates a typed+blurred match
        ->assertSet('form.vendor_id', $vendor->id);
});

it('fills nothing when the title is split across several vendors', function (): void {
    ['vendor' => $vendorA, 'project' => $project] = makeTitleSuggestionFixture();
    $vendorB = Vendor::factory()->create(['business_name' => 'Second Sub']);

    // 3 total uses, 2/3 (67%) would pass the share test on its own, but here
    // it's an even split that must not reach the 60% share for either vendor.
    makeSuggestionTask($project, 'Demo', $vendorA);
    makeSuggestionTask($project, 'Demo', $vendorA);
    makeSuggestionTask($project, 'Demo', $vendorB);
    makeSuggestionTask($project, 'Demo', $vendorB);

    Livewire::test(TaskCreate::class)
        ->call('addTask')
        ->set('form.project_id', $project->id)
        ->call('selectTitleSuggestion', 'Demo')
        ->assertSet('form.vendor_id', null);
});

it('never overwrites a vendor the user already chose', function (): void {
    ['vendor' => $dominantVendor, 'project' => $project] = makeTitleSuggestionFixture();
    $chosenVendor = Vendor::factory()->create(['business_name' => 'User Picked This One']);

    makeSuggestionTask($project, 'Plumbing', $dominantVendor);
    makeSuggestionTask($project, 'Plumbing', $dominantVendor);

    Livewire::test(TaskCreate::class)
        ->call('addTask')
        ->set('form.project_id', $project->id)
        ->set('form.vendor_id', $chosenVendor->id)
        ->call('selectTitleSuggestion', 'Plumbing')
        ->assertSet('form.vendor_id', $chosenVendor->id);
});

it('leaves an edited task\'s vendor alone unless its title is changed', function (): void {
    ['vendor' => $dominantVendor, 'project' => $project] = makeTitleSuggestionFixture();
    $originalVendor = Vendor::factory()->create(['business_name' => 'Originally Assigned Sub']);

    // Build up "Plumbing" => $dominantVendor as the remembered pairing.
    makeSuggestionTask($project, 'Plumbing', $dominantVendor);
    makeSuggestionTask($project, 'Plumbing', $dominantVendor);

    // The task being edited already has a DIFFERENT vendor on a title that
    // happens to match the remembered pairing above — loading it for edit
    // must not touch vendor_id just because the title matches.
    $task = makeSuggestionTask($project, 'Plumbing', $originalVendor);

    Livewire::test(TaskCreate::class)
        ->call('editTask', $task->id)
        ->assertSet('form.vendor_id', $originalVendor->id);
});
