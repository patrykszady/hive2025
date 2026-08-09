<?php

use App\Models\CompanyEmail;
use App\Models\EmailTracking;
use App\Models\Lead;
use App\Models\SmsGroupThread;
use App\Models\User;
use App\Models\Vendor;
use App\Services\CrewLeadEmailService;
use App\Services\GroupSmsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function leadFlowFixture(): array
{
    $vendor = Vendor::factory()->create();
    $vendor->forceFill(['business_type' => 'LLC', 'registration' => ['registered' => true]])->save();

    $user = new User();
    $user->forceFill([
        'first_name' => 'Patryk',
        'last_name' => 'Szady',
        'email' => 'flow.'.uniqid().'@example.test',
        'cell_phone' => fake()->unique()->numerify('224555####'),
        'primary_vendor_id' => $vendor->id,
        'registration' => ['registered' => true],
    ]);
    $user->save();
    $vendor->users()->attach($user->id, ['role_id' => 1]);

    $lead = Lead::withoutEvents(fn () => Lead::create([
        'date' => now(),
        'origin' => 'Email',
        'lead_data' => ['name' => 'Kathy Moseler', 'email' => 'kathy@example.test', 'phone' => '7606853015'],
        'belongs_to_vendor_id' => $vendor->id,
        'created_by_user_id' => $user->id,
    ]));

    return ['vendor' => $vendor, 'user' => $user, 'lead' => $lead];
}

// ── Inbound replies land on the lead ────────────────────────────────────

it('files an email reply onto the lead and hands the ball back to the team', function () {
    $fx = leadFlowFixture();
    $fx['lead']->statuses()->create(['title' => 'New', 'belongs_to_vendor_id' => $fx['vendor']->id]);
    $fx['lead']->statuses()->create(['title' => 'Replied', 'belongs_to_vendor_id' => $fx['vendor']->id]);

    $method = new \ReflectionMethod(CrewLeadEmailService::class, 'recordLeadReply');
    $method->setAccessible(true);
    $leadId = $method->invoke(app(CrewLeadEmailService::class), [
        'from_email' => 'kathy@example.test',
        'subject' => 'Re: Termite Repair Bid Request',
        'message_at' => now(),
    ], 'Thursday at 2pm works great for us!');

    $lead = Lead::withoutGlobalScopes()->find($fx['lead']->id);

    expect($leadId)->toBe($lead->id)
        ->and($lead->lead_data['email_replies'][0]['body'])->toContain('Thursday at 2pm')
        ->and($lead->lead_data['email_replies'][0]['subject'])->toBe('Re: Termite Repair Bid Request')
        // Replied → New: they answered, the ball is ours again.
        ->and($lead->last_status->title)->toBe('New');
});

it('does not touch status when the lead was not waiting on us', function () {
    $fx = leadFlowFixture();
    $fx['lead']->statuses()->create(['title' => 'Won', 'belongs_to_vendor_id' => $fx['vendor']->id]);

    $method = new \ReflectionMethod(CrewLeadEmailService::class, 'recordLeadReply');
    $method->setAccessible(true);
    $method->invoke(app(CrewLeadEmailService::class), [
        'from_email' => 'kathy@example.test',
        'subject' => 'Re: thanks',
        'message_at' => now(),
    ], 'Great, see you then.');

    $lead = Lead::withoutGlobalScopes()->find($fx['lead']->id);

    expect($lead->last_status->title)->toBe('Won')
        ->and($lead->lead_data['email_replies'])->toHaveCount(1);
});

// ── Text the schedule link ──────────────────────────────────────────────

it('texts the schedule link into an opted-in thread', function () {
    $fx = leadFlowFixture();

    $thread = SmsGroupThread::create([
        'from_number' => '+12247354200',
        'vendor_id' => $fx['vendor']->id,
        'participants' => ['+17606853015'],
    ]);
    $thread->threadParticipants()->create(['phone_number' => '+17606853015', 'opted_in_at' => now()]);

    $this->mock(GroupSmsService::class, function ($mock) use ($thread) {
        $mock->shouldReceive('sendToThread')
            ->once()
            ->withArgs(fn ($t, $text) => $t->id === $thread->id
                // Same shape as Send Schedule: "Hi Kathy,\n<ask>: <link>".
                && str_starts_with($text, "Hi Kathy,\n\n")
                && str_contains($text, 'Pick a consultation time with')
                && preg_match('#(l/|lead/times/)#', $text));
    });

    Livewire::actingAs($fx['user'])
        ->test(\App\Livewire\Leads\LeadCreate::class)
        ->call('editLead', $fx['lead']->id)
        ->call('textScheduleLink');
});

