<?php

use App\Livewire\Vendors\VendorTaskList;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * @return array{owner: Vendor, subject: Vendor, project: Project}
 */
function makeVendorTaskListFixture(): array
{
    $owner = Vendor::factory()->create(['business_name' => 'GS Construction']);
    $subject = Vendor::factory()->create(['business_name' => 'Smartech Electric']);

    $user = User::query()->create([
        'first_name' => 'Owner',
        'last_name' => 'User',
        'email' => 'owner.vendor-tasklist-' . uniqid() . '@example.test',
        'cell_phone' => (string) random_int(2000000000, 9999999999),
        'primary_vendor_id' => $owner->id,
    ]);
    $owner->users()->attach($user->id, ['is_employed' => true, 'role_id' => 1]);
    test()->actingAs($user);

    $client = Client::factory()->create();

    $project = Project::withoutEvents(fn () => Project::create([
        'project_name' => 'Vendor Task Project',
        'client_id' => $client->id,
        'belongs_to_vendor_id' => $owner->id,
        'address' => '100 Main St',
        'city' => 'Cary',
        'state' => 'IL',
        'zip_code' => '60013',
    ]));

    $project->vendors()->attach($owner->id, ['client_id' => $client->id]);

    return ['owner' => $owner, 'subject' => $subject, 'project' => $project];
}

it('shows scheduled and pending tasks assigned to the vendor', function (): void {
    ['subject' => $subject, 'project' => $project] = makeVendorTaskListFixture();

    $today = now()->format('Y-m-d');

    Task::withoutEvents(fn () => Task::create([
        'title' => 'Scheduled Wiring',
        'type' => 'Task',
        'order' => 1,
        'project_id' => $project->id,
        'vendor_id' => $subject->id,
        'belongs_to_vendor_id' => $project->belongs_to_vendor_id,
        'created_by_user_id' => 1,
        'start_date' => $today,
        'end_date' => $today,
        'options' => ['dates' => [$today]],
    ]));

    Task::withoutEvents(fn () => Task::create([
        'title' => 'Pending Outlet Fix',
        'type' => 'Task',
        'order' => 2,
        'project_id' => $project->id,
        'vendor_id' => $subject->id,
        'belongs_to_vendor_id' => $project->belongs_to_vendor_id,
        'created_by_user_id' => 1,
    ]));

    $component = Livewire::test(VendorTaskList::class, ['vendor' => $subject]);

    expect($component->instance()->unscheduledTasks->pluck('title')->all())
        ->toContain('Pending Outlet Fix');

    expect($component->instance()->groupedTasks->flatten(1)->pluck('title')->all())
        ->toContain('Scheduled Wiring');

    $component->assertSee('Scheduled Wiring')
        ->assertSee('Pending Outlet Fix');
});

it('does not show tasks assigned to a different vendor', function (): void {
    ['owner' => $owner, 'subject' => $subject, 'project' => $project] = makeVendorTaskListFixture();

    $otherVendor = Vendor::factory()->create(['business_name' => 'RG Tile']);

    Task::withoutEvents(fn () => Task::create([
        'title' => 'Someone Elses Task',
        'type' => 'Task',
        'order' => 1,
        'project_id' => $project->id,
        'vendor_id' => $otherVendor->id,
        'belongs_to_vendor_id' => $project->belongs_to_vendor_id,
        'created_by_user_id' => 1,
    ]));

    Livewire::test(VendorTaskList::class, ['vendor' => $subject])
        ->assertDontSee('Someone Elses Task');
});
