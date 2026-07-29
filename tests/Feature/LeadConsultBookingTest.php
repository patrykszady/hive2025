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

function makeConsultFixture(?array $slot = null): array
{
    $slot ??= ['date' => now()->addDays(2)->format('Y-m-d'), 'time' => '2-4 PM'];
    config(['email_tracking.provider' => 'mailtrap']);

    $vendor = Vendor::factory()->create(['options' => ['short_name' => 'GSC']]);

    $admin = new User();
    $admin->forceFill([
        'first_name' => 'Patryk',
        'last_name' => 'Sender',
        'email' => 'consult-admin.'.uniqid().'@example.test',
        'cell_phone' => fake()->unique()->numerify('224555####'),
        'primary_vendor_id' => $vendor->id,
    ]);
    $admin->save();
    $vendor->users()->attach($admin->id, ['role_id' => 1]);

    CompanyEmail::create(['vendor_id' => $vendor->id, 'email' => $admin->email, 'grant_id' => '']);

    $contact = User::query()->create([
        'first_name' => 'Preet',
        'last_name' => 'Singh',
        'email' => 'preet.'.uniqid().'@example.com',
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
            'name' => 'Preet Kanwal Singh',
            'address' => '123 Main St, Palatine, IL 60067',
            'message' => 'Kitchen remodel',
            'email' => $contact->email,
            'availability' => [$slot],
        ],
    ]);
    $lead->statuses()->create(['title' => 'New', 'belongs_to_vendor_id' => $vendor->id]);

    return compact('vendor', 'admin', 'contact', 'client', 'lead');
}

/**
 * The next $count bookable weekdays for this lead — consults are weekdays
 * only, so "+1 day" can land on a Saturday.
 *
 * @return array<int, string>
 */
function bookableWeekdays(Lead $lead, int $count = 2): array
{
    $tz = \App\Livewire\Leads\PickTimes::timezone();
    $day = \Illuminate\Support\Carbon::parse(\App\Livewire\Leads\PickTimes::firstBookableDate($lead), $tz);
    $days = [];

    while (count($days) < $count) {
        if (! $day->isWeekend()) {
            $days[] = $day->format('Y-m-d');
        }
        $day->addDay();
    }

    return $days;
}

function consultComposer(array $fx)
{
    return Livewire::actingAs($fx['admin'])
        ->test(LeadCreate::class)
        ->call('editLead', $fx['lead']->id)
        ->set('to', [$fx['contact']->email])
        ->set('from', $fx['admin']->email)
        ->set('subject', 'Consultation')
        ->set('emailBody', '<p>See you soon</p>');
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
        // Sending the reply moves the New lead to Replied.
        ->and($fx['lead']->fresh()->last_status->title)->toBe('Replied');
});

it('locks a Replied lead: no composer, no delete', function () {
    Queue::fake();
    $fx = makeConsultFixture();
    $fx['lead']->setStatus('Replied');

    $component = Livewire::actingAs($fx['admin'])
        ->test(LeadCreate::class)
        ->call('editLead', $fx['lead']->id);

    expect($component->instance()->hasReplied)->toBeTrue();

    // Remove is guarded server-side too.
    $component->call('confirmRemove')->assertSet('showLeadDelete', false);
    $component->call('remove');
    expect(Lead::withoutGlobalScopes()->find($fx['lead']->id))->not->toBeNull();
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
        ->toBe(['2:00 PM', '2:30 PM', '3:00 PM', '3:30 PM']);

    $component->call('selectExactTime', '14:30')->set('projectName', 'Consult')->call('send_message');

    $task = Task::withoutGlobalScopes()->where('title', 'GSC | Singh | Consult')->first();
    expect($task)->not->toBeNull()
        ->and(data_get($task->options, 'time_settings.'.now()->addDays(2)->format('Y-m-d').'.start_time'))->toBe('14:30')
        ->and(data_get($task->options, 'time_settings.'.now()->addDays(2)->format('Y-m-d').'.end_time'))->toBe('15:00');
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
    $fx = makeConsultFixture(['date' => now()->subDays(3)->format('Y-m-d'), 'time' => '2-4 PM']);
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

    expect($body)->toContain('select new consultation times');
});

