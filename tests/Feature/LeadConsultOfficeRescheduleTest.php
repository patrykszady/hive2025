<?php

use App\Jobs\CreateMeetTaskCalendarEvent;
use App\Jobs\SendLeadReplyJob;
use App\Jobs\UpdateMeetTaskCalendarEvent;
use App\Livewire\Leads\LeadCreate;
use App\Models\Client;
use App\Models\CompanyEmail;
use App\Models\Lead;
use App\Models\Task;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/** Next week's Wednesday — always in the future, never a weekend. */
function officeProposalDate(int $plusDays = 0): string
{
    return now()->addWeek()->startOfWeek()->addDays(2 + $plusDays)->format('Y-m-d');
}

function makeOfficeRescheduleFixture(?array $availability = null): array
{
    config(['email_tracking.provider' => 'mailtrap']);

    $vendor = Vendor::factory()->create(['options' => ['short_name' => 'GSC']]);

    $admin = new User();
    $admin->forceFill([
        'first_name' => 'Patryk',
        'last_name' => 'Sender',
        'email' => 'office-admin.'.uniqid().'@example.test',
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
        'lead_data' => array_filter([
            'name' => 'Kristin White',
            'address' => '166 Akenside Rd, Riverside, IL 60546',
            'message' => 'Bathroom remodel',
            'email' => $contact->email,
            'availability' => $availability,
        ]),
    ]);
    $lead->statuses()->create(['title' => 'New', 'belongs_to_vendor_id' => $vendor->id]);

    return compact('vendor', 'admin', 'contact', 'client', 'lead');
}

function officeComposer(array $fx)
{
    return Livewire::actingAs($fx['admin'])
        ->test(LeadCreate::class)
        ->call('editLead', $fx['lead']->id)
        ->set('to', [$fx['contact']->email])
        ->set('from', $fx['admin']->email)
        ->set('subject', 'Consultation')
        ->set('emailBody', '<p>See you soon</p>');
}

it('books a consult on an office-proposed day the homeowner never offered', function () {
    Queue::fake();
    $fx = makeOfficeRescheduleFixture();
    $date = officeProposalDate();

    $component = officeComposer($fx)->set('proposeDate', $date);

    // The proposed day rides the normal rails: it appears as a selected slot
    // and the busy-gated exact-time chips open up for it.
    $options = $component->instance()->exactTimeOptions;
    expect($options)->not->toBeEmpty();

    $component
        ->call('selectExactTime', $options[0]['value'])
        ->set('projectName', 'Bathroom Remodel')
        ->call('send_message');

    Queue::assertPushed(SendLeadReplyJob::class);
    Queue::assertPushed(CreateMeetTaskCalendarEvent::class);

    $task = Task::withoutGlobalScopes()->where('type', 'Meet')->first();
    expect($task)->not->toBeNull()
        ->and($task->start_date->toDateString())->toBe($date)
        ->and(data_get($task->options, 'time_settings.'.$date.'.start_time'))->toBe($options[0]['value']);
});

it('words the email as an offer, not a confirmation, for an office-proposed time', function () {
    Queue::fake();
    $fx = makeOfficeRescheduleFixture();

    $component = officeComposer($fx)->set('proposeDate', officeProposalDate());
    $options = $component->instance()->exactTimeOptions;
    $component->call('selectExactTime', $options[0]['value']);

    // The seeded template isn't required — the wording comes from the
    // lead_time_block placeholder, rendered into any template that carries it.
    $component->set('selectedTemplateId', null);
    $timeBlock = (new ReflectionMethod(LeadCreate::class, 'replacePlaceholders'))
        ->invoke($component->instance(), '{{lead_time_block}}');

    expect($timeBlock)->toContain('offer this consultation time')
        ->and($timeBlock)->not->toContain('availability you shared');
});

it('reschedules the booked consult to the office-proposed day, moving the calendar event', function () {
    Queue::fake();
    $fx = makeOfficeRescheduleFixture([
        ['date' => officeProposalDate(), 'time' => '1-3 PM'],
    ]);

    // First booking: homeowner's own slot.
    officeComposer($fx)
        ->call('insertAvailabilitySlot', 0)
        ->call('selectExactTime', '14:00')
        ->set('projectName', 'Bathroom Remodel')
        ->call('send_message');

    $task = Task::withoutGlobalScopes()->where('type', 'Meet')->firstOrFail();
    $task->updateQuietly([
        'options' => array_merge((array) $task->options, [
            'nylas_meet_event' => ['event_id' => 'evt-1', 'grant_id' => 'grant-1', 'calendar_id' => 'cal-1'],
        ]),
    ]);

    // GS-side reschedule: propose a different day entirely.
    $newDate = officeProposalDate(1);
    $component = officeComposer($fx)->set('proposeDate', $newDate);
    $options = $component->instance()->exactTimeOptions;
    $component->call('selectExactTime', $options[0]['value'])->call('send_message');

    $moved = Task::withoutGlobalScopes()->where('type', 'Meet')->get();
    expect($moved)->toHaveCount(1)
        ->and($moved->first()->id)->toBe($task->id)
        ->and($moved->first()->start_date->toDateString())->toBe($newDate)
        ->and(data_get($moved->first()->options, 'time_settings.'.$newDate.'.start_time'))->toBe($options[0]['value']);

    Queue::assertPushed(UpdateMeetTaskCalendarEvent::class);
});

