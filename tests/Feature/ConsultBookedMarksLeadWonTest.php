<?php

use App\Livewire\Leads\PickTimes;
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
 * A consult put on the calendar, or moved, converts its lead to Won whichever
 * screen did it. Carri Taraszka (2026-09-16): booked from the composer (Won),
 * re-picked times through the link (New, by design), consult moved to 2:00
 * in the task form — and the lead sat at New with a confirmed meeting.
 */
function consultWonFixture(string $status = 'New'): array
{
    $vendor = Vendor::factory()->create();
    $admin = new User();
    $admin->forceFill([
        'first_name' => 'Patryk', 'last_name' => 'Szady',
        'email' => 'won.'.uniqid().'@example.test',
        'cell_phone' => fake()->unique()->numerify('224555####'),
        'primary_vendor_id' => $vendor->id,
    ]);
    $admin->save();
    $vendor->users()->attach($admin->id, ['role_id' => 1]);
    auth()->login($admin); // TaskObserver stamps the creator from the session

    $contact = User::query()->create([
        'first_name' => 'Carri', 'last_name' => 'Taraszka',
        'email' => 'carri.'.uniqid().'@example.test', 'cell_phone' => fake()->unique()->numerify('847555####'),
    ]);
    $client = Client::factory()->create(['address' => '3395 Portshire', 'city' => 'Hoffman Estates', 'state' => 'IL', 'zip_code' => 60192]);
    $client->users()->attach($contact->id);
    $client->vendors()->attach($vendor->id);

    $project = Project::withoutEvents(fn () => Project::create([
        'project_name' => 'Kitchen', 'client_id' => $client->id,
        'address' => '3395 Portshire', 'city' => 'Hoffman Estates', 'state' => 'IL', 'zip_code' => '60192',
        'belongs_to_vendor_id' => $vendor->id,
    ]));
    $project->vendors()->attach($vendor->id, ['client_id' => $client->id]);
    $project->statuses()->create(['status_code' => 9, 'belongs_to_vendor_id' => $vendor->id, 'start_date' => now()->toDateString()]);

    $lead = Lead::withoutEvents(fn () => Lead::create([
        'date' => now(), 'origin' => 'Messages', 'user_id' => $contact->id,
        'lead_data' => ['name' => 'Carri Taraszka', 'email' => $contact->email],
        'belongs_to_vendor_id' => $vendor->id, 'created_by_user_id' => $admin->id,
    ]));
    $lead->statuses()->create(['title' => $status, 'belongs_to_vendor_id' => $vendor->id]);

    return compact('vendor', 'admin', 'contact', 'client', 'project', 'lead');
}

function bookConsultTask(array $fx, int $daysAhead = 3, string $title = 'GS Construction | Taraszka | Consult'): Task
{
    $date = now()->addDays($daysAhead)->format('Y-m-d');

    return Task::create([
        'title' => $title, 'project_id' => $fx['project']->id, 'type' => 'Meet',
        'start_date' => $date, 'end_date' => $date, 'order' => 0, 'user_ids' => [$fx['admin']->id],
        'options' => ['dates' => [$date], 'time_settings' => [$date => ['use_time' => true, 'start_time' => '13:00', 'end_time' => '13:30']]],
    ]);
}

function leadStatus(Lead $lead): ?string
{
    return Lead::withoutGlobalScopes()->find($lead->id)->last_status?->title;
}

it('marks the lead Won when a consult is put on its client\'s calendar from anywhere', function (string $status) {
    $fx = consultWonFixture($status);

    bookConsultTask($fx);

    expect(leadStatus($fx['lead']))->toBe('Won');
})->with(['New', 'Replied']);

it('marks the lead Won again when the consult is moved after they re-picked times', function () {
    $fx = consultWonFixture();
    $task = bookConsultTask($fx);
    expect(leadStatus($fx['lead']))->toBe('Won');

    // Their re-pick put the ball back with the office (PickTimes rule).
    $fx['lead']->setStatus('New');

    // The office moves the consult to 2:00 in the task form.
    $date = $task->start_date->format('Y-m-d');
    $task->update(['options' => ['dates' => [$date], 'time_settings' => [$date => ['use_time' => true, 'start_time' => '14:00', 'end_time' => '14:30']]]]);

    expect(leadStatus($fx['lead']))->toBe('Won');
});

it('leaves the lead alone for a task that is not an upcoming consult, and never touches a human decision', function () {
    $fx = consultWonFixture();

    bookConsultTask($fx, title: 'Walk-through');            // a Meet, not a consult
    bookConsultTask($fx, daysAhead: -2);                     // a consult that already happened
    expect(leadStatus($fx['lead']))->toBe('New');

    $fx['lead']->setStatus('Lost');
    bookConsultTask($fx);
    expect(leadStatus($fx['lead']))->toBe('Lost');
});

it('the deploy migration converts every New or Replied lead with a consult on the calendar, once', function () {
    Queue::fake(); // keep the observer quiet: this is about the leads already in that state

    $booked = consultWonFixture();
    bookConsultTask($booked);
    $booked['lead']->setStatus('New'); // re-picked after the booking

    $replied = consultWonFixture('Replied');
    bookConsultTask($replied);

    $noConsult = consultWonFixture();               // nothing on the calendar
    $past = consultWonFixture();
    bookConsultTask($past, daysAhead: -2);          // a consult that already happened
    $lost = consultWonFixture('Lost');
    bookConsultTask($lost);                         // a human decision stays

    $migration = require base_path('database/migrations/2026_09_16_030000_mark_confirmed_consult_leads_won.php');
    $migration->up();

    expect(leadStatus($booked['lead']))->toBe('Won')
        ->and(leadStatus($replied['lead']))->toBe('Won')
        ->and(leadStatus($noConsult['lead']))->toBe('New')
        ->and(leadStatus($past['lead']))->toBe('New')
        ->and(leadStatus($lost['lead']))->toBe('Lost');

    $migration->up();
    expect($booked['lead']->statuses()->where('title', 'Won')->count())->toBe(1);
});