it('starts the consent flow for a number with no thread, without pretending the link went out', function () {
    $fx = leadFlowFixture();

    $this->mock(GroupSmsService::class, function ($mock) {
        $mock->shouldReceive('sendNewGroup')->once()
            ->withArgs(fn ($phones) => $phones === ['+17606853015']);
        $mock->shouldReceive('sendToThread')->never();
    });

    Livewire::actingAs($fx['user'])
        ->test(\App\Livewire\Leads\LeadCreate::class)
        ->call('editLead', $fx['lead']->id)
        ->call('textScheduleLink');
});

// ── Follow-up automation ────────────────────────────────────────────────

it('nudges a lead that sat in Replied, exactly once', function () {
    Queue::fake();
    $fx = leadFlowFixture();

    $fx['lead']->statuses()->create([
        'title' => 'Replied',
        'belongs_to_vendor_id' => $fx['vendor']->id,
        'created_at' => now()->subDays(4),
    ]);

    CompanyEmail::withoutGlobalScopes()->create([
        'vendor_id' => $fx['vendor']->id,
        'email' => 'patryk@gs.test',
        'grant_id' => 'grant-1',
    ]);

    EmailTracking::withoutGlobalScopes()->create([
        'belongs_to_vendor_id' => $fx['vendor']->id,
        'lead_id' => $fx['lead']->id,
        'event_type' => 'sent',
        'recipient_emails' => ['kathy@example.test'],
        'event_at' => now()->subDays(4),
    ]);

    $this->artisan('leads:follow-up')->assertSuccessful();

    Queue::assertPushed(\App\Jobs\SendLeadReplyJob::class, 1);

    expect(Lead::withoutGlobalScopes()->find($fx['lead']->id)->lead_data['follow_up_sent_at'])->not->toBeNull();

    // Second run: the marker holds — nobody gets nagged twice.
    $this->artisan('leads:follow-up')->assertSuccessful();
    Queue::assertPushed(\App\Jobs\SendLeadReplyJob::class, 1);
});

it('leaves fresh and non-Replied leads alone', function () {
    Queue::fake();
    $fx = leadFlowFixture();

    // Replied only yesterday — not yet.
    $fx['lead']->statuses()->create([
        'title' => 'Replied',
        'belongs_to_vendor_id' => $fx['vendor']->id,
        'created_at' => now()->subDay(),
    ]);

    CompanyEmail::withoutGlobalScopes()->create([
        'vendor_id' => $fx['vendor']->id, 'email' => 'patryk@gs.test', 'grant_id' => 'grant-1',
    ]);

    $this->artisan('leads:follow-up')->assertSuccessful();

    Queue::assertNotPushed(\App\Jobs\SendLeadReplyJob::class);
});

// ── Duplicate detection on manual entry ─────────────────────────────────

it('warns before creating a lead for someone who already has one', function () {
    $fx = leadFlowFixture();

    $component = Livewire::actingAs($fx['user'])
        ->test(\App\Livewire\Leads\LeadCreate::class)
        ->call('addLead')
        ->set('full_name', 'Kathy M')
        ->set('email', 'kathy@example.test')
        ->call('save');

    expect($component->instance()->duplicateMatch)->not->toBeNull()
        ->and($component->instance()->duplicateMatch['lead_id'])->toBe($fx['lead']->id)
        ->and(Lead::withoutGlobalScopes()->count())->toBe(1);

    // Deliberate override goes through.
    $component->call('saveDespiteDuplicate');

    expect(Lead::withoutGlobalScopes()->count())->toBe(2);
});