it('rolls back a proposal for a day with no open starts instead of arming a timeless send', function () {
    Queue::fake();
    $fx = makeOfficeRescheduleFixture();

    // 3:10 PM Central: the working day (07:00-15:00) has no start left.
    $tz = \App\Livewire\Leads\PickTimes::timezone();
    $today = \Illuminate\Support\Carbon::now($tz);
    if ($today->isWeekend()) {
        $today = $today->next(\Illuminate\Support\Carbon::MONDAY);
    }
    \Illuminate\Support\Carbon::setTestNow($today->setTime(15, 10));

    $component = officeComposer($fx)->set('proposeDate', \Illuminate\Support\Carbon::now($tz)->format('Y-m-d'));

    $component->assertHasErrors('proposeDate');
    expect(collect($component->instance()->availability)->contains(fn ($s) => ! empty($s['office_proposed'])))->toBeFalse()
        ->and($component->instance()->selectedAvailability)->toBe([]);

    \Illuminate\Support\Carbon::setTestNow();
});

it('does not thank a rescheduled homeowner for availability when the office proposes its own time', function () {
    Queue::fake();
    $fx = makeOfficeRescheduleFixture();
    $fx['lead']->update([
        'lead_data' => array_merge($fx['lead']->lead_data->toArray(), ['availability_updated_at' => now()->toIso8601String()]),
    ]);

    $component = officeComposer($fx)->set('proposeDate', officeProposalDate());
    $options = $component->instance()->exactTimeOptions;
    $component->call('selectExactTime', $options[0]['value']);

    $rendered = (new ReflectionMethod(LeadCreate::class, 'replacePlaceholders'))
        ->invoke($component->instance(), '{{lead_intro}} {{lead_time_block}}');

    expect($rendered)->not->toContain('sending over your new availability')
        ->and($rendered)->toContain('offer this consultation time');
});

it('never presents another client\'s meeting as this lead\'s booked consult', function () {
    Queue::fake();
    $fx = makeOfficeRescheduleFixture();

    // The same contact belongs to a second, unrelated client whose project
    // has its own Meet task.
    $otherClient = Client::factory()->create();
    $otherClient->vendors()->attach($fx['vendor']->id);
    $otherClient->users()->attach($fx['contact']->id);
    $otherProject = \App\Models\Project::withoutEvents(fn () => \App\Models\Project::create([
        'project_name' => 'Old Kitchen',
        'client_id' => $otherClient->id,
        'belongs_to_vendor_id' => $fx['vendor']->id,
        'address' => '1 Elsewhere Ave',
        'city' => 'Berwyn',
        'state' => 'IL',
        'zip_code' => '60402',
    ]));
    Task::withoutEvents(fn () => Task::create([
        'title' => 'Framing walkthrough',
        'project_id' => $otherProject->id,
        'type' => 'Meet',
        'start_date' => officeProposalDate(),
        'end_date' => officeProposalDate(),
        'order' => 0,
        'belongs_to_vendor_id' => $fx['vendor']->id,
        'created_by_user_id' => $fx['admin']->id,
    ]));

    $component = Livewire::actingAs($fx['admin'])
        ->test(LeadCreate::class)
        ->call('editLead', $fx['lead']->id);

    expect($component->instance()->bookedConsult)->toBeNull();
    $component->assertDontSee('Consult booked');
});

it('shows the booked consult in the modal and refuses weekend proposals', function () {
    Queue::fake();
    $fx = makeOfficeRescheduleFixture([
        ['date' => officeProposalDate(), 'time' => '1-3 PM'],
    ]);

    officeComposer($fx)
        ->call('insertAvailabilitySlot', 0)
        ->call('selectExactTime', '13:00')
        ->set('projectName', 'Bathroom Remodel')
        ->call('send_message');

    $saturday = now()->addWeek()->startOfWeek()->addDays(5)->format('Y-m-d');

    $component = Livewire::actingAs($fx['admin'])
        ->test(LeadCreate::class)
        ->call('editLead', $fx['lead']->id);

    expect($component->instance()->bookedConsult)->not->toBeNull()
        ->and($component->instance()->bookedConsult['label'])->toContain('1:00 PM');

    $component->assertSee('Consult booked')
        ->set('proposeDate', $saturday)
        ->assertHasErrors('proposeDate');

    // The rejected weekend never became a selectable slot.
    expect(collect($component->instance()->availability)->contains(fn ($s) => ! empty($s['office_proposed'])))->toBeFalse();
});
