<?php

use App\Livewire\Leads\LeadCreate;
use App\Models\Client;
use App\Models\CompanyEmail;
use App\Models\Lead;
use App\Models\ProjectStatus;
use App\Models\Task;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function consultStatusFixture(): array
{
    config(['email_tracking.provider' => 'mailtrap']);

    $vendor = Vendor::factory()->create(['options' => ['short_name' => 'GSC']]);

    $admin = new User();
    $admin->forceFill([
        'first_name' => 'Patryk',
        'last_name' => 'Sender',
        'email' => 'status-admin.'.uniqid().'@example.test',
        'cell_phone' => fake()->unique()->numerify('224555####'),
        'primary_vendor_id' => $vendor->id,
    ]);
    $admin->save();
    $vendor->users()->attach($admin->id, ['role_id' => 1]);

    CompanyEmail::create(['vendor_id' => $vendor->id, 'email' => $admin->email, 'grant_id' => '']);

    $contact = User::query()->create([
        'first_name' => 'Kristin',
        'last_name' => 'White',
        'email' => 'kristin.'.uniqid().'@example.com',
        'cell_phone' => fake()->unique()->numerify('224888####'),
    ]);

    $client = Client::factory()->create();
    $client->vendors()->attach($vendor->id);
    $client->users()->attach($contact->id);

    $lead = Lead::create([
        'date' => now(),
        'origin' => 'gs.construction',
        'user_id' => $contact->id,
        'belongs_to_vendor_id' => $vendor->id,
        'created_by_user_id' => $contact->id,
        'lead_data' => [
            'name' => 'Kristin White',
            'address' => '166 Akenside Rd, Riverside, IL 60546',
            'message' => 'Addition',
            'email' => $contact->email,
        ],
    ]);
    $lead->statuses()->create(['title' => 'New', 'belongs_to_vendor_id' => $vendor->id]);

    return compact('vendor', 'admin', 'contact', 'client', 'lead');
}

function statusProposalDate(int $plusDays = 0): string
{
    return now()->addWeek()->startOfWeek()->addDays(2 + $plusDays)->format('Y-m-d');
}

function bookStatusConsult(array $fx, int $plusDays = 0): Task
{
    $component = Livewire::actingAs($fx['admin'])
        ->test(LeadCreate::class)
        ->call('editLead', $fx['lead']->id)
        ->set('to', [$fx['contact']->email])
        ->set('from', $fx['admin']->email)
        ->set('subject', 'Consultation')
        ->set('emailBody', '<p>See you soon</p>')
        ->set('proposeDate', statusProposalDate($plusDays));

    $options = $component->instance()->exactTimeOptions;
    $component
        ->call('selectExactTime', $options[0]['value'])
        ->set('projectName', 'Addition')
        ->call('send_message');

    return Task::withoutGlobalScopes()->where('type', 'Meet')->firstOrFail();
}

function latestStatusCode(int $projectId): int
{
    return (int) ProjectStatus::withoutGlobalScopes()
        ->where('project_id', $projectId)
        ->orderByDesc('start_date')
        ->orderByDesc('id')
        ->value('status_code');
}

it('parks a won lead\'s project in Consult while the meeting is still ahead', function () {
    Queue::fake();
    $fx = consultStatusFixture();

    $task = bookStatusConsult($fx);

    expect(latestStatusCode($task->project_id))->toBe(9);
});

it('advances the project to Estimate once the consult has passed', function () {
    Queue::fake();
    $fx = consultStatusFixture();
    $task = bookStatusConsult($fx);

    Carbon::setTestNow(Carbon::parse(statusProposalDate(), \App\Livewire\Leads\PickTimes::timezone())->addDay()->setTime(9, 0));

    $this->artisan('projects:advance-past-consults')->assertSuccessful();

    expect(latestStatusCode($task->project_id))->toBe(2);

    Carbon::setTestNow();
});

