<?php

use App\Jobs\CreateMeetTaskCalendarEvent;
use App\Jobs\SendLeadReplyJob;
use App\Livewire\Leads\LeadCreate;
use App\Models\Client;
use App\Models\CompanyEmail;
use App\Models\Lead;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Vendor;
use Database\Seeders\LeadConsultTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * The next $count bookable weekdays for this lead — consults are weekdays
 * only, so "+1 day" can land on a Saturday.
 *
 * @return array<int, string>
 */
/**
 * Weekdays on which EVERY window is bookable. The first bookable date often
 * qualifies only for its later windows — 72h notice measured at 1pm lands
 * mid-day, so that day's "9-11 AM" is legitimately refused. Tests that toggle
 * a morning window need a day that clears the notice period outright, or they
 * pass or fail depending on the hour the suite runs.
 */
function bookableWeekdays(Lead $lead, int $count = 2): array
{
    $tz = \App\Livewire\Leads\PickTimes::timezone();
    $earliest = \App\Livewire\Leads\PickTimes::earliestStart($lead);
    $dayOpens = \App\Livewire\Leads\PickTimes::dayBounds()[0];
    $day = \Illuminate\Support\Carbon::parse(\App\Livewire\Leads\PickTimes::firstBookableDate($lead), $tz);
    $days = [];

    while (count($days) < $count) {
        $opensAt = \Illuminate\Support\Carbon::parse($day->format('Y-m-d').' '.$dayOpens, $tz);

        if (! $day->isWeekend() && $opensAt->greaterThanOrEqualTo($earliest)) {
            $days[] = $day->format('Y-m-d');
        }

        $day->addDay();
    }

    return $days;
}

it('creates the project and the Meet task when sending with a slot and exact time', function () {
    Queue::fake();
    $fx = makeConsultFixture();

    consultComposer($fx)
        ->call('insertAvailabilitySlot', 0)
        ->call('selectExactTime', '14:00')
        ->set('projectName', 'Kitchen Remodel')
        ->call('send_message');

    Queue::assertPushed(SendLeadReplyJob::class);
    Queue::assertPushed(CreateMeetTaskCalendarEvent::class);

    $project = Project::withoutGlobalScopes()->where('client_id', $fx['client']->id)->first();
    expect($project)->not->toBeNull()
        ->and($project->project_name)->toBe('Kitchen Remodel')
        ->and($project->city)->toBe('Palatine')
        ->and((string) $project->zip_code)->toBe('60067');

    $task = Task::withoutGlobalScopes()->where('project_id', $project->id)->first();
    expect($task)->not->toBeNull()
        ->and($task->title)->toBe('GSC | Singh | Consult')
        ->and($task->type)->toBe('Meet')
        ->and($task->start_date->toDateString())->toBe(now()->addDays(2)->format('Y-m-d'))
        // A consult is a 30-minute block: the window's start plus half an hour.
        ->and(data_get($task->options, 'time_settings.'.now()->addDays(2)->format('Y-m-d').'.start_time'))->toBe('14:00')
        ->and(data_get($task->options, 'time_settings.'.now()->addDays(2)->format('Y-m-d').'.end_time'))->toBe('14:30')
        // Booking the consult converts the lead: New -> Replied -> Won.
        ->and($fx['lead']->fresh()->last_status->title)->toBe('Won');
});

/** A project for the fixture client in the given lifecycle state. */
function clientProjectWithStatus(array $fx, string $name, int $statusCode): Project
{
    // Without events: the project observer stamps the vendor from the
    // signed-in user, and nobody is signed in while the fixture is built.
    $project = Project::withoutEvents(fn () => Project::query()->create([
        'project_name' => $name,
        'client_id' => $fx['client']->id,
        'address' => '123 Main St',
        'city' => 'Palatine',
        'state' => 'IL',
        'zip_code' => 60067,
        'belongs_to_vendor_id' => $fx['vendor']->id,
    ]));
    // A project is visible to a vendor's users through the project_vendor
    // pivot (ProjectScope), not through belongs_to_vendor_id alone.
    $project->vendors()->attach($fx['vendor']->id, ['client_id' => $fx['client']->id]);
    $project->statuses()->create([
        'status_code' => $statusCode,
        'start_date' => now()->subDays(30)->toDateString(),
        'belongs_to_vendor_id' => $fx['vendor']->id,
    ]);

    return $project;
}

it('books a consult for a lead with no linked contact when the client is known by address', function () {
    // Jeanne Bondi, 2026-09-15: a returning client's website enquiry was
    // filed unlinked, her finished projects were all the composer could
    // see, and the consult email went out with a time nobody booked.
    Queue::fake();
    $fx = makeConsultFixture();
    $fx['client']->forceFill(['address' => '123 Main St', 'city' => 'Palatine'])->save();
    $fx['lead']->forceFill(['user_id' => null])->save();
    clientProjectWithStatus($fx, 'Hall Bath', 7);   // Complete
    clientProjectWithStatus($fx, 'Powder Room', 10); // Cancelled

    $component = consultComposer($fx)
        ->call('insertAvailabilitySlot', 0)
        ->call('selectExactTime', '14:00');

    // Finished projects are not where a new consult goes: a name is asked for.
    expect($component->get('sendBlockedReason'))->toBe('Name the project for this consult first');

    $component->set('projectName', 'Primary Bedroom')->call('send_message');

    Queue::assertPushed(CreateMeetTaskCalendarEvent::class);
    $project = Project::withoutGlobalScopes()->where('client_id', $fx['client']->id)->where('project_name', 'Primary Bedroom')->first();
    expect($project)->not->toBeNull();
    $task = Task::withoutGlobalScopes()->where('project_id', $project->id)->where('type', 'Meet')->first();
    expect($task)->not->toBeNull()
        ->and(Task::withoutGlobalScopes()->whereIn('project_id', Project::withoutGlobalScopes()->where('client_id', $fx['client']->id)->where('project_name', '!=', 'Primary Bedroom')->pluck('id'))->count())->toBe(0)
        ->and($fx['lead']->fresh()->last_status->title)->toBe('Won');
});

it('puts the consult on the client\'s open project rather than a finished one', function () {
    Queue::fake();
    $fx = makeConsultFixture();
    clientProjectWithStatus($fx, 'Hall Bath', 7);            // Complete, older
    $open = clientProjectWithStatus($fx, 'Kitchen', 9);     // Consult, newer

    $component = consultComposer($fx)
        ->call('insertAvailabilitySlot', 0)
        ->call('selectExactTime', '14:00');

    // Proposed, not imposed: the open project is filled into the Project box.
    // The finished bathroom is not offered at all.
    expect($component->get('projectName'))->toBe('Kitchen — Consult')
        ->and($component->get('sendBlockedReason'))->toBeNull()
        ->and(collect($component->instance()->consultProjectOptions)->pluck('label')->all())->toBe(['Kitchen — Consult']);

    $component->call('send_message');

    expect(Task::withoutGlobalScopes()->where('type', 'Meet')->pluck('project_id')->all())->toBe([$open->id]);
});