it('does not mint a twin lead on a double-submitted Create', function () {
    $fx = leadFlowFixture();
    Lead::withoutGlobalScopes()->forceDelete();

    $component = Livewire::actingAs($fx['user'])
        ->test(\App\Livewire\Leads\LeadCreate::class)
        ->call('addLead')
        ->set('full_name', 'Walk-in Caller')
        ->set('email', 'caller@example.test')
        ->call('save')
        // The double-tap: a queued second submit lands after the first save
        // created the lead and flipped the modal into edit mode.
        ->call('save');

    expect(Lead::withoutGlobalScopes()->count())->toBe(1);

    // Same for the duplicate-override path: one tap, one lead.
    $again = Livewire::actingAs($fx['user'])
        ->test(\App\Livewire\Leads\LeadCreate::class)
        ->call('addLead')
        ->set('full_name', 'Walk-in Caller')
        ->set('email', 'caller@example.test')
        ->call('saveDespiteDuplicate');

    expect(Lead::withoutGlobalScopes()->count())->toBe(2)
        ->and($again->instance()->createAnyway)->toBeFalse();
});

// ── Deleting a lead a homeowner still holds a link to ───────────────────

it('warns the delete flows when a scheduling link is still out', function () {
    $fx = leadFlowFixture();

    // No link yet — no warning.
    expect($fx['lead']->deleteImpact()['schedule_link'])->toBeFalse();

    \App\Models\ShortLink::create([
        'code' => 'abc123',
        'destination' => 'https://hive.test/lead/times/'.$fx['lead']->id.'?expires=1&signature=x',
    ]);

    // A lead whose id merely PREFIXES another id must not match (8 vs 80).
    $other = Lead::withoutEvents(fn () => Lead::create([
        'date' => now(), 'origin' => 'Email',
        'lead_data' => ['name' => 'Other'],
        'belongs_to_vendor_id' => $fx['vendor']->id,
        'created_by_user_id' => $fx['user']->id,
    ]));

    expect($fx['lead']->fresh()->deleteImpact()['schedule_link'])->toBeTrue()
        ->and($other->deleteImpact()['schedule_link'])->toBeFalse();

    // The bulk modal names who is holding something.
    $impact = Livewire::actingAs($fx['user'])
        ->test(\App\Livewire\Leads\LeadsIndex::class)
        ->set('selected', [$fx['lead']->id, $other->id])
        ->instance()->bulkDeleteImpact();

    expect($impact['holding'])->toBe(['Kathy Moseler']);
});

// ── Resolving the lead that speaks for a client ─────────────────────────

it('resolves the client lead past trashed stubs and prefers freshest availability', function () {
    $fx = leadFlowFixture();

    $client = \App\Models\Client::factory()->create();
    $client->users()->attach($fx['user']->id);

    $original = Lead::withoutEvents(fn () => Lead::create([
        'date' => now()->subDays(9), 'origin' => 'Website',
        'user_id' => $fx['user']->id,
        'lead_data' => [
            'name' => 'Original', 'availability_updated_at' => now()->subDay()->toDateTimeString(),
            'availability' => [['date' => now()->addDays(3)->format('Y-m-d'), 'time' => '9-11 AM']],
        ],
        'belongs_to_vendor_id' => $fx['vendor']->id, 'created_by_user_id' => $fx['user']->id,
    ]));

    // A NEWER, empty stub that was later soft-deleted (exactly the shape the
    // Aug-7 recovery left behind) — it must never shadow the real lead.
    $stub = Lead::withoutEvents(fn () => Lead::create([
        'date' => now(), 'origin' => 'Manual', 'user_id' => $fx['user']->id,
        'lead_data' => ['name' => 'Stub'],
        'belongs_to_vendor_id' => $fx['vendor']->id, 'created_by_user_id' => $fx['user']->id,
    ]));
    $stub->delete();

    expect(Lead::latestForClient($client)?->id)->toBe($original->id);

    // A live lead with FRESHER availability outranks an older one even with
    // a lower id.
    $fresher = Lead::withoutEvents(fn () => Lead::create([
        'date' => now(), 'origin' => 'Website', 'user_id' => $fx['user']->id,
        'lead_data' => [
            'name' => 'Fresher', 'availability_updated_at' => now()->toDateTimeString(),
            'availability' => [['date' => now()->addDays(5)->format('Y-m-d'), 'time' => '1-3 PM']],
        ],
        'belongs_to_vendor_id' => $fx['vendor']->id, 'created_by_user_id' => $fx['user']->id,
    ]));

    expect(Lead::latestForClient($client)?->id)->toBe($fresher->id);
});

