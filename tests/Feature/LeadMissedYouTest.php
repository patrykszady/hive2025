<?php

use App\Jobs\SendLeadReplyJob;
use App\Livewire\Leads\LeadCreate;
use App\Livewire\Leads\PickTimes;
use App\Models\Client;
use App\Models\CompanyEmail;
use App\Models\EmailTemplate;
use App\Models\Lead;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Leads\LeadReplyComposer;
use App\Support\Leads\MissedYouTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * "Sorry we missed you" (Veera Chiranjeevi, Glenview, 2026-11-05): a booked
 * consult started at 11:00, nobody met, and the office had no way to say so
 * and ask for new times. The callout above the message now offers the
 * Missed You template; the office reads it, Send Email sends it with the
 * picker link, marks the consult missed on the lead and hands the lead back
 * to Replied, and the public picker treats the homeowner as rescheduling.
 * Rebooking clears the mark.
 */
function missedYouFixture(): array
{
    // A Thursday at 11:26 Chicago; the consult below is at 11:00 that day.
    Carbon::setTestNow(Carbon::parse('2026-11-05 11:26', PickTimes::timezone()));

    $vendor = Vendor::factory()->create(['business_name' => 'GS Construction & Remodeling', 'options' => ['short_name' => 'GS Construction']]);
    $vendor->forceFill(['business_type' => 'LLC', 'registration' => ['registered' => true]])->save();

    $admin = new User();
    $admin->forceFill([
        'first_name' => 'Patryk', 'last_name' => 'Szady',
        'email' => 'missed.'.uniqid().'@example.test',
        'cell_phone' => fake()->unique()->numerify('224555####'),
        'primary_vendor_id' => $vendor->id,
        'registration' => ['registered' => true],
    ]);
    $admin->save();
    $vendor->users()->attach($admin->id, ['role_id' => 1]);
    auth()->login($admin);

    CompanyEmail::withoutEvents(fn () => CompanyEmail::create([
        'email' => 'patryk@example.test', 'vendor_id' => $vendor->id, 'grant_id' => 'grant-1',
    ]));

    $contact = User::query()->create([
        'first_name' => 'Veera', 'last_name' => 'Chiranjeevi',
        'email' => 'veera.'.uniqid().'@example.test', 'cell_phone' => fake()->unique()->numerify('615555####'),
    ]);
    $client = Client::factory()->create(['address' => '2334 Phillips Drive', 'city' => 'Glenview', 'state' => 'IL', 'zip_code' => 60026]);
    $client->users()->attach($contact->id);
    $client->vendors()->attach($vendor->id);

    $project = Project::withoutEvents(fn () => Project::create([
        'project_name' => 'Bedroom Suite', 'client_id' => $client->id,
        'address' => '2334 Phillips Drive', 'city' => 'Glenview', 'state' => 'IL', 'zip_code' => '60026',
        'belongs_to_vendor_id' => $vendor->id,
    ]));
    $project->vendors()->attach($vendor->id, ['client_id' => $client->id]);
    $project->statuses()->create(['status_code' => 9, 'belongs_to_vendor_id' => $vendor->id, 'start_date' => now()->toDateString()]);

    $lead = Lead::withoutEvents(fn () => Lead::create([
        'date' => now()->subDays(2), 'origin' => 'gs.construction', 'user_id' => $contact->id,
        'lead_data' => [
            'name' => 'Veera Chiranjeevi', 'email' => $contact->email, 'address' => '2334 Phillips Drive',
            'availability' => [['date' => '2026-11-05', 'time' => '11-1 PM'], ['date' => '2026-11-06', 'time' => '1-3 PM']],
        ],
        'belongs_to_vendor_id' => $vendor->id, 'created_by_user_id' => $admin->id,
    ]));
    $lead->statuses()->create(['title' => 'Won', 'belongs_to_vendor_id' => $vendor->id]);

    // The vendor's own templates: Consult is the default (first by name),
    // Missed You is the one the callout switches to.
    EmailTemplate::create([
        'vendor_id' => $vendor->id, 'type' => 'lead', 'name' => 'Consult',
        'subject' => '{{vendor_name}} Consultation | {{client_last_names}}',
        'body' => '<p>Hi {{client_first_name}},</p><p>{{lead_intro}}</p><p>{{lead_time_block}}</p>',
    ]);
    EmailTemplate::create([
        'vendor_id' => $vendor->id, 'type' => 'lead', 'name' => MissedYouTemplate::NAME,
        'subject' => MissedYouTemplate::subject(),
        'body' => MissedYouTemplate::bodyFrom('<p>Hi {{client_first_name}},</p><p>{{lead_intro}}</p><p>{{lead_time_block}}</p><p>Thank you,<br>Greg &amp; Patryk | (224) 735-4200</p>'),
    ]);

    return compact('vendor', 'admin', 'contact', 'client', 'project', 'lead');
}