it('offers only projects a consult can land on: Consult, Estimate or Cancelled, never one that was ever Complete', function () {
    Queue::fake();
    $fx = makeConsultFixture();
    clientProjectWithStatus($fx, 'Hall Bath', 7);     // Complete
    clientProjectWithStatus($fx, 'Master Suite', 6);  // Active
    $cancelled = clientProjectWithStatus($fx, 'Basement', 10); // Cancelled
    $estimate = clientProjectWithStatus($fx, 'Deck', 2);       // Estimate
    // Finished, then cancelled: it was Complete once, so it stays out.
    $reopened = clientProjectWithStatus($fx, 'Toilet', 7);
    $reopened->statuses()->create(['status_code' => 10, 'start_date' => now()->toDateString(), 'belongs_to_vendor_id' => $fx['vendor']->id]);
    $consult = clientProjectWithStatus($fx, 'Paint', 9);       // Consult, newest

    $component = consultComposer($fx)
        ->call('insertAvailabilitySlot', 0)
        ->call('selectExactTime', '14:00');

    $options = collect($component->instance()->consultProjectOptions);
    expect($options->pluck('label')->all())
        ->toBe(['Paint — Consult', 'Deck — Estimate', 'Basement — Cancelled'])
        // Each carries its stage as the badge the projects table uses.
        ->and($options->map(fn ($o) => [$o['name'], $o['stage'], $o['color']])->all())
        ->toBe([['Paint', 'Consult', 'purple'], ['Deck', 'Estimate', 'blue'], ['Basement', 'Cancelled', 'red']])
        // The newest open one is proposed; a Cancelled one is offered but never proposed.
        ->and($component->get('projectName'))->toBe('Paint — Consult');
    // …and the box renders them as badges, with the plain label as the pick value.
    $component->assertSeeHtml('value="Paint — Consult"')->assertSee('Consult');

    $consult->delete();
    $estimate->delete();
    $fresh = consultComposer($fx)->call('insertAvailabilitySlot', 0)->call('selectExactTime', '14:00');
    expect(collect($fresh->instance()->consultProjectOptions)->pluck('label')->all())->toBe(['Basement — Cancelled'])
        ->and($fresh->get('projectName'))->toBe('')
        ->and($fresh->get('sendBlockedReason'))->toBe('Name the project for this consult first');
    // Picking the cancelled one is allowed, and brings it back to Consult.
    $fresh->set('projectName', 'Basement — Cancelled')->call('send_message');
    expect(Task::withoutGlobalScopes()->where('type', 'Meet')->pluck('project_id')->all())->toBe([$cancelled->id]);
});

it('attaches the consult to whichever of the client\'s projects is chosen, or to a new one', function () {
    Queue::fake();
    $fx = makeConsultFixture();
    $hallBath = clientProjectWithStatus($fx, 'Hall Bath', 2); // Estimate
    clientProjectWithStatus($fx, 'Kitchen', 9);               // Consult

    // A project still at Estimate is a valid choice when the operator picks
    // it — by its suggested label, or by bare name in any case.
    $component = consultComposer($fx)
        ->call('insertAvailabilitySlot', 0)
        ->call('selectExactTime', '14:00')
        ->set('projectName', 'hall bath');

    expect($component->get('sendBlockedReason'))->toBeNull();
    $component->call('send_message');
    expect(Task::withoutGlobalScopes()->where('type', 'Meet')->pluck('project_id')->all())->toBe([$hallBath->id])
        // …and that project is in Consult for it.
        ->and((int) \App\Models\ProjectStatus::withoutGlobalScopes()->where('project_id', $hallBath->id)->orderByDesc('start_date')->orderByDesc('id')->value('status_code'))->toBe(9);

    // A finished project cannot be picked, even by its exact name: the
    // consult gets a new project of that name instead.
    $finished = clientProjectWithStatus($fx, 'Toilet', 7); // Complete
    Task::withoutGlobalScopes()->where('type', 'Meet')->forceDelete();
    $fx['lead']->setStatus('New');
    consultComposer($fx)
        ->call('insertAvailabilitySlot', 0)
        ->call('selectExactTime', '14:00')
        ->set('projectName', 'Toilet')
        ->call('send_message');
    $consultProject = Task::withoutGlobalScopes()->where('type', 'Meet')->value('project_id');
    expect($consultProject)->not->toBe($finished->id)
        ->and(Project::withoutGlobalScopes()->find($consultProject)->project_name)->toBe('Toilet');

    // A name matching none of the client's projects creates one — even with
    // an open project on file, and an emptied box asks for a name first.
    Task::withoutGlobalScopes()->where('type', 'Meet')->forceDelete();
    $fx['lead']->setStatus('New');
    $component = consultComposer($fx)
        ->call('insertAvailabilitySlot', 0)
        ->call('selectExactTime', '14:00')
        ->set('projectName', '');

    expect($component->get('sendBlockedReason'))->toBe('Name the project for this consult first');
    $component->set('projectName', 'Primary Bedroom')->call('send_message');

    $created = Project::withoutGlobalScopes()->where('client_id', $fx['client']->id)->where('project_name', 'Primary Bedroom')->first();
    expect($created)->not->toBeNull()
        ->and(Task::withoutGlobalScopes()->where('type', 'Meet')->pluck('project_id')->all())->toBe([$created->id]);

    // Another client's project name is just a new name for this client.
    $strangerClient = Client::factory()->create();
    $stranger = Project::withoutEvents(fn () => Project::query()->create([
        'project_name' => 'Elsewhere', 'client_id' => $strangerClient->id, 'address' => '1 Other St', 'city' => 'Cary', 'state' => 'IL', 'zip_code' => 60013, 'belongs_to_vendor_id' => $fx['vendor']->id,
    ]));
    Task::withoutGlobalScopes()->where('type', 'Meet')->forceDelete();
    $fx['lead']->setStatus('New');
    consultComposer($fx)
        ->call('insertAvailabilitySlot', 0)
        ->call('selectExactTime', '14:00')
        ->set('projectName', 'Elsewhere')
        ->call('send_message');
    $task = Task::withoutGlobalScopes()->where('type', 'Meet')->first();
    expect($task->project_id)->not->toBe($stranger->id)
        ->and(Project::withoutGlobalScopes()->find($task->project_id)->client_id)->toBe($fx['client']->id);
});

it('warns instead of pretending when a chosen time cannot be booked at all', function () {
    Queue::fake();
    $fx = makeConsultFixture();
    // No contact, and an address no client carries: nothing to book onto.
    $fx['lead']->forceFill(['user_id' => null])->save();
    $fx['lead']->update(['lead_data' => array_merge($fx['lead']->lead_data->toArray(), ['address' => '9 Nowhere Ln, Palatine, IL 60067'])]);

    consultComposer($fx)
        ->call('insertAvailabilitySlot', 0)
        ->call('selectExactTime', '14:00')
        ->call('send_message');

    Queue::assertPushed(SendLeadReplyJob::class);
    Queue::assertNotPushed(CreateMeetTaskCalendarEvent::class);
    expect(Task::withoutGlobalScopes()->where('type', 'Meet')->count())->toBe(0)
        // Not Won: nothing was booked.
        ->and($fx['lead']->fresh()->last_status->title)->toBe('Replied');
});

it('keeps the Message tab on a Replied lead but refuses to remove it', function () {
    Queue::fake();
    $fx = makeConsultFixture();
    $fx['lead']->setStatus('Replied');

    $component = Livewire::actingAs($fx['admin'])
        ->test(LeadCreate::class)
        ->call('editLead', $fx['lead']->id);

    expect($component->instance()->hasReplied)->toBeTrue();

    // The composer is there — a consult email is re-sent from it when the
    // first went unanswered — with its send buttons, and no Remove.
    $component->assertSee('name="messages"', false)
        ->assertSee('Send Email')
        ->assertDontSee('wire:click="confirmRemove"', false);

    // Remove is guarded server-side too.
    $component->call('confirmRemove')->assertSet('showLeadDelete', false);
    $component->call('remove');
    expect(Lead::withoutGlobalScopes()->find($fx['lead']->id))->not->toBeNull();
});

it('reverts a Replied lead back to New from the status dropdown', function () {
    Queue::fake();
    $fx = makeConsultFixture();
    $fx['lead']->setStatus('Replied');

    $component = Livewire::actingAs($fx['admin'])
        ->test(LeadCreate::class)
        ->call('editLead', $fx['lead']->id)
        ->set('lead_status', 'New');

    expect($fx['lead']->fresh()->last_status->title)->toBe('New')
        ->and($component->instance()->hasReplied)->toBeFalse();
});

it('does not downgrade an already-progressed lead on send', function () {
    Queue::fake();
    $fx = makeConsultFixture();
    $fx['lead']->setStatus('Won');

    consultComposer($fx)->call('send_message');

    Queue::assertPushed(SendLeadReplyJob::class);
    expect($fx['lead']->fresh()->last_status->title)->toBe('Won');
});

it('blocks sending while the template placeholder is unresolved (no slot picked)', function () {
    Queue::fake();
    (new LeadConsultTemplateSeeder)->run();
    $fx = makeConsultFixture();
    App\Models\EmailTemplate::withoutGlobalScopes()->where('name', 'Consult')->update(['vendor_id' => $fx['vendor']->id]);

    // Template auto-selected: body holds {{SELECT Availability}} until a slot
    // (and exact time) is picked — sending must be blocked.
    Livewire::actingAs($fx['admin'])
        ->test(LeadCreate::class)
        ->call('editLead', $fx['lead']->id)
        ->set('to', [$fx['contact']->email])
        ->set('from', $fx['admin']->email)
        ->call('send_message');

    Queue::assertNotPushed(SendLeadReplyJob::class);
});

