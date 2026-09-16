<?php

use App\Jobs\DeleteMeetTaskCalendarEvent;
use App\Models\Client;
use App\Models\Lead;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * Removing a lead takes its booked consultation with it: the Meet task is
 * deleted, which withdraws the calendar invite, and a project that existed
 * only for that consult goes too. Lead 170 (Josh Simmons, 2026-09-15) was
 * removed while its consult stayed on everyone's calendar.
 */
function consultRemovalFixture(int $daysAhead = 6): array
{
    Queue::fake();

    $vendor = Vendor::factory()->create();
    $admin = new User();
    $admin->forceFill([
        'first_name' => 'Patryk', 'last_name' => 'Szady',
        'email' => 'removal.'.uniqid().'@example.test',
        'cell_phone' => fake()->unique()->numerify('224555####'),
        'primary_vendor_id' => $vendor->id,
    ]);
    $admin->save();
    $vendor->users()->attach($admin->id, ['role_id' => 1]);
    // TaskObserver stamps the creator from the session.
    auth()->login($admin);

    $contact = User::query()->create([
        'first_name' => 'Josh', 'last_name' => 'Simmons',
        'email' => 'jsims.'.uniqid().'@example.test', 'cell_phone' => fake()->unique()->numerify('847555####'),
    ]);
    $client = Client::factory()->create(['address' => '6 Drake Terrace', 'city' => 'Prospect Heights', 'state' => 'IL', 'zip_code' => 60070]);
    $client->users()->attach($contact->id);
    $client->vendors()->attach($vendor->id);

    $project = Project::withoutEvents(fn () => Project::create([
        'project_name' => 'Basement', 'client_id' => $client->id,
        'address' => '6 Drake Terrace', 'city' => 'Prospect Heights', 'state' => 'IL', 'zip_code' => '60070',
        'belongs_to_vendor_id' => $vendor->id,
    ]));
    $project->vendors()->attach($vendor->id, ['client_id' => $client->id]);
    $project->statuses()->create(['status_code' => 9, 'belongs_to_vendor_id' => $vendor->id, 'start_date' => now()->toDateString()]);

    $date = now()->addDays($daysAhead)->format('Y-m-d');
    $task = Task::create([
        'title' => 'GS Construction | Simmons | Consult', 'project_id' => $project->id, 'type' => 'Meet',
        'start_date' => $date, 'end_date' => $date, 'order' => 0,
        'user_ids' => [$admin->id],
        'options' => [
            'dates' => [$date],
            'time_settings' => [$date => ['use_time' => true, 'start_time' => '10:00', 'end_time' => '10:30']],
            'nylas_meet_event' => ['event_id' => 'evt-simmons', 'grant_id' => 'grant-patryk', 'calendar_id' => 'cal-1'],
        ],
    ]);

    $lead = Lead::withoutEvents(fn () => Lead::create([
        'date' => now(), 'origin' => 'Email', 'user_id' => $contact->id,
        'lead_data' => ['name' => 'Josh Simmons', 'email' => $contact->email],
        'belongs_to_vendor_id' => $vendor->id, 'created_by_user_id' => $admin->id,
    ]));

    return compact('vendor', 'admin', 'contact', 'client', 'project', 'task', 'lead', 'date');
}

it('names the consult and the consult-only project the delete will take with it', function () {
    $fx = consultRemovalFixture();

    $impact = $fx['lead']->deleteImpact();

    expect($impact['booked_consult'])->toBeTrue()
        ->and($impact['consults'])->toBe([\Carbon\Carbon::parse($fx['date'])->format('M j').', 10:00 AM'])
        ->and($impact['consult_projects'])->toBe(['Basement'])
        // With the project gone the client has nothing left — it goes too.
        ->and($impact['clients'])->toBe([$fx['client']->name]);
});