function missedYouConsult(array $fx, string $date = '2026-11-05', string $startTime = '11:00'): Task
{
    $title = app(LeadReplyComposer::class)->consultTaskTitle($fx['client'], $fx['lead'], 'Veera Chiranjeevi');

    return Task::withoutEvents(fn () => Task::create([
        'title' => $title, 'project_id' => $fx['project']->id, 'type' => 'Meet',
        'start_date' => $date, 'end_date' => $date, 'order' => 0, 'user_ids' => [$fx['admin']->id],
        'belongs_to_vendor_id' => $fx['vendor']->id, 'created_by_user_id' => $fx['admin']->id,
        'options' => ['dates' => [$date], 'time_settings' => [$date => ['use_time' => true, 'start_time' => $startTime, 'end_time' => '11:30']]],
    ]));
}

function missedYouLead(Lead $lead): Lead
{
    return Lead::withoutGlobalScopes()->findOrFail($lead->id);
}

afterEach(fn () => Carbon::setTestNow());

it('offers the Missed You template above the message, and Send Email marks the consult missed and hands the lead back to Replied', function () {
    Queue::fake();
    $fx = missedYouFixture();
    $task = missedYouConsult($fx);

    $component = Livewire::actingAs($fx['admin'])
        ->test(LeadCreate::class)
        ->call('editLead', $fx['lead']->id)
        ->set('from', 'patryk@example.test')
        ->set('to', [$fx['contact']->email]);

    expect($component->instance()->canUseMissedYouTemplate)->toBeTrue()
        ->and($component->instance()->missedYouTemplateSelected)->toBeFalse();

    // The template fills the message for the office to read first…
    $component->call('useMissedYouTemplate')->assertHasNoErrors();
    expect($component->instance()->missedYouTemplateSelected)->toBeTrue()
        ->and($component->get('subject'))->toContain('Sorry we missed you')
        ->and($component->get('emailBody'))->toContain('Thu, Nov 5 · 11:00 AM');
    Queue::assertNotPushed(SendLeadReplyJob::class);
    expect(missedYouLead($fx['lead'])->hasMissedConsult())->toBeFalse();

    // …and the ordinary Send Email sends it.
    $component->call('send_message')->assertHasNoErrors();

    Queue::assertPushed(SendLeadReplyJob::class, function (SendLeadReplyJob $job) use ($fx) {
        $seen = (fn () => ['subject' => $this->subject, 'body' => $this->body, 'template' => $this->emailTemplateName, 'recipients' => $this->recipients])->call($job);

        return $seen['template'] === 'Missed You'
            && str_contains($seen['subject'], 'Sorry we missed you')
            && str_contains($seen['body'], 'Hi Veera')
            && str_contains($seen['body'], 'Sorry we missed you &mdash; we had your consultation with ')
            && str_contains($seen['body'], ' scheduled for <strong>Thu, Nov 5 · 11:00 AM</strong> and weren&rsquo;t able to connect, we left our business card at your door.</p>')
            && str_contains($seen['body'], 'please <a href="')
            && str_contains($seen['body'], '/lead/times/'.$fx['lead']->id)
            && str_contains($seen['body'], '">pick new consultation times</a> that suit you and we&rsquo;ll confirm ASAP.')
            && str_contains($seen['body'], 'Greg &amp; Patryk')
            && $seen['recipients'] === [$fx['contact']->email];
    });

    $lead = missedYouLead($fx['lead']);
    expect($lead->last_status?->title)->toBe('Replied')
        ->and($lead->missedConsult())->toMatchArray(['task_id' => $task->id, 'label' => 'Thu, Nov 5 · 11:00 AM'])
        // The missed consult still stands in the project's history.
        ->and(Task::withoutGlobalScopes()->find($task->id)->deleted_at)->toBeNull();
});