it('blocks sending while the exact time is unpicked', function () {
    Queue::fake();
    (new LeadConsultTemplateSeeder)->run();
    $fx = makeConsultFixture();
    App\Models\EmailTemplate::withoutGlobalScopes()->where('name', 'Consult')->update(['vendor_id' => $fx['vendor']->id]);

    Livewire::actingAs($fx['admin'])
        ->test(LeadCreate::class)
        ->call('editLead', $fx['lead']->id)
        ->set('to', [$fx['contact']->email])
        ->set('from', $fx['admin']->email)
        ->call('insertAvailabilitySlot', 0)
        ->call('send_message');

    Queue::assertNotPushed(SendLeadReplyJob::class);
    expect(Project::withoutGlobalScopes()->where('client_id', $fx['client']->id)->exists())->toBeFalse();
});

it('books the Meet task at the exact time when one is picked within the slot', function () {
    Queue::fake();
    $fx = makeConsultFixture();

    $component = consultComposer($fx)->call('insertAvailabilitySlot', 0);

    expect(array_column($component->instance()->exactTimeOptions, 'label'))
        ->toBe(['1:00 PM', '1:30 PM', '2:00 PM', '2:30 PM']);

    $component->call('selectExactTime', '14:30')->set('projectName', 'Consult')->call('send_message');

    $task = Task::withoutGlobalScopes()->where('title', 'GSC | Singh | Consult')->first();
    expect($task)->not->toBeNull()
        ->and(data_get($task->options, 'time_settings.'.now()->addDays(2)->format('Y-m-d').'.start_time'))->toBe('14:30')
        ->and(data_get($task->options, 'time_settings.'.now()->addDays(2)->format('Y-m-d').'.end_time'))->toBe('15:00');
});

it('offers the whole bookable day as exact times when the slot is Anytime', function () {
    Queue::fake();
    // "Anytime" is not a parseable window, so it used to yield no exact-time
    // chips at all and the email confirmed a literal "Anytime" back to the
    // client. It means the whole bookable day instead.
    $fx = makeConsultFixture(['date' => now()->addDays(2)->format('Y-m-d'), 'time' => 'Anytime']);

    $component = consultComposer($fx)->call('insertAvailabilitySlot', 0);

    expect(array_column($component->instance()->exactTimeOptions, 'value'))
        ->toBe(['07:00', '07:30', '08:00', '08:30', '09:00', '09:30', '10:00', '10:30',
            '11:00', '11:30', '12:00', '12:30', '13:00', '13:30', '14:00', '14:30']);

    // A time inside the day sticks; one outside it is still refused.
    expect($component->call('selectExactTime', '10:30')->get('selectedExactTime'))->toBe('10:30')
        ->and($component->call('selectExactTime', '19:00')->get('selectedExactTime'))->toBe('10:30');
});

it('books an Anytime consult at the picked time, not the whole day', function () {
    Queue::fake();
    $fx = makeConsultFixture(['date' => now()->addDays(2)->format('Y-m-d'), 'time' => 'Anytime']);

    consultComposer($fx)
        ->call('insertAvailabilitySlot', 0)
        ->call('selectExactTime', '09:00')
        ->set('projectName', 'Consult')
        ->call('send_message');

    $task = Task::withoutGlobalScopes()->where('title', 'GSC | Singh | Consult')->first();
    $day = now()->addDays(2)->format('Y-m-d');

    expect($task)->not->toBeNull()
        ->and(data_get($task->options, 'time_settings.'.$day.'.use_time'))->toBeTrue()
        ->and(data_get($task->options, 'time_settings.'.$day.'.start_time'))->toBe('09:00')
        ->and(data_get($task->options, 'time_settings.'.$day.'.end_time'))->toBe('09:30');
});

it('derives the bookable day from the windows clients pick from', function () {
    // dayBounds() must track WINDOWS — if a window moves, "Anytime" moves too.
    $windows = collect(\App\Livewire\Leads\PickTimes::WINDOWS)
        ->reject(fn ($w) => $w === 'Anytime')
        ->map(fn ($w) => \App\Models\Lead::parseSlotTimes($w));

    expect(\App\Livewire\Leads\PickTimes::dayBounds())
        ->toBe([$windows->min(fn ($r) => $r[0]), $windows->max(fn ($r) => $r[1])]);
});

it('rejects an exact time outside the slot window', function () {
    Queue::fake();
    $fx = makeConsultFixture();

    $component = consultComposer($fx)
        ->call('insertAvailabilitySlot', 0)
        ->call('selectExactTime', '19:00');

    expect($component->get('selectedExactTime'))->toBeNull();
});

it('blocks sending until the new project is named', function () {
    Queue::fake();
    $fx = makeConsultFixture();

    $component = consultComposer($fx)
        ->call('insertAvailabilitySlot', 0)
        ->call('selectExactTime', '14:00');

    expect($component->instance()->needsProjectName)->toBeTrue();

    $component->call('send_message');
    Queue::assertNotPushed(SendLeadReplyJob::class);

    $component->set('projectName', 'Bathroom Addition')->call('send_message');
    Queue::assertPushed(SendLeadReplyJob::class);
    expect(Project::withoutGlobalScopes()->where('client_id', $fx['client']->id)->value('project_name'))
        ->toBe('Bathroom Addition');
});

it('needs no project name when the client already has a project', function () {
    Queue::fake();
    $fx = makeConsultFixture();
    Livewire::actingAs($fx['admin']); // ProjectObserver reads auth()->user()

    $existing = null;
    test()->actingAs($fx['admin']);
    $existing = Project::create(['project_name' => 'Existing Job', 'client_id' => $fx['client']->id, 'address' => '5 Oak St', 'city' => 'Palatine', 'state' => 'IL', 'zip_code' => '60067']);

    $component = consultComposer($fx)
        ->call('insertAvailabilitySlot', 0)
        ->call('selectExactTime', '14:00');

    expect($component->instance()->needsProjectName)->toBeFalse();

    $component->call('send_message');
    Queue::assertPushed(SendLeadReplyJob::class);

    $task = Task::withoutGlobalScopes()->where('title', 'GSC | Singh | Consult')->first();
    expect($task->project_id)->toBe($existing->id)
        ->and(Project::withoutGlobalScopes()->where('client_id', $fx['client']->id)->count())->toBe(1);
});

it('does not duplicate the project or task on a resend', function () {
    Queue::fake();
    $fx = makeConsultFixture();

    consultComposer($fx)->call('insertAvailabilitySlot', 0)->call('selectExactTime', '14:00')->set('projectName', 'Consult')->call('send_message');
    consultComposer($fx)->call('insertAvailabilitySlot', 0)->call('selectExactTime', '14:00')->set('projectName', 'Consult')->call('send_message');

    expect(Project::withoutGlobalScopes()->where('client_id', $fx['client']->id)->count())->toBe(1)
        ->and(Task::withoutGlobalScopes()->where('title', 'GSC | Singh | Consult')->count())->toBe(1);
});

it('moves the existing consult when the client reschedules', function () {
    Queue::fake();
    $fx = makeConsultFixture();
    $firstDay = now()->addDays(2)->format('Y-m-d');

    consultComposer($fx)->call('insertAvailabilitySlot', 0)->call('selectExactTime', '14:00')
        ->set('projectName', 'Consult')->call('send_message');

    // The client replies with new availability; we pick one of the new times.
    $secondDay = now()->addDays(9)->format('Y-m-d');
    $lead = $fx['lead']->fresh();
    $data = $lead->lead_data;
    $data['availability'] = [['date' => $secondDay, 'time' => '9-11 AM']];
    $lead->lead_data = $data;
    $lead->save();

    consultComposer($fx)->call('insertAvailabilitySlot', 0)->call('selectExactTime', '10:30')->call('send_message');

    // One consult, moved — not a second task and not the stale first date.
    $tasks = Task::withoutGlobalScopes()->where('title', 'GSC | Singh | Consult')->get();
    expect($tasks)->toHaveCount(1);

    $task = $tasks->first();
    expect($task->start_date->format('Y-m-d'))->toBe($secondDay)
        ->and($task->end_date->format('Y-m-d'))->toBe($secondDay)
        ->and(data_get($task->options, 'time_settings.'.$secondDay.'.start_time'))->toBe('10:30')
        ->and(data_get($task->options, 'time_settings.'.$secondDay.'.end_time'))->toBe('11:00')
        // The old day's time settings must not linger.
        ->and(data_get($task->options, 'time_settings.'.$firstDay))->toBeNull()
        ->and(data_get($task->options, 'dates'))->toBe([$secondDay]);
});