it('leaves the project in Consult while the meeting is still upcoming', function () {
    Queue::fake();
    $fx = consultStatusFixture();
    $task = bookStatusConsult($fx);

    $this->artisan('projects:advance-past-consults')->assertSuccessful();

    expect(latestStatusCode($task->project_id))->toBe(9);
});

it('advances a Consult project whose meeting was cancelled outright', function () {
    Queue::fake();
    $fx = consultStatusFixture();
    $task = bookStatusConsult($fx);

    Task::withoutGlobalScopes()->whereKey($task->id)->update(['deleted_at' => now()]);

    $this->artisan('projects:advance-past-consults')->assertSuccessful();

    expect(latestStatusCode($task->project_id))->toBe(2);
});

it('re-parks in Consult when a passed consult is rebooked to a future day', function () {
    Queue::fake();
    $fx = consultStatusFixture();
    $task = bookStatusConsult($fx);

    // Consult day passes; the hourly run advances the project.
    Carbon::setTestNow(Carbon::parse(statusProposalDate(), \App\Livewire\Leads\PickTimes::timezone())->addDay()->setTime(9, 0));
    $this->artisan('projects:advance-past-consults');
    expect(latestStatusCode($task->project_id))->toBe(2);

    // Office rebooks for later the same week: back to Consult.
    bookStatusConsult($fx, 2);
    expect(latestStatusCode($task->project_id))->toBe(9);

    Carbon::setTestNow();
});

it('backfills Consult onto Estimate projects still waiting on their consult', function () {
    Queue::fake();
    $fx = consultStatusFixture();
    $task = bookStatusConsult($fx);

    // Simulate the pre-feature world: the booking parked it, knock it back.
    ProjectStatus::withoutGlobalScopes()
        ->where('project_id', $task->project_id)
        ->where('status_code', 9)
        ->delete();
    expect(latestStatusCode($task->project_id))->toBe(2);

    $this->artisan('projects:backfill-consult-status')->assertSuccessful();
    expect(latestStatusCode($task->project_id))->toBe(9);

    // Idempotent: a second run finds nothing to park.
    $this->artisan('projects:backfill-consult-status')
        ->expectsOutputToContain('parked: 0')
        ->assertSuccessful();
});

it('does not backfill projects whose upcoming Meet is not a consult', function () {
    Queue::fake();
    $fx = consultStatusFixture();
    $task = bookStatusConsult($fx);

    ProjectStatus::withoutGlobalScopes()
        ->where('project_id', $task->project_id)
        ->where('status_code', 9)
        ->delete();
    Task::withoutGlobalScopes()->whereKey($task->id)->update(['title' => 'Walkthrough with framer']);

    $this->artisan('projects:backfill-consult-status')->assertSuccessful();

    expect(latestStatusCode($task->project_id))->toBe(2);
});

it('keeps Consult as a valid projects-index status filter', function () {
    Queue::fake();
    $fx = consultStatusFixture();

    Livewire::actingAs($fx['admin'])
        ->withQueryParams(['project_status_title' => 9])
        ->test(\App\Livewire\Projects\ProjectsIndex::class)
        ->assertSet('project_status_title', 9);
});

it('never drags a progressed project back to Consult when another meeting is booked', function () {
    Queue::fake();
    $fx = consultStatusFixture();
    $task = bookStatusConsult($fx);

    // The project moves on (Active) — a later meeting must not reset it.
    ProjectStatus::create([
        'project_id' => $task->project_id,
        'belongs_to_vendor_id' => $fx['vendor']->id,
        'status_code' => 6,
        'start_date' => today()->addDay()->format('Y-m-d'),
    ]);

    bookStatusConsult($fx, 2);

    expect(latestStatusCode($task->project_id))->toBe(6);

    $this->artisan('projects:advance-past-consults')->assertSuccessful();
    expect(latestStatusCode($task->project_id))->toBe(6);
});