it('refuses to select a slot whose date has passed', function () {
    $fx = makeConsultFixture(['date' => now()->subDay()->format('Y-m-d'), 'time' => '2-4 PM']);

    $component = consultComposer($fx)->call('insertAvailabilitySlot', 0);

    expect($component->get('selectedAvailability'))->toBe([]);
});

it('lets the lead submit new times through the signed page', function () {
    $fx = makeConsultFixture(['date' => now()->subDay()->format('Y-m-d'), 'time' => '2-4 PM']);

    [$date, $date2] = bookableWeekdays($fx['lead']);

    Livewire::test(\App\Livewire\Leads\PickTimes::class, ['lead' => $fx['lead']->id])
        ->set('date', $date)
        ->call('toggleWindow', '10-12 PM')
        ->call('toggleWindow', '2-4 PM')
        ->set('date', $date2)
        ->call('toggleWindow', 'Anytime')
        ->call('submit')
        ->assertSet('submitted', true);

    $fresh = Lead::withoutGlobalScopes()->find($fx['lead']->id);
    expect($fresh->lead_data['availability'][0])->toMatchArray(['date' => $date, 'time' => '10-12 PM'])
        ->and($fresh->lead_data['availability'][1])->toMatchArray(['date' => $date, 'time' => '2-4 PM'])
        ->and($fresh->lead_data['availability'][2])->toMatchArray(['date' => $date2, 'time' => 'Anytime']);
});

it('requires at least three times across two different days', function () {
    $fx = makeConsultFixture();

    [$d1, $d2] = bookableWeekdays($fx['lead']);

    // 3 times but one day -> blocked
    Livewire::test(\App\Livewire\Leads\PickTimes::class, ['lead' => $fx['lead']->id])
        ->set('date', $d1)
        ->call('toggleWindow', '8-10 AM')
        ->call('toggleWindow', '10-12 PM')
        ->call('toggleWindow', '2-4 PM')
        ->call('submit')
        ->assertHasErrors('times')
        ->assertSet('submitted', false);

    // 2 days but only 2 times -> blocked
    Livewire::test(\App\Livewire\Leads\PickTimes::class, ['lead' => $fx['lead']->id])
        ->set('date', $d1)
        ->call('toggleWindow', '2-4 PM')
        ->set('date', $d2)
        ->call('toggleWindow', '2-4 PM')
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
        ->call('toggleWindow', '2-4 PM')
        ->assertHasErrors('date');

    expect($picker->get('times'))->toBe([]);

    foreach (bookableWeekdays($fx['lead'], \App\Livewire\Leads\PickTimes::MAX_SLOTS + 1) as $day) {
        $picker->set('date', $day)->call('toggleWindow', '2-4 PM');
    }

    expect(count($picker->get('times')))->toBe(\App\Livewire\Leads\PickTimes::MAX_SLOTS);
});

it('treats Anytime as the whole day, like the vendor availability flow', function () {
    $fx = makeConsultFixture();
    [$day, $day2] = bookableWeekdays($fx['lead']);

    $picker = Livewire::test(\App\Livewire\Leads\PickTimes::class, ['lead' => $fx['lead']->id])
        ->set('date', $day)
        ->call('toggleWindow', '8-10 AM')
        ->call('toggleWindow', '2-4 PM')
        // Anytime supersedes the day's specific windows...
        ->call('toggleWindow', 'Anytime');

    expect($picker->get('times'))->toBe([['date' => $day, 'time' => 'Anytime']]);

    // ...and a specific window replaces the day's Anytime, without touching
    // other days.
    $picker->set('date', $day2)->call('toggleWindow', 'Anytime')
        ->set('date', $day)->call('toggleWindow', '2-4 PM');

    expect($picker->get('times'))->toBe([
        ['date' => $day, 'time' => '2-4 PM'],
        ['date' => $day2, 'time' => 'Anytime'],
    ]);
});