it('creates the calendar event when a rescheduled consult never got one', function () {
    Queue::fake();
    $fx = makeConsultFixture();

    consultComposer($fx)->call('insertAvailabilitySlot', 0)->call('selectExactTime', '14:00')
        ->set('projectName', 'Consult')->call('send_message');

    $secondDay = now()->addDays(9)->format('Y-m-d');
    $lead = $fx['lead']->fresh();
    $data = $lead->lead_data;
    $data['availability'] = [['date' => $secondDay, 'time' => '9-11 AM']];
    $lead->lead_data = $data;
    $lead->save();

    consultComposer($fx)->call('insertAvailabilitySlot', 0)->call('selectExactTime', '10:30')->call('send_message');

    // No nylas_meet_event was ever stored (no grant in tests), so the move must
    // fall back to creating the event rather than updating a missing one.
    Queue::assertPushed(\App\Jobs\CreateMeetTaskCalendarEvent::class, 2);
    Queue::assertNotPushed(\App\Jobs\UpdateMeetTaskCalendarEvent::class);
});

it('revives a deleted consult when a new date is booked', function () {
    Queue::fake();
    $fx = makeConsultFixture();

    consultComposer($fx)->call('insertAvailabilitySlot', 0)->call('selectExactTime', '14:00')
        ->set('projectName', 'Consult')->call('send_message');

    // The first date didn't work, so the consult was deleted — booking a new
    // date must bring the SAME task back, not leave it dead or duplicate it.
    $first = Task::withoutGlobalScopes()->where('title', 'GSC | Singh | Consult')->firstOrFail();
    $first->delete();

    $secondDay = now()->addDays(9)->format('Y-m-d');
    $lead = $fx['lead']->fresh();
    $data = $lead->lead_data;
    $data['availability'] = [['date' => $secondDay, 'time' => '9-11 AM']];
    $lead->lead_data = $data;
    $lead->save();

    consultComposer($fx)->call('insertAvailabilitySlot', 0)->call('selectExactTime', '10:30')->call('send_message');

    $live = Task::query()->where('title', 'GSC | Singh | Consult')->get();
    expect($live)->toHaveCount(1)
        ->and($live->first()->id)->toBe($first->id)
        ->and($live->first()->deleted_at)->toBeNull()
        ->and($live->first()->start_date->format('Y-m-d'))->toBe($secondDay)
        ->and(data_get($live->first()->options, 'time_settings.'.$secondDay.'.start_time'))->toBe('10:30')
        ->and(data_get($live->first()->options, 'time_settings.'.$secondDay.'.end_time'))->toBe('11:00');
});

it('sends recipients an updated calendar invite when the consult is rescheduled', function () {
    Queue::fake();
    $fx = makeConsultFixture();

    consultComposer($fx)->call('insertAvailabilitySlot', 0)->call('selectExactTime', '14:00')
        ->set('projectName', 'Consult')->call('send_message');

    // The booking job normally stores the Nylas event metadata; the queue is
    // faked here, so stamp what a created event leaves behind.
    $task = Task::withoutGlobalScopes()->where('title', 'GSC | Singh | Consult')->firstOrFail();
    $options = (array) $task->options;
    $options['nylas_meet_event'] = ['event_id' => 'evt-1', 'grant_id' => 'grant-1', 'calendar_id' => 'cal-1'];
    $task->updateQuietly(['options' => $options]);

    $secondDay = now()->addDays(9)->format('Y-m-d');
    $lead = $fx['lead']->fresh();
    $data = $lead->lead_data;
    $data['availability'] = [['date' => $secondDay, 'time' => '9-11 AM']];
    $lead->lead_data = $data;
    $lead->save();

    consultComposer($fx)->call('insertAvailabilitySlot', 0)->call('selectExactTime', '10:30')->call('send_message');

    // The move goes out as an UPDATE to the existing event — Nylas is called
    // with notify_participants=true, so attendees receive the updated invite
    // (and updateMeetEvent falls back to creating a fresh one if the event id
    // is stale).
    Queue::assertPushed(\App\Jobs\UpdateMeetTaskCalendarEvent::class, 1);
    Queue::assertPushed(\App\Jobs\CreateMeetTaskCalendarEvent::class, 1); // the original booking only
});

it('books nothing when no availability slot is selected', function () {
    Queue::fake();
    $fx = makeConsultFixture();

    consultComposer($fx)->call('send_message');

    Queue::assertPushed(SendLeadReplyJob::class);
    expect(Project::withoutGlobalScopes()->where('client_id', $fx['client']->id)->exists())->toBeFalse()
        ->and(Task::withoutGlobalScopes()->where('title', 'GSC | Singh | Consult')->exists())->toBeFalse();
});

it('keeps the meeting date without fixed times when the slot time is unparseable', function () {
    Queue::fake();

    // Relative, not a hardcoded date: a fixed day silently becomes unbookable
    // once it slips into the past and the booking stops happening.
    $day = now()->addDays(4)->format('Y-m-d');
    $fx = makeConsultFixture(['date' => $day, 'time' => 'sometime in the afternoon']);

    consultComposer($fx)->call('insertAvailabilitySlot', 0)->call('send_message');

    $task = Task::withoutGlobalScopes()->where('title', 'GSC | Singh | Consult')->first();
    expect($task)->not->toBeNull()
        ->and(data_get($task->options, 'time_settings.'.$day.'.use_time'))->toBeFalse();
});

it('renders the seeded Consult template with the selected slot', function () {
    Queue::fake();
    (new LeadConsultTemplateSeeder)->run();
    $fx = makeConsultFixture();
    // The seeder targets vendor 1 (production); scope it to the test vendor.
    App\Models\EmailTemplate::withoutGlobalScopes()->where('name', 'Consult')->update(['vendor_id' => $fx['vendor']->id]);

    $component = Livewire::actingAs($fx['admin'])
        ->test(LeadCreate::class)
        ->call('editLead', $fx['lead']->id);

    expect($component->get('subject'))->toContain('Consultation');

    $component->call('insertAvailabilitySlot', 0);

    // Slot alone holds the email back until the exact time is picked.
    expect($component->get('emailBody'))->toContain('{{SELECT Time}}')
        ->and($component->get('emailBody'))->toContain('Hi Preet');

    $component->call('selectExactTime', '14:00');

    expect($component->get('emailBody'))->toContain(now()->addDays(2)->format('D, M j').' · 2:00 PM');
});

// ─── Stale / missing availability → pick-new-times flow ─────────────────────

/** A lead template using the composite time block, auto-picked by editLead. */
function makeConsultTemplate(array $fx): void
{
    \App\Models\EmailTemplate::create([
        'vendor_id' => $fx['vendor']->id,
        'type' => 'lead',
        'name' => 'Consult',
        'subject' => 'Consultation',
        'body' => '<p>{{lead_time_block}}</p>',
    ]);
}

it('offers the signed pick-new-times link when every preferred slot has passed', function () {
    $fx = makeConsultFixture(['date' => now()->subDays(3)->format('Y-m-d'), 'time' => '1-3 PM']);
    makeConsultTemplate($fx);

    $component = Livewire::actingAs($fx['admin'])
        ->test(LeadCreate::class)
        ->call('editLead', $fx['lead']->id);
    $body = $component->get('emailBody');

    expect($body)->toContain('select new consultation times')
        ->and($body)->toMatch('/lead\/times\/'.$fx['lead']->id.'\?expires=\d+/')
        ->and($body)->not->toContain('{{SELECT')
        ->and($component->get('sendBlockedReason'))->toBeNull();
});