it('books a homeowner slot into the Meet form via the two-stage picker', function () {
    $fx = leadFlowFixture();

    $client = \App\Models\Client::factory()->create();
    $client->users()->attach($fx['user']->id);
    $client->vendors()->attach($fx['vendor']->id);

    $project = \App\Models\Project::withoutEvents(fn () => \App\Models\Project::create([
        'project_name' => 'Deck', 'client_id' => $client->id,
        'address' => '1 Deck St', 'city' => 'Palatine', 'state' => 'IL', 'zip_code' => '60067',
        'belongs_to_vendor_id' => $fx['vendor']->id,
    ]));

    $date = now()->addDays(4)->format('Y-m-d');
    $fx['lead']->update(['lead_data' => array_merge($fx['lead']->lead_data->toArray(), [
        'availability' => [['date' => $date, 'time' => '4-6 PM']],
        'availability_updated_at' => now()->toDateTimeString(),
    ])]);
    $fx['lead']->forceFill(['user_id' => $fx['user']->id])->save();

    $component = Livewire::actingAs($fx['user'])
        ->test(\App\Livewire\Tasks\TaskCreate::class)
        ->call('addTask', $project->id)
        ->set('form.type', 'Meet');

    // Stage 1: the slot books the day at the window start.
    $component->call('applyHomeownerTime', 0);
    expect($component->instance()->form->dates)->toBe([$date])
        ->and(data_get($component->instance()->form->time_settings, "$date.start_time"))->toBe('16:00');

    // The exact-time chips mirror the composer: 4:00 through 5:30.
    expect(collect($component->instance()->homeownerExactOptions)->pluck('label')->all())
        ->toBe(['4:00 PM', '4:30 PM', '5:00 PM', '5:30 PM']);

    // Stage 2: narrowing to 4:30 makes a 30-minute Meet from there.
    $component->call('selectHomeownerExactTime', '16:30');
    expect(data_get($component->instance()->form->time_settings, "$date.start_time"))->toBe('16:30')
        ->and(data_get($component->instance()->form->time_settings, "$date.end_time"))->toBe('17:00');

    // Garbage exact times are refused.
    $component->call('selectHomeownerExactTime', '23:45');
    expect(data_get($component->instance()->form->time_settings, "$date.start_time"))->toBe('16:30');
});

it('mirrors team members and participants in both directions on a Meet', function () {
    $fx = leadFlowFixture();
    // fx's user pivot lacks is_employed — set it so the employees pool sees them.
    $fx['vendor']->users()->updateExistingPivot($fx['user']->id, ['is_employed' => 1]);

    $greg = new User();
    $greg->forceFill([
        'first_name' => 'Grzegorz', 'last_name' => 'Szady',
        'email' => 'greg.'.uniqid().'@example.test',
        'cell_phone' => fake()->unique()->numerify('224999####'),
        'primary_vendor_id' => $fx['vendor']->id,
        'registration' => ['registered' => true],
    ]);
    $greg->save();
    $fx['vendor']->users()->attach($greg->id, ['role_id' => 1, 'is_employed' => 1]);

    $component = Livewire::actingAs($fx['user'])
        ->test(\App\Livewire\Tasks\TaskCreate::class)
        ->call('addTask')
        ->set('form.type', 'Meet')
        ->set('form.user_ids', [$fx['user']->id, $greg->id]);

    expect($component->instance()->form->meeting_participants)
        ->toContain(strtolower($fx['user']->email))
        ->toContain(strtolower($greg->email));

    // Deselect Greg up top → he leaves the invite.
    $component->set('form.user_ids', [$fx['user']->id]);
    expect($component->instance()->form->meeting_participants)
        ->not->toContain(strtolower($greg->email))
        ->toContain(strtolower($fx['user']->email));

    // Remove Patryk's participant chip → he leaves Team Members too.
    $index = array_search(strtolower($fx['user']->email), $component->instance()->form->meeting_participants, true);
    $component->call('removeMeetingParticipant', $index);

    expect($component->instance()->form->meeting_participants)->not->toContain(strtolower($fx['user']->email))
        ->and($component->instance()->form->user_ids)->not->toContain($fx['user']->id);
});