it('removing the lead cancels the consult, withdraws the invite, and tidies the project and client', function () {
    $fx = consultRemovalFixture();

    $fx['lead']->deleteWithOrphans();

    // Task, project and lead soft-delete; the client row is removed outright.
    expect(Task::withoutGlobalScopes()->find($fx['task']->id)->deleted_at)->not->toBeNull()
        ->and(Project::withoutGlobalScopes()->find($fx['project']->id)->deleted_at)->not->toBeNull()
        ->and(Client::withoutGlobalScopes()->find($fx['client']->id))->toBeNull()
        ->and(Lead::withoutGlobalScopes()->find($fx['lead']->id)->deleted_at)->not->toBeNull();

    // Deleting the Meet task is what withdraws the calendar invite.
    Queue::assertPushed(DeleteMeetTaskCalendarEvent::class, fn ($job) => $job->taskId === $fx['task']->id);
});

it('leaves a project alone that holds more than the consult, and a consult already held', function () {
    $fx = consultRemovalFixture();
    Task::create(['title' => 'Demo day', 'project_id' => $fx['project']->id, 'type' => 'Task', 'order' => 1, 'user_ids' => [$fx['admin']->id]]);

    $impact = $fx['lead']->deleteImpact();
    expect($impact['consult_projects'])->toBe([])->and($impact['clients'])->toBe([]);

    $fx['lead']->deleteWithOrphans();
    expect(Task::withoutGlobalScopes()->find($fx['task']->id)->deleted_at)->not->toBeNull()
        ->and(Project::withoutGlobalScopes()->find($fx['project']->id)->deleted_at)->toBeNull();

    // A consult that already happened is history, not something to cancel.
    $past = consultRemovalFixture(daysAhead: -3);
    expect($past['lead']->deleteImpact()['consults'])->toBe([]);
    $past['lead']->deleteWithOrphans();
    expect(Task::withoutGlobalScopes()->find($past['task']->id)->deleted_at)->toBeNull();
});

it('cancels consults left behind by leads removed before removal did this, and only those', function () {
    $fx = consultRemovalFixture();
    $fx['lead']->delete(); // removed the old way: consult still on the calendar

    // Another contact whose lead was removed but who still has a live lead: theirs stays.
    $kept = consultRemovalFixture();
    $kept['lead']->delete();
    Lead::withoutEvents(fn () => Lead::create([
        'date' => now(), 'origin' => 'Email', 'user_id' => $kept['contact']->id,
        'lead_data' => ['name' => 'Josh Simmons'], 'belongs_to_vendor_id' => $kept['vendor']->id, 'created_by_user_id' => $kept['admin']->id,
    ]));

    $this->artisan('leads:cancel-orphaned-consults', ['--dry-run' => true])
        ->expectsOutputToContain('would be cancelled')
        ->assertSuccessful();
    expect(Task::withoutGlobalScopes()->find($fx['task']->id)->deleted_at)->toBeNull();

    $this->artisan('leads:cancel-orphaned-consults')
        ->expectsOutputToContain('1 consultation(s) were cancelled')
        ->assertSuccessful();

    expect(Task::withoutGlobalScopes()->find($fx['task']->id)->deleted_at)->not->toBeNull()
        ->and(Project::withoutGlobalScopes()->find($fx['project']->id)->deleted_at)->not->toBeNull()
        ->and(Task::withoutGlobalScopes()->find($kept['task']->id)->deleted_at)->toBeNull();
    Queue::assertPushed(DeleteMeetTaskCalendarEvent::class, 1);
});

it('the deploy migration runs the same clean-up once, without failing the deploy', function () {
    $fx = consultRemovalFixture();
    $fx['lead']->delete(); // removed the old way

    $migration = require base_path('database/migrations/2026_09_16_020000_cancel_consults_of_removed_leads.php');
    $migration->up();

    expect(Task::withoutGlobalScopes()->find($fx['task']->id)->deleted_at)->not->toBeNull();
    Queue::assertPushed(DeleteMeetTaskCalendarEvent::class, 1);

    // Nothing left to do: running it again is a no-op.
    $migration->up();
    Queue::assertPushed(DeleteMeetTaskCalendarEvent::class, 1);
});