it('offers the link when the lead never gave availability', function () {
    $fx = makeConsultFixture();
    makeConsultTemplate($fx);
    $data = $fx['lead']->lead_data;
    $data['availability'] = [];
    $fx['lead']->lead_data = $data;
    $fx['lead']->save();

    $body = Livewire::actingAs($fx['admin'])
        ->test(LeadCreate::class)
        ->call('editLead', $fx['lead']->id)
        ->get('emailBody');

    // Nothing "new" about times they never picked in the first place — and
    // one "we'd love" per email is plenty.
    expect($body)->toContain('select consultation times')
        ->and($body)->not->toContain('select new consultation times')
        ->and($body)->toContain('find a time that works for you')
        ->and(substr_count(strtolower($body), 'love'))->toBeLessThanOrEqual(1)
        ->and($body)->toMatch('/lead\/times\/'.$fx['lead']->id.'\?expires=\d+/');
});

it('refuses to select a slot whose date has passed', function () {
    $fx = makeConsultFixture(['date' => now()->subDay()->format('Y-m-d'), 'time' => '1-3 PM']);

    $component = consultComposer($fx)->call('insertAvailabilitySlot', 0);

    expect($component->get('selectedAvailability'))->toBe([]);
});

it('lets the lead submit new times through the signed page', function () {
    $fx = makeConsultFixture(['date' => now()->subDay()->format('Y-m-d'), 'time' => '1-3 PM']);

    [$date, $date2] = bookableWeekdays($fx['lead']);

    Livewire::test(\App\Livewire\Leads\PickTimes::class, ['lead' => $fx['lead']->id])
        ->set('date', $date)
        ->call('toggleWindow', '9-11 AM')
        ->call('toggleWindow', '1-3 PM')
        ->set('date', $date2)
        ->call('toggleWindow', 'Anytime')
        ->call('submit')
        ->assertSet('submitted', true);

    $fresh = Lead::withoutGlobalScopes()->find($fx['lead']->id);
    expect($fresh->lead_data['availability'][0])->toMatchArray(['date' => $date, 'time' => '9-11 AM'])
        ->and($fresh->lead_data['availability'][1])->toMatchArray(['date' => $date, 'time' => '1-3 PM'])
        ->and($fresh->lead_data['availability'][2])->toMatchArray(['date' => $date2, 'time' => 'Anytime']);
});

it('requires at least three times across two different days', function () {
    $fx = makeConsultFixture();

    [$d1, $d2] = bookableWeekdays($fx['lead']);

    // 3 times but one day -> blocked
    Livewire::test(\App\Livewire\Leads\PickTimes::class, ['lead' => $fx['lead']->id])
        ->set('date', $d1)
        ->call('toggleWindow', '7-9 AM')
        ->call('toggleWindow', '9-11 AM')
        ->call('toggleWindow', '1-3 PM')
        ->call('submit')
        ->assertHasErrors('times')
        ->assertSet('submitted', false);

    // 2 days but only 2 times -> blocked
    Livewire::test(\App\Livewire\Leads\PickTimes::class, ['lead' => $fx['lead']->id])
        ->set('date', $d1)
        ->call('toggleWindow', '1-3 PM')
        ->set('date', $d2)
        ->call('toggleWindow', '1-3 PM')
        ->call('submit')
        ->assertHasErrors('times')
        ->assertSet('submitted', false);
});

it('rejects the picker page without a valid signature', function () {
    $fx = makeConsultFixture();

    $this->get('/lead/times/'.$fx['lead']->id)->assertForbidden();
    $this->get($fx['lead']->availabilityUrl())->assertOk();
});

it('rejects past dates and caps the number of picker slots', function () {
    $fx = makeConsultFixture();

    $picker = Livewire::test(\App\Livewire\Leads\PickTimes::class, ['lead' => $fx['lead']->id])
        ->set('date', now()->subDay()->format('Y-m-d'))
        ->call('toggleWindow', '1-3 PM')
        ->assertHasErrors('date');

    expect($picker->get('times'))->toBe([]);

    foreach (bookableWeekdays($fx['lead'], \App\Livewire\Leads\PickTimes::MAX_SLOTS + 1) as $day) {
        $picker->set('date', $day)->call('toggleWindow', '1-3 PM');
    }

    expect(count($picker->get('times')))->toBe(\App\Livewire\Leads\PickTimes::MAX_SLOTS);
});

it('treats Anytime as the whole day, like the vendor availability flow', function () {
    $fx = makeConsultFixture();
    [$day, $day2] = bookableWeekdays($fx['lead']);

    $picker = Livewire::test(\App\Livewire\Leads\PickTimes::class, ['lead' => $fx['lead']->id])
        ->set('date', $day)
        ->call('toggleWindow', '7-9 AM')
        ->call('toggleWindow', '1-3 PM')
        // Anytime supersedes the day's specific windows...
        ->call('toggleWindow', 'Anytime');

    expect($picker->get('times'))->toBe([['date' => $day, 'time' => 'Anytime']]);

    // ...and a specific window replaces the day's Anytime, without touching
    // other days.
    $picker->set('date', $day2)->call('toggleWindow', 'Anytime')
        ->set('date', $day)->call('toggleWindow', '1-3 PM');

    expect($picker->get('times'))->toBe([
        ['date' => $day, 'time' => '1-3 PM'],
        ['date' => $day2, 'time' => 'Anytime'],
    ]);
});

it('rejects times less than 72 hours ahead', function () {
    $fx = makeConsultFixture();

    // Tomorrow fails date validation outright.
    Livewire::test(\App\Livewire\Leads\PickTimes::class, ['lead' => $fx['lead']->id])
        ->set('date', now()->addDay()->format('Y-m-d'))
        ->call('toggleWindow', '1-3 PM')
        ->assertHasErrors('date');

    // Beyond the boundary is fine (first bookable weekday).
    [$ok] = bookableWeekdays($fx['lead']);

    $picker = Livewire::test(\App\Livewire\Leads\PickTimes::class, ['lead' => $fx['lead']->id])
        ->set('date', $ok)
        ->call('toggleWindow', '1-3 PM');

    expect(count($picker->get('times')))->toBe(1);
});

it('uses the company timezone, not the visitor timezone, for the 72h rule', function () {
    $fx = makeConsultFixture();

    // A guest page has no browser-timezone session; the rule must still be
    // Central (config), not the UTC fallback.
    session()->forget('browser.timezone');

    expect(\App\Livewire\Leads\PickTimes::timezone())
        ->toBe(config('sms.business_hours.timezone'));

    // Every window offered on the calendar's first selectable day must be
    // selectable — otherwise the page shows a day that only errors.
    $first = \App\Livewire\Leads\PickTimes::firstBookableDate($fx['lead']);

    $selectable = collect(\App\Livewire\Leads\PickTimes::WINDOWS)
        ->filter(function (string $window) use ($fx, $first) {
            return Livewire::test(\App\Livewire\Leads\PickTimes::class, ['lead' => $fx['lead']->id])
                ->set('date', $first)
                ->call('toggleWindow', $window)
                ->get('times') !== [];
        });

    expect($selectable)->not->toBeEmpty();
});

it('counts Anytime as two times toward the minimum', function () {
    $fx = makeConsultFixture();
    [$d1, $d2] = bookableWeekdays($fx['lead']);

    $picker = fn () => Livewire::test(\App\Livewire\Leads\PickTimes::class, ['lead' => $fx['lead']->id]);

    // Two days of Anytime = 4 -> passes.
    expect($picker()->set('date', $d1)->call('toggleWindow', 'Anytime')
        ->set('date', $d2)->call('toggleWindow', 'Anytime')
        ->get('canSubmit'))->toBeTrue();

    // Anytime + one window on another day = 3 -> passes.
    expect($picker()->set('date', $d1)->call('toggleWindow', 'Anytime')
        ->set('date', $d2)->call('toggleWindow', '1-3 PM')
        ->get('canSubmit'))->toBeTrue();

    // Anytime on a single day is worth 2 but only one day -> still blocked.
    expect($picker()->set('date', $d1)->call('toggleWindow', 'Anytime')
        ->get('canSubmit'))->toBeFalse();

    // Two plain windows across two days = 2 -> still blocked.
    expect($picker()->set('date', $d1)->call('toggleWindow', '7-9 AM')
        ->set('date', $d2)->call('toggleWindow', '1-3 PM')
        ->get('canSubmit'))->toBeFalse();
});