it('rejects times less than 72 hours ahead', function () {
    $fx = makeConsultFixture();

    // Tomorrow fails date validation outright.
    Livewire::test(\App\Livewire\Leads\PickTimes::class, ['lead' => $fx['lead']->id])
        ->set('date', now()->addDay()->format('Y-m-d'))
        ->call('toggleWindow', '2-4 PM')
        ->assertHasErrors('date');

    // Beyond the boundary is fine (first bookable weekday).
    [$ok] = bookableWeekdays($fx['lead']);

    $picker = Livewire::test(\App\Livewire\Leads\PickTimes::class, ['lead' => $fx['lead']->id])
        ->set('date', $ok)
        ->call('toggleWindow', '2-4 PM');

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
        ->set('date', $d2)->call('toggleWindow', '2-4 PM')
        ->get('canSubmit'))->toBeTrue();

    // Anytime on a single day is worth 2 but only one day -> still blocked.
    expect($picker()->set('date', $d1)->call('toggleWindow', 'Anytime')
        ->get('canSubmit'))->toBeFalse();

    // Two plain windows across two days = 2 -> still blocked.
    expect($picker()->set('date', $d1)->call('toggleWindow', '8-10 AM')
        ->set('date', $d2)->call('toggleWindow', '2-4 PM')
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

it('leaves a Won or Lost lead status alone when times are submitted', function () {
    $fx = makeConsultFixture();
    $fx['lead']->setStatus('Won');

    [$d1, $d2] = bookableWeekdays($fx['lead']);

    Livewire::test(\App\Livewire\Leads\PickTimes::class, ['lead' => $fx['lead']->id])
        ->set('date', $d1)->call('toggleWindow', 'Anytime')
        ->set('date', $d2)->call('toggleWindow', 'Anytime')
        ->call('submit');

    $fresh = Lead::withoutGlobalScopes()->find($fx['lead']->id);
    $fresh->unsetRelation('last_status');

    expect($fresh->last_status?->title)->toBe('Won');
});

it('drops the follow-up line when no time was proposed', function () {
    $fx = makeConsultFixture(['date' => now()->subDays(3)->format('Y-m-d'), 'time' => '2-4 PM']);
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

it('gives a rescheduling client 24h notice instead of 72h', function () {
    $fx = makeConsultFixture();
    $tz = \App\Livewire\Leads\PickTimes::timezone();

    // Pretend it is 1pm on a Tuesday so the boundary is deterministic.
    \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::parse('2026-07-28 13:00', $tz));

    $data = $fx['lead']->lead_data;
    $data['availability_updated_at'] = now()->toDateTimeString();
    $fx['lead']->lead_data = $data;
    $fx['lead']->save();

    $lead = Lead::withoutGlobalScopes()->find($fx['lead']->id);
    $tomorrow = '2026-07-29'; // Wednesday

    expect(\App\Livewire\Leads\PickTimes::minLeadHours($lead))->toBe(24)
        ->and(\App\Livewire\Leads\PickTimes::firstBookableDate($lead))->toBe($tomorrow);

    // Asking at 1pm greys out tomorrow morning but leaves 2-4 PM.
    $pick = fn (string $window) => Livewire::test(\App\Livewire\Leads\PickTimes::class, ['lead' => $lead->id])
        ->set('date', $tomorrow)
        ->call('toggleWindow', $window)
        ->get('times');

    expect($pick('8-10 AM'))->toBe([])
        ->and($pick('12-2 PM'))->toBe([])
        ->and($pick('2-4 PM'))->toBe([['date' => $tomorrow, 'time' => '2-4 PM']]);

    \Illuminate\Support\Carbon::setTestNow();
});

it('never offers weekends or a 4-6 PM window', function () {
    $fx = makeConsultFixture();
    $tz = \App\Livewire\Leads\PickTimes::timezone();

    expect(\App\Livewire\Leads\PickTimes::WINDOWS)->not->toContain('4-6 PM');

    $saturday = \Illuminate\Support\Carbon::now($tz)->next(\Carbon\Carbon::SATURDAY)->format('Y-m-d');
    $sunday = \Illuminate\Support\Carbon::now($tz)->next(\Carbon\Carbon::SUNDAY)->format('Y-m-d');

    foreach ([$saturday, $sunday] as $weekend) {
        expect(Livewire::test(\App\Livewire\Leads\PickTimes::class, ['lead' => $fx['lead']->id])
            ->set('date', $weekend)
            ->call('toggleWindow', '2-4 PM')
            ->get('times'))->toBe([]);
    }

    // The calendar's opening day is a weekday, and weekends are marked out.
    $first = \App\Livewire\Leads\PickTimes::firstBookableDate($fx['lead']);
    expect(\Illuminate\Support\Carbon::parse($first, $tz)->isWeekend())->toBeFalse()
        ->and(\App\Livewire\Leads\PickTimes::unavailableDates($fx['lead']))->toContain($saturday);
});
