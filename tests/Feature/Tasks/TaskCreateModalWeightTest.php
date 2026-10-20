<?php

use App\Livewire\Tasks\TaskCreate;
use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['scout.driver' => null]);
});

/**
 * Every arrival-time pick in the task modal is a Livewire round trip that
 * re-renders the modal. Its Vendor select used to print all 856 vendors as
 * Flux options — 1.8 MB of the 2 MB sent back per pick, which made picking
 * times on /planner/cards crawl (Patryk, 2026-10-20). The modal now uses
 * <x-vendor-picker>, which loads the list once in the browser.
 */
function modalWeightCompanyProject(): Project
{
    $company = Vendor::factory()->create(['business_name' => 'GS Construction']);
    $user = User::query()->create([
        'first_name' => 'Owner', 'last_name' => 'User',
        'email' => 'owner.modal-weight-'.uniqid().'@example.com',
        'cell_phone' => (string) random_int(2000000000, 9999999999),
        'primary_vendor_id' => $company->id,
    ]);
    $company->users()->attach($user->id, ['is_employed' => true, 'role_id' => 1]);
    test()->actingAs($user);

    $client = Client::factory()->create();
    $client->vendors()->attach($company->id);

    return Project::query()->create([
        'project_name' => 'Kitchen', 'client_id' => $client->id, 'belongs_to_vendor_id' => $company->id,
        'address' => '348 N Pondview Dr', 'city' => 'Chicago', 'state' => 'IL', 'zip_code' => 60601,
    ]);
}

/**
 * Picking a start time made a round trip whose response re-keyed both
 * pickers; on hive.contractors the end took ages to appear and the modal then
 * stopped responding, so Create could not be pressed (Patryk, 2026-10-20).
 * The arrival times now live in the browser (task-arrival-times.js) and reach
 * the server with the save.
 */
it('keeps arrival time switches and pickers in the browser, with Create only busy while saving', function (): void {
    $project = modalWeightCompanyProject();
    $date = now()->addDays(2)->format('Y-m-d');

    Livewire::test(TaskCreate::class)
        ->call('addTask', $project->id, $date)
        ->assertSeeHtml("taskArrivalTimes(\$wire.entangle('form.time_settings'))")
        ->assertSeeHtml("x-model=\"day('{$date}').start_time\"")
        ->assertSeeHtml("x-model=\"day('{$date}').end_time\"")
        ->assertDontSeeHtml('wire:model.live="form.time_settings')
        ->assertDontSeeHtml('wire:model.live.self="form.time_settings')
        ->assertSeeHtml('wire:target="save"');
});

it('saves the arrival times the modal sends along with Create', function (): void {
    $project = modalWeightCompanyProject();
    $date = now()->addDays(2)->format('Y-m-d');

    Livewire::test(TaskCreate::class)
        ->call('addTask', $project->id, $date)
        ->set('form.title', 'Tile delivery')
        ->set([
            "form.time_settings.{$date}.use_time" => true,
            "form.time_settings.{$date}.start_time" => '09:00',
            "form.time_settings.{$date}.end_time" => '12:00',
        ])
        ->call('save')
        ->assertHasNoErrors();

    $task = \App\Models\Task::query()->where('project_id', $project->id)->where('title', 'Tile delivery')->sole();

    expect((array) $task->options->time_settings->{$date})
        ->toMatchArray(['use_time' => true, 'start_time' => '09:00', 'end_time' => '12:00']);
});

it('re-renders an arrival time pick without printing the vendor list', function (): void {
    $company = Vendor::factory()->create(['business_name' => 'GS Construction']);
    $user = User::query()->create([
        'first_name' => 'Owner', 'last_name' => 'User',
        'email' => 'owner.modal-weight-'.uniqid().'@example.com',
        'cell_phone' => (string) random_int(2000000000, 9999999999),
        'primary_vendor_id' => $company->id,
    ]);
    $company->users()->attach($user->id, ['is_employed' => true, 'role_id' => 1]);
    $this->actingAs($user);

    $client = Client::factory()->create();
    $client->vendors()->attach($company->id);
    $project = Project::query()->create([
        'project_name' => 'Kitchen', 'client_id' => $client->id, 'belongs_to_vendor_id' => $company->id,
        'address' => '348 N Pondview Dr', 'city' => 'Chicago', 'state' => 'IL', 'zip_code' => 60601,
    ]);

    foreach (range(1, 25) as $i) {
        $company->vendors()->attach(Vendor::factory()->create(['business_name' => sprintf('Listed Vendor %02d', $i)])->id);
    }

    $date = now()->addDays(2)->format('Y-m-d');
    $component = Livewire::test(TaskCreate::class)
        ->call('addTask', $project->id, $date)
        ->set("form.time_settings.{$date}.use_time", true)
        ->set("form.time_settings.{$date}.start_time", '09:00');

    expect($component->get("form.time_settings.{$date}.end_time"))->not->toBeEmpty();
    $component->assertSeeHtml('data-vendor-picker')
        ->assertSeeHtml('order=ytd')
        ->assertDontSee('Listed Vendor 01')
        ->assertDontSee('Listed Vendor 25');
});