it('moves a Replied lead back to New when the client submits new times', function () {
    $fx = makeConsultFixture();
    $fx['lead']->setStatus('Replied');

    [$d1, $d2] = bookableWeekdays($fx['lead']);

    Livewire::test(\App\Livewire\Leads\PickTimes::class, ['lead' => $fx['lead']->id])
        ->set('date', $d1)->call('toggleWindow', 'Anytime')
        ->set('date', $d2)->call('toggleWindow', 'Anytime')
        ->call('submit')
        ->assertSet('submitted', true);

    $fresh = Lead::withoutGlobalScopes()->find($fx['lead']->id);
    $fresh->unsetRelation('last_status');

    expect($fresh->last_status?->title)->toBe('New');
});

it('moves a Won lead back to New when the client reschedules — the booking no longer stands', function () {
    $fx = makeConsultFixture();
    $fx['lead']->setStatus('Won');

    [$d1, $d2] = bookableWeekdays($fx['lead']);

    Livewire::test(\App\Livewire\Leads\PickTimes::class, ['lead' => $fx['lead']->id])
        ->set('date', $d1)->call('toggleWindow', 'Anytime')
        ->set('date', $d2)->call('toggleWindow', 'Anytime')
        ->call('submit')
        ->assertSet('submitted', true);

    $fresh = Lead::withoutGlobalScopes()->find($fx['lead']->id);
    $fresh->unsetRelation('last_status');

    // Same path a Replied lead takes: the row stops reading Won, the badge
    // counts it, and the composer is back so the office can confirm the new
    // time — which sets it to Won again.
    expect($fresh->last_status?->title)->toBe('New')
        ->and($fresh->hasRescheduled())->toBeTrue();
});

it('leaves a Lost or Not a Fit lead alone when times are submitted', function (string $status) {
    $fx = makeConsultFixture();
    $fx['lead']->setStatus($status);

    [$d1, $d2] = bookableWeekdays($fx['lead']);

    Livewire::test(\App\Livewire\Leads\PickTimes::class, ['lead' => $fx['lead']->id])
        ->set('date', $d1)->call('toggleWindow', 'Anytime')
        ->set('date', $d2)->call('toggleWindow', 'Anytime')
        ->call('submit');

    $fresh = Lead::withoutGlobalScopes()->find($fx['lead']->id);
    $fresh->unsetRelation('last_status');

    expect($fresh->last_status?->title)->toBe($status);
})->with(['Lost', 'Not a Fit']);

it('drops the follow-up line when no time was proposed', function () {
    $fx = makeConsultFixture(['date' => now()->subDays(3)->format('Y-m-d'), 'time' => '1-3 PM']);
    makeConsultTemplate($fx);

    $body = Livewire::actingAs($fx['admin'])
        ->test(LeadCreate::class)
        ->call('editLead', $fx['lead']->id)
        ->get('emailBody');

    expect($body)->toContain('select new consultation times')
        ->and($body)->not->toContain('If this time no longer works for you');
});

it('links the picker from the confirm-a-time follow-up too', function () {
    $fx = makeConsultFixture();
    makeConsultTemplate($fx);

    $body = Livewire::actingAs($fx['admin'])
        ->test(LeadCreate::class)
        ->call('editLead', $fx['lead']->id)
        ->get('emailBody');

    // A time IS proposed here, and the follow-up offers self-service instead
    // of "just reply to this email".
    expect($body)->toContain('If this time no longer works for you')
        ->and($body)->toContain('pick new consultation times')
        ->and($body)->toMatch('/lead\/times\/'.$fx['lead']->id.'\?expires=\d+/')
        ->and($body)->not->toContain('just reply to this email');
});

it('thanks the client for rescheduling once they have sent new times', function () {
    $fx = makeConsultFixture();
    \App\Models\EmailTemplate::create([
        'vendor_id' => $fx['vendor']->id,
        'type' => 'lead',
        'name' => 'Consult',
        'subject' => 'Consultation',
        'body' => '<p>{{lead_intro}}</p><p>{{lead_time_block}}</p>',
    ]);

    $compose = fn () => Livewire::actingAs($fx['admin'])
        ->test(LeadCreate::class)
        ->call('editLead', $fx['lead']->id)
        ->get('emailBody');

    // First contact.
    expect($compose())->toContain('Thank you for reaching out')
        ->and($compose())->toContain('Based on the availability you shared')
        ->and($compose())->not->toContain('new availability');

    // After they resubmit through the picker.
    [$d1, $d2] = bookableWeekdays($fx['lead']);

    Livewire::test(\App\Livewire\Leads\PickTimes::class, ['lead' => $fx['lead']->id])
        ->set('date', $d1)->call('toggleWindow', 'Anytime')
        ->set('date', $d2)->call('toggleWindow', 'Anytime')
        ->call('submit');

    expect($compose())->toContain('Thank you for sending over your new availability')
        ->and($compose())->toContain('Based on your updated availability')
        ->and($compose())->not->toContain('Thank you for reaching out');
});

it('does not thank a first-time picker for rescheduling — that comes with the second set of times', function () {
    $fx = makeConsultFixture();
    \App\Models\EmailTemplate::create([
        'vendor_id' => $fx['vendor']->id, 'type' => 'lead', 'name' => 'Consult', 'subject' => 'Consultation',
        'body' => '<p>{{lead_intro}}</p><p>{{lead_time_block}}</p>',
    ]);

    // A lead texted the link before ever giving times (Carri & Alan, 2026-09-15).
    $data = $fx['lead']->lead_data;
    unset($data['availability']);
    $fx['lead']->lead_data = $data;
    $fx['lead']->save();

    $compose = fn () => Livewire::actingAs($fx['admin'])
        ->test(LeadCreate::class)
        ->call('editLead', $fx['lead']->id)
        ->get('emailBody');

    [$d1, $d2] = bookableWeekdays($fx['lead']);
    $pick = fn () => Livewire::test(\App\Livewire\Leads\PickTimes::class, ['lead' => $fx['lead']->id])
        ->set('date', $d1)->call('toggleWindow', 'Anytime')
        ->set('date', $d2)->call('toggleWindow', 'Anytime')
        ->call('submit')
        ->assertSet('submitted', true);

    // Their first times: a first contact, however they got the link.
    $pick();
    expect(Lead::withoutGlobalScopes()->find($fx['lead']->id)->hasRescheduled())->toBeFalse()
        ->and($compose())->toContain('Thank you for reaching out')
        ->and($compose())->not->toContain('reschedule');

    // Times on top of times: now they have rescheduled.
    $pick();
    expect(Lead::withoutGlobalScopes()->find($fx['lead']->id)->hasRescheduled())->toBeTrue()
        ->and($compose())->toContain('taking the time to reschedule');
});

it('lets someone who already picked once take any slot that has not started', function () {
    $fx = makeConsultFixture();
    $tz = \App\Livewire\Leads\PickTimes::timezone();

    // Pretend it is 9am on a Tuesday so the boundary is deterministic.
    \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::parse('2026-07-28 09:00', $tz));

    $data = $fx['lead']->lead_data;
    $data['availability_rescheduled_at'] = now()->toDateTimeString();
    $fx['lead']->lead_data = $data;
    $fx['lead']->save();

    $lead = Lead::withoutGlobalScopes()->find($fx['lead']->id);
    $today = '2026-07-28';

    expect(\App\Livewire\Leads\PickTimes::minLeadHours($lead))->toBe(0)
        ->and(\App\Livewire\Leads\PickTimes::firstBookableDate($lead))->toBe($today);

    $pick = fn (string $window) => Livewire::test(\App\Livewire\Leads\PickTimes::class, ['lead' => $lead->id])
        ->set('date', $today)
        ->call('toggleWindow', $window)
        ->get('times');

    // 7-9 AM has begun; the rest of today is theirs.
    expect($pick('7-9 AM'))->toBe([])
        ->and($pick('9-11 AM'))->toBe([['date' => $today, 'time' => '9-11 AM']])
        ->and($pick('1-3 PM'))->toBe([['date' => $today, 'time' => '1-3 PM']]);

    \Illuminate\Support\Carbon::setTestNow();
});