it('treats a homeowner we missed as rescheduling on the public picker, not as a first contact', function () {
    Queue::fake();
    $fx = missedYouFixture();
    $task = missedYouConsult($fx);

    // While the consult task exists the picker already counts them as
    // rescheduling; the mark is what keeps that true once the task is gone
    // (removed from the project after the no-show, say).
    $task->forceDelete();
    expect(PickTimes::minLeadHours(missedYouLead($fx['lead'])))->toBe(PickTimes::MIN_LEAD_HOURS);

    missedYouLead($fx['lead'])->markConsultMissed(['task_id' => 1, 'label' => 'Thu, Nov 5 · 11:00 AM']);

    expect(PickTimes::isRescheduling(missedYouLead($fx['lead'])))->toBeTrue()
        ->and(PickTimes::minLeadHours(missedYouLead($fx['lead'])))->toBe(PickTimes::MIN_LEAD_HOURS_RESCHEDULE);
});

it('is not offered while the consult has not started, and a send with that template is refused', function () {
    Queue::fake();
    $fx = missedYouFixture();
    missedYouConsult($fx, startTime: '15:00');

    $component = Livewire::actingAs($fx['admin'])
        ->test(LeadCreate::class)
        ->call('editLead', $fx['lead']->id)
        ->set('from', 'patryk@example.test')
        ->set('to', [$fx['contact']->email]);

    expect($component->instance()->canUseMissedYouTemplate)->toBeFalse();

    // Picked from the template list anyway: send() refuses it.
    $component->set('selectedTemplateId', EmailTemplate::query()->where('name', MissedYouTemplate::NAME)->value('id'))
        ->call('send_message');

    Queue::assertNotPushed(SendLeadReplyJob::class);
    $lead = missedYouLead($fx['lead']);
    expect($lead->last_status?->title)->toBe('Won')
        ->and($lead->hasMissedConsult())->toBeFalse();
});

it('says so when no Missed You template exists instead of offering it', function () {
    Queue::fake();
    $fx = missedYouFixture();
    missedYouConsult($fx);
    EmailTemplate::query()->where('name', MissedYouTemplate::NAME)->delete();

    $component = Livewire::actingAs($fx['admin'])
        ->test(LeadCreate::class)
        ->call('editLead', $fx['lead']->id);
    $before = $component->get('selectedTemplateId');

    expect($component->instance()->canUseMissedYouTemplate)->toBeFalse();

    $component->call('useMissedYouTemplate');

    expect($component->get('selectedTemplateId'))->toBe($before);
    Queue::assertNotPushed(SendLeadReplyJob::class);
    expect(missedYouLead($fx['lead'])->hasMissedConsult())->toBeFalse();
});

it('clears the missed mark when the consult is rebooked', function () {
    Queue::fake();
    $fx = missedYouFixture();
    $task = missedYouConsult($fx);
    $lead = missedYouLead($fx['lead']);
    $lead->markConsultMissed(['task_id' => $task->id, 'label' => 'Thu, Nov 5 · 11:00 AM']);

    $booked = app(LeadReplyComposer::class)->bookConsult(
        $lead, $fx['client'], 'Veera Chiranjeevi', '2334 Phillips Drive', 'in_person',
        [['date' => '2026-11-10', 'time' => '1-3 PM']], [0], '13:00', 'Bedroom Suite',
    );

    expect($booked)->toBeTrue()
        ->and(missedYouLead($fx['lead'])->hasMissedConsult())->toBeFalse()
        ->and(Task::withoutGlobalScopes()->find($task->id)->start_date->format('Y-m-d'))->toBe('2026-11-10');
});

it('builds the Missed You template from the vendor\'s own Consult signature', function () {
    $consult = '<p>Hi {{client_first_name}},</p><p></p><p>{{lead_intro}}</p><p></p><p>{{lead_time_block}}</p><p></p><p>Thank you,<br>{{short_vendor_name}}<br>Greg &amp; Patryk | (224) 735-4200<br><a href="https://www.gs.construction">www.gs.construction</a></p>';

    $body = MissedYouTemplate::bodyFrom($consult);

    expect($body)->toStartWith('<p>Hi {{client_first_name}},</p>')
        ->toContain('<p>{{missed_consult_line}}, we left our business card at your door.</p>')
        ->toContain('please {{pick_times_link}} that suit you')
        ->toContain('Greg &amp; Patryk | (224) 735-4200')
        ->toContain('www.gs.construction')
        ->not->toContain('{{lead_time_block}}')
        ->and(MissedYouTemplate::bodyFrom(null))->toContain('<p>Thank you,<br>{{short_vendor_name}}</p>')
        ->and(MissedYouTemplate::subject())->toContain('Sorry we missed you');
});