it('treats a booked consult as rescheduling, even if the picker was never used', function () {
    Queue::fake();
    $fx = makeConsultFixture();
    $tz = \App\Livewire\Leads\PickTimes::timezone();

    \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::parse('2026-07-28 09:00', $tz));

    $lead = Lead::withoutGlobalScopes()->find($fx['lead']->id);

    // A first contact waits the full notice.
    expect(\App\Livewire\Leads\PickTimes::minLeadHours($lead))->toBe(72);

    consultComposer($fx)
        ->call('insertAvailabilitySlot', 0)
        ->call('selectExactTime', '14:00')
        ->set('projectName', 'Kitchen Remodel')
        ->call('send_message');

    // Now they have a meeting. The "Need a different time?" link in the invite
    // lands here, and moving an appointment they already have is not a first
    // contact — this used to hold them to three days out.
    $lead = Lead::withoutGlobalScopes()->find($fx['lead']->id);

    expect($lead->hasRescheduled())->toBeFalse()
        ->and($lead->hasBookedConsult())->toBeTrue()
        ->and(\App\Livewire\Leads\PickTimes::minLeadHours($lead))->toBe(0)
        ->and(\App\Livewire\Leads\PickTimes::firstBookableDate($lead))->toBe('2026-07-28');

    \Illuminate\Support\Carbon::setTestNow();
});

it('still holds a brand-new lead to three days notice', function () {
    $fx = makeConsultFixture();
    $tz = \App\Livewire\Leads\PickTimes::timezone();

    \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::parse('2026-07-28 09:00', $tz));

    $lead = Lead::withoutGlobalScopes()->find($fx['lead']->id);

    expect(\App\Livewire\Leads\PickTimes::minLeadHours($lead))->toBe(72);

    $times = Livewire::test(\App\Livewire\Leads\PickTimes::class, ['lead' => $lead->id])
        ->set('date', '2026-07-29')
        ->call('toggleWindow', '9-11 AM')
        ->get('times');

    expect($times)->toBe([]);

    \Illuminate\Support\Carbon::setTestNow();
});

it('books a virtual consult as a Teams meeting when asked', function () {
    Queue::fake();
    $fx = makeConsultFixture();

    consultComposer($fx)
        ->set('consultMeetingType', 'virtual')
        ->call('insertAvailabilitySlot', 0)
        ->call('selectExactTime', '14:00')
        ->set('projectName', 'Kitchen Remodel')
        ->call('send_message');

    $task = Task::withoutGlobalScopes()->where('type', 'Meet')->latest('id')->firstOrFail();

    expect(data_get($task->options, 'meeting_location_type'))->toBe('virtual');

    // The invite's location is the call, not the jobsite — nobody drives to
    // a Teams meeting.
    $service = app(\App\Services\MeetTaskCalendarService::class);
    $method = new \ReflectionMethod($service, 'resolveMeetingLocation');
    $method->setAccessible(true);

    expect($method->invoke($service, $task->fresh()))->toBe('Microsoft Teams');
});

it('carries the homeowner meeting preference from the picker into the composer', function () {
    Queue::fake();
    $fx = makeConsultFixture();
    $tz = \App\Livewire\Leads\PickTimes::timezone();
    \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::parse('2026-07-20 09:00', $tz));

    Livewire::test(\App\Livewire\Leads\PickTimes::class, ['lead' => $fx['lead']->id])
        ->set('date', '2026-07-27')
        ->call('toggleWindow', '7-9 AM')
        ->set('date', '2026-07-28')
        ->call('toggleWindow', '9-11 AM')
        ->call('toggleWindow', '11-1 PM')
        ->set('meeting', 'virtual')
        ->call('submit')
        ->assertHasNoErrors();

    $lead = Lead::withoutGlobalScopes()->find($fx['lead']->id);
    expect($lead->lead_data['meeting_preference'])->toBe('virtual');

    // Opening the composer picks the preference up as the default.
    $composer = Livewire::actingAs($fx['admin'])
        ->test(LeadCreate::class)
        ->call('editLead', $lead->id);

    expect($composer->instance()->consultMeetingType)->toBe('virtual');

    \Illuminate\Support\Carbon::setTestNow();
});

it('notifies vendor admins when a homeowner picks consultation times', function () {
    Queue::fake();
    $fx = makeConsultFixture();
    $tz = \App\Livewire\Leads\PickTimes::timezone();
    \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::parse('2026-07-20 09:00', $tz));

    Livewire::test(\App\Livewire\Leads\PickTimes::class, ['lead' => $fx['lead']->id])
        ->set('date', '2026-07-27')
        ->call('toggleWindow', '7-9 AM')
        ->set('date', '2026-07-28')
        ->call('toggleWindow', '9-11 AM')
        ->call('toggleWindow', '11-1 PM')
        ->set('meeting', 'virtual')
        ->call('submit')
        ->assertHasNoErrors();

    $notification = \App\Models\AppNotification::where('type', 'lead_times_picked')
        ->where('user_id', $fx['admin']->id)
        ->first();

    expect($notification)->not->toBeNull()
        ->and($notification->title)->toContain('picked consultation times')
        ->and($notification->body)->toContain('Mon, Jul 27 · 7-9 AM')
        ->and($notification->body)->toContain('video call')
        ->and($notification->data['lead_id'])->toBe($fx['lead']->id);

    // …and every admin's browser gets the same alert.
    Queue::assertPushed(\App\Jobs\SendBrowserNotificationsToUsers::class, fn ($job) => in_array($fx['admin']->id, $job->userIds, true)
        && str_contains($job->payload['title'], 'picked consultation times')
        && str_contains($job->payload['body'], 'Mon, Jul 27 · 7-9 AM'));

    \Illuminate\Support\Carbon::setTestNow();
});

// ── The same people, both channels ──────────────────────────────────────

/** An opted-in thread for the fixture's client, on the contact's number. */
function consultThread(array $fx, bool $optedIn = true, ?\App\Models\User $partner = null): \App\Models\SmsGroupThread
{
    $numbers = [\App\Services\GroupSmsService::formatE164($fx['contact']->cell_phone)];
    if ($partner) {
        $numbers[] = \App\Services\GroupSmsService::formatE164($partner->cell_phone);
    }

    $thread = \App\Models\SmsGroupThread::create([
        'from_number' => '+12247354200',
        'vendor_id' => $fx['vendor']->id,
        'participants' => $numbers,
        'client_id' => $fx['client']->id,
    ]);
    foreach ($numbers as $number) {
        $thread->threadParticipants()->create(['phone_number' => $number, 'opted_in_at' => $optedIn ? now() : null]);
    }

    return $thread;
}

it('confirms the booked consult by text as well, to everyone on the client\'s thread', function () {
    Queue::fake();
    $fx = makeConsultFixture();
    $partner = User::query()->create([
        'first_name' => 'Alan', 'last_name' => 'Singh',
        'email' => 'alan.'.uniqid().'@example.com', 'cell_phone' => fake()->unique()->numerify('224777####'),
    ]);
    $fx['client']->users()->attach($partner->id);
    $fx['client']->update(['address' => '3395 Portshire Dr', 'city' => 'Hoffman Estates']);
    $thread = consultThread($fx, partner: $partner);

    $sent = null;
    $this->mock(\App\Services\GroupSmsService::class, function ($mock) use ($thread, &$sent) {
        $mock->shouldReceive('sendToThread')->once()
            ->withArgs(function ($t, $text) use ($thread, &$sent) {
                $sent = $text;

                return $t->id === $thread->id;
            });
    });

    consultComposer($fx)
        ->call('insertAvailabilitySlot', 0)
        ->call('selectExactTime', '14:00')
        ->set('projectName', 'Kitchen Remodel')
        ->call('send_message');

    $task = Task::withoutGlobalScopes()->where('type', 'Meet')->firstOrFail();
    $label = \Illuminate\Support\Carbon::parse($task->start_date)->format('D, M j').' · 2:00 PM';

    expect($sent)->toStartWith("Hi Preet & Alan,\n\nYour consultation with GSC is confirmed for {$label} at ")
        ->and($sent)->toContain('pick new consultation times here: ')
        ->and($sent)->toContain('confirm the new one ASAP.');
    // The email still goes out as before.
    Queue::assertPushed(SendLeadReplyJob::class);
});

it('sends nothing more by text while the thread still awaits START', function () {
    Queue::fake();
    $fx = makeConsultFixture();
    consultThread($fx, optedIn: false);
    $this->mock(\App\Services\GroupSmsService::class, function ($mock) {
        $mock->shouldNotReceive('sendToThread');
        $mock->shouldNotReceive('sendNewGroup');
    });

    consultComposer($fx)
        ->call('insertAvailabilitySlot', 0)
        ->call('selectExactTime', '14:00')
        ->set('projectName', 'Kitchen Remodel')
        ->call('send_message');

    expect(Task::withoutGlobalScopes()->where('type', 'Meet')->count())->toBe(1);
    Queue::assertPushed(SendLeadReplyJob::class);
});

it('starts the START consent flow with every contact when the client has no thread yet', function () {
    Queue::fake();
    $fx = makeConsultFixture();
    $partner = User::query()->create([
        'first_name' => 'Alan', 'last_name' => 'Singh',
        'email' => 'alan3.'.uniqid().'@example.com', 'cell_phone' => '2247770101',
    ]);
    $fx['client']->users()->attach($partner->id);

    $this->mock(\App\Services\GroupSmsService::class, function ($mock) use ($fx) {
        $mock->shouldNotReceive('sendToThread');
        $mock->shouldReceive('sendNewGroup')->once()
            ->withArgs(fn ($numbers, $text, $projectId, $clientId) => $numbers === [
                \App\Services\GroupSmsService::formatE164($fx['contact']->cell_phone),
                '+12247770101',
            ] && $clientId === $fx['client']->id)
            ->andReturn(new \App\Models\SmsGroupThread);
    });

    consultComposer($fx)->call('send_message');

    Queue::assertPushed(SendLeadReplyJob::class);
});

it('texts the pick-times ask, or a heads-up, to match the email that went out', function () {
    Queue::fake();
    $fx = makeConsultFixture();
    $thread = consultThread($fx);
    $texts = [];
    $this->mock(\App\Services\GroupSmsService::class, function ($mock) use ($thread, &$texts) {
        $mock->shouldReceive('sendToThread')->twice()->withArgs(function ($t, $text) use ($thread, &$texts) {
            $texts[] = $text;

            return $t->id === $thread->id;
        });
    });

    // An email carrying the pick-times link: the same ask by text.
    consultComposer($fx)
        ->set('emailBody', '<p>Pick times: https://hive.test/lead/times/'.$fx['lead']->id.'?expires=1&signature=x</p>')
        ->call('send_message');
    // Any other email: a heads-up naming it.
    consultComposer($fx)->set('subject', 'Your estimate')->call('send_message');

    expect($texts[0])->toStartWith("Hi Preet,\n\nPick a consultation time with GSC here: ")
        ->and($texts[1])->toBe("Hi Preet,\n\nWe just emailed you about \"Your estimate\" — please check your inbox. If texting is easier, just reply here.");
});

it('greets every contact on the client in the consult email', function () {
    Queue::fake();
    $fx = makeConsultFixture();
    $partner = User::query()->create([
        'first_name' => 'Alan', 'last_name' => 'Singh',
        'email' => 'alan2.'.uniqid().'@example.com', 'cell_phone' => fake()->unique()->numerify('224666####'),
    ]);
    $fx['client']->users()->attach($partner->id);

    $component = consultComposer($fx);
    $greeting = (new ReflectionMethod(LeadCreate::class, 'replacePlaceholders'))
        ->invoke($component->instance(), 'Hi {{client_first_name}}, — {{client_first_names}}');

    expect($greeting)->toBe('Hi Preet & Alan, — Preet & Alan');
});

it('trims a name typed on the lead to its first word when there is no client', function () {
    Queue::fake();
    $fx = makeConsultFixture();
    $fx['client']->users()->detach();
    $fx['lead']->update(['user_id' => null]);

    $component = Livewire::actingAs($fx['admin'])->test(LeadCreate::class)->call('editLead', $fx['lead']->id);
    $greeting = (new ReflectionMethod(LeadCreate::class, 'replacePlaceholders'))
        ->invoke($component->instance(), 'Hi {{client_first_name}},');

    expect($greeting)->toBe('Hi Preet,');
});

it('re-sending the confirmation of the time already booked converts a re-picked lead back to Won', function () {
    Queue::fake();
    $fx = makeConsultFixture();
    // An opted-in thread from the start, or the first send would open a
    // pending consent thread that keeps the second text from going out.
    $thread = consultThread($fx);
    $texts = [];
    $this->mock(\App\Services\GroupSmsService::class, function ($mock) use ($thread, &$texts) {
        $mock->shouldReceive('sendToThread')->twice()
            ->withArgs(function ($t, $text) use ($thread, &$texts) { $texts[] = $text; return $t->id === $thread->id; });
    });

    consultComposer($fx)
        ->call('insertAvailabilitySlot', 0)
        ->call('selectExactTime', '14:00')
        ->set('projectName', 'Kitchen Remodel')
        ->call('send_message');
    expect($fx['lead']->fresh()->last_status->title)->toBe('Won');

    // They re-picked through the link: back to New, consult still on the calendar.
    $fx['lead']->setStatus('New');

    // The office confirms the same time again: nothing to book, still a
    // confirmation — for the lead's status and for the text that goes with it.
    consultComposer($fx)
        ->call('insertAvailabilitySlot', 0)
        ->call('selectExactTime', '14:00')
        ->call('send_message');

    $task = Task::withoutGlobalScopes()->where('type', 'Meet')->firstOrFail();
    $label = \Illuminate\Support\Carbon::parse($task->start_date)->format('D, M j').' · 2:00 PM';

    expect(Task::withoutGlobalScopes()->where('type', 'Meet')->count())->toBe(1)
        ->and($fx['lead']->fresh()->last_status->title)->toBe('Won')
        ->and($texts)->toHaveCount(2)
        ->and($texts[1])->toContain("Your consultation with GSC is confirmed for {$label}")
        ->and($texts[1])->not->toContain('Pick a consultation time');
});

// ── The team's calendars gate the composer too ───────────────────────────

it('withholds start times the calendars are busy for, and says so when a picked window has none left', function () {
    Queue::fake();
    $fx = makeConsultFixture(); // the homeowner picked 1-3 PM
    $date = $fx['lead']->lead_data['availability'][0]['date'];

    // Patryk or Greg busy 1:00-2:00 — with the 30-minute travel buffer the
    // chips keep clear of 12:30-2:30, so only 2:30 is left.
    $this->partialMock(\App\Services\AdminCalendarBusy::class, fn ($mock) => $mock->shouldReceive('busyIntervalsFor')
        ->with($date)->andReturn([['13:00', '14:00']]));

    $component = consultComposer($fx)->call('insertAvailabilitySlot', 0);
    expect(collect($component->instance()->exactTimeOptions)->pluck('label')->all())->toBe(['2:30 PM'])
        ->and($component->instance()->selectedSlotWindowKnown)->toBeTrue();
    $component->assertDontSee('No free start left');

    // The whole window went busy since they picked it: the slot is marked
    // booked and can't be selected, and with nothing usable left the email
    // asks for new times instead of confirming one.
    \App\Models\EmailTemplate::create([
        'vendor_id' => $fx['vendor']->id, 'type' => 'lead', 'name' => 'Consult', 'subject' => 'Consultation',
        'body' => '<p>{{lead_intro}}</p><p>{{lead_time_block}}</p>',
    ]);
    $this->partialMock(\App\Services\AdminCalendarBusy::class, fn ($mock) => $mock->shouldReceive('busyIntervalsFor')
        ->with($date)->andReturn([['12:30', '15:30']]));

    $component = Livewire::actingAs($fx['admin'])->test(LeadCreate::class)->call('editLead', $fx['lead']->id);
    expect($component->instance()->slotProblems)->toBe(['booked'])
        ->and($component->instance()->hasUsableAvailability)->toBeFalse();
    $component->assertSee('· booked')
        ->assertSee('The calendars are booked for all of these preferred times');

    $component->call('insertAvailabilitySlot', 0);
    expect($component->get('selectedAvailability'))->toBe([])
        ->and($component->get('emailBody'))->toContain('select new consultation times')
        ->and($component->get('emailBody'))->toContain('no longer open on our calendar')
        ->and($component->get('sendBlockedReason'))->toBeNull();
});

