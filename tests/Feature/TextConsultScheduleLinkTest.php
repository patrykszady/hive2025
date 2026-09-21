<?php

use App\Livewire\Sms\SmsConversation;
use App\Models\AppNotification;
use App\Models\Client;
use App\Models\Lead;
use App\Models\Project;
use App\Models\SmsGroupThread;
use App\Models\Task;
use App\Models\User;
use App\Models\Vendor;
use App\Services\ConsultScheduleLinkTexter;
use App\Services\GroupSmsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Texting a client the signed "pick consultation times" link from Messages —
 * the link the lead emails carry — for a project that never came through
 * the leads pipeline (Debby, project 444, thread 42).
 */
function consultTextFixture(bool $optedIn = true, bool $withClient = true, bool $withPartner = false): array
{
    $vendor = Vendor::factory()->create(['options' => ['short_name' => 'GSC']]);
    $admin = new User();
    $admin->forceFill([
        'first_name' => 'Patryk', 'last_name' => 'Sender',
        'email' => 'consult-text-admin.'.uniqid().'@example.test',
        'cell_phone' => fake()->unique()->numerify('224555####'),
        'primary_vendor_id' => $vendor->id,
    ]);
    $admin->save();
    $vendor->users()->attach($admin->id, ['role_id' => 1]);

    $contact = User::query()->create([
        'first_name' => 'Debby', 'last_name' => 'Hill',
        'email' => 'debby.'.uniqid().'@example.com',
        'cell_phone' => '2245321090',
    ]);
    $client = null;
    if ($withClient) {
        $client = Client::factory()->create(['address' => '1463 W Winnetka St', 'city' => 'Palatine', 'state' => 'IL', 'zip_code' => '60067']);
        $client->vendors()->attach($vendor->id);
        $client->users()->attach($contact->id);
    }

    $participants = ['+12245321090'];
    $partner = null;
    if ($withPartner) {
        $partner = User::query()->create([
            'first_name' => 'Alan', 'last_name' => 'Hill',
            'email' => 'alan.'.uniqid().'@example.com',
            'cell_phone' => '2245321091',
        ]);
        $client?->users()->attach($partner->id);
        $participants[] = '+12245321091';
    }

    $thread = SmsGroupThread::create([
        'from_number' => '+12247354200',
        'vendor_id' => $vendor->id,
        'participants' => $participants,
        'client_id' => $client?->id,
    ]);
    foreach ($participants as $number) {
        $thread->threadParticipants()->create(['phone_number' => $number, 'opted_in_at' => $optedIn ? now() : null]);
    }

    return compact('vendor', 'admin', 'contact', 'client', 'thread', 'partner');
}

it('greets everyone on a couple\'s thread, not just the first contact', function () {
    $fx = consultTextFixture(withPartner: true);
    $this->actingAs($fx['admin']);

    $this->mock(GroupSmsService::class, function ($mock) {
        $mock->shouldReceive('sendToThread')
            ->once()
            ->withArgs(fn ($thread, $text) => str_starts_with($text, "Hi Debby & Alan,\n\nPick a consultation time with GSC here: "));
    });

    expect(app(ConsultScheduleLinkTexter::class)->textToThread($fx['thread'], $fx['admin'])['ok'])->toBeTrue();

    // One lead, on the first contact, as before.
    expect(Lead::withoutGlobalScopes()->where('user_id', $fx['contact']->id)->count())->toBe(1)
        ->and(Lead::withoutGlobalScopes()->where('user_id', $fx['partner']->id)->count())->toBe(0);
});

it('texts the pick-times link and quietly gives the contact a lead to hang it on', function () {
    $fx = consultTextFixture();
    $this->actingAs($fx['admin']);

    $this->mock(GroupSmsService::class, function ($mock) use ($fx) {
        $mock->shouldReceive('sendToThread')
            ->once()
            ->withArgs(fn ($thread, $text) => $thread->id === $fx['thread']->id
                && str_starts_with($text, "Hi Debby,\n\nPick a consultation time with GSC here: ")
                && preg_match('#(l/|lead/times/)#', $text)
                && ! str_contains($text, 'no longer works'));
    });

    $result = app(ConsultScheduleLinkTexter::class)->textToThread($fx['thread'], $fx['admin']);
    expect($result['ok'])->toBeTrue();

    $lead = Lead::withoutGlobalScopes()->where('user_id', $fx['contact']->id)->first();
    expect($lead)->not->toBeNull()
        ->and($lead->origin)->toBe('Messages')
        ->and($lead->lead_data['phone'])->toBe('2245321090')
        ->and($lead->lead_data['address'])->toBe('1463 W Winnetka St')
        // Born with a stage like every other lead, then Replied: the link is
        // our reply and the ball is with them (lead 171 sat at "Set status").
        ->and($lead->statuses()->orderBy('id')->pluck('title')->all())->toBe(['New', 'Replied'])
        // Nothing new arrived: no "new lead" notification for the team.
        ->and(AppNotification::count())->toBe(0);

    // A second text reuses the lead rather than minting another, and writes no second Replied.
    $this->mock(GroupSmsService::class, fn ($mock) => $mock->shouldReceive('sendToThread')->once());
    app(ConsultScheduleLinkTexter::class)->textToThread($fx['thread'], $fx['admin']);
    expect(Lead::withoutGlobalScopes()->where('user_id', $fx['contact']->id)->count())->toBe(1)
        ->and($lead->statuses()->count())->toBe(2);
});

it('never downgrades a lead that already progressed, and gives a stageless one its New first', function () {
    $fx = consultTextFixture();
    $this->actingAs($fx['admin']);
    $this->mock(GroupSmsService::class, fn ($mock) => $mock->shouldReceive('sendToThread')->twice());

    // A lead made before this path recorded a stage (lead 171, 2026-09-15).
    $lead = Lead::withoutEvents(fn () => Lead::create([
        'date' => now(), 'origin' => 'Messages', 'user_id' => $fx['contact']->id,
        'belongs_to_vendor_id' => $fx['vendor']->id, 'created_by_user_id' => $fx['admin']->id,
        'lead_data' => ['name' => 'Debby'],
    ]));

    app(ConsultScheduleLinkTexter::class)->textToThread($fx['thread'], $fx['admin']);
    expect($lead->statuses()->orderBy('id')->pluck('title')->all())->toBe(['New', 'Replied']);

    // Won stays Won: offering new times does not undo a booking on its own.
    $lead->setStatus('Won');
    app(ConsultScheduleLinkTexter::class)->textToThread($fx['thread'], $fx['admin']);
    expect($lead->fresh()->last_status?->title)->toBe('Won');
});

it('confirms the booked consult and offers new times when one is on the books', function () {
    $fx = consultTextFixture();
    $this->actingAs($fx['admin']);

    $project = Project::create([
        'project_name' => 'Wine Cellar', 'client_id' => $fx['client']->id,
        'address' => '1463 W Winnetka St', 'city' => 'Palatine', 'state' => 'IL', 'zip_code' => '60067',
    ]);
    $date = now()->addDays(5)->format('Y-m-d');
    Task::create([
        'project_id' => $project->id, 'title' => 'GSC/Hill Consult', 'type' => 'Meet',
        'start_date' => $date, 'end_date' => $date, 'order' => 0, 'user_ids' => [$fx['admin']->id], 'notes' => '',
        'options' => ['dates' => [$date], 'time_settings' => [$date => ['use_time' => true, 'start_time' => '13:00', 'end_time' => '13:30']]],
    ]);
    $expectedDay = \Carbon\Carbon::parse($date)->format('D, M j');

    $this->mock(GroupSmsService::class, function ($mock) use ($expectedDay) {
        $mock->shouldReceive('sendToThread')
            ->once()
            ->withArgs(fn ($thread, $text) => str_contains($text, "Your consultation with GSC is booked for {$expectedDay} at 1:00 PM.")
                && str_contains($text, 'If this time no longer works for you, you can pick new consultation times here: ')
                && str_contains($text, 'we’ll confirm the new one ASAP'));
    });

    expect(app(ConsultScheduleLinkTexter::class)->textToThread($fx['thread'], $fx['admin'])['ok'])->toBeTrue();
});

it('sends nothing while the number has not opted in', function () {
    $this->mock(GroupSmsService::class, fn ($mock) => $mock->shouldReceive('sendToThread')->never());

    $pending = consultTextFixture(optedIn: false);
    $result = app(ConsultScheduleLinkTexter::class)->textToThread($pending['thread'], $pending['admin']);

    expect($result['ok'])->toBeFalse()->and($result['heading'])->toBe('Awaiting START reply')
        // No lead is minted for a text that never went out.
        ->and(Lead::withoutGlobalScopes()->count())->toBe(0);
});

it('sends nothing when the thread has no client to hang the link on', function () {
    $this->mock(GroupSmsService::class, fn ($mock) => $mock->shouldReceive('sendToThread')->never());

    $orphan = consultTextFixture(withClient: false);
    $result = app(ConsultScheduleLinkTexter::class)->textToThread($orphan['thread'], $orphan['admin']);

    expect($result['ok'])->toBeFalse()->and($result['heading'])->toBe('No client on this thread');
});

it('the conversation menu drafts the text into the message box and sends nothing', function () {
    $fx = consultTextFixture();

    $this->mock(GroupSmsService::class, fn ($mock) => $mock->shouldReceive('sendToThread')->never());

    Livewire::actingAs($fx['admin'])
        ->test(SmsConversation::class)
        ->call('loadThread', $fx['thread']->id)
        ->assertSee('Draft consult scheduling text')
        ->call('textConsultScheduleLink')
        ->assertHasNoErrors()
        ->assertSet('newMessage', fn ($value) => str_starts_with((string) $value, 'Hi') && str_contains((string) $value, 'Pick a consultation time with GSC here: '));
});

it('says the consult was missed when its time has passed, and keeps it out of the future tense', function () {
    $fx = consultTextFixture();
    $this->actingAs($fx['admin']);
    $project = Project::create([
        'project_name' => 'Wine Cellar', 'client_id' => $fx['client']->id,
        'address' => '1463 W Winnetka St', 'city' => 'Palatine', 'state' => 'IL', 'zip_code' => '60067',
    ]);
    $tz = \App\Livewire\Leads\PickTimes::timezone();
    \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-09-21 15:00', $tz));
    $date = '2026-09-21';
    Task::create([
        'project_id' => $project->id, 'title' => 'GSC/Hill Consult', 'type' => 'Meet',
        'start_date' => $date, 'end_date' => $date, 'order' => 0, 'user_ids' => [$fx['admin']->id], 'notes' => '',
        'options' => ['dates' => [$date], 'time_settings' => [$date => ['use_time' => true, 'start_time' => '09:00', 'end_time' => '10:00']]],
    ]);

    // 9:00 AM this morning, now 3 PM: "we had it scheduled", not "is booked for".
    $composed = app(ConsultScheduleLinkTexter::class)->composeForThread($fx['thread'], $fx['admin']);
    expect($composed['ok'])->toBeTrue();
    expect($composed['message'])->toContain('We had your consultation with GSC scheduled for Mon, Sep 21 at 9:00 AM — let\'s find a new time. Pick one here: ');
    expect($composed['message'])->not->toContain('is booked for');

    // Two days later it still reads as missed; a week and more later it is forgotten and the plain picker is offered.
    \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-09-23 10:00', $tz));
    expect(app(ConsultScheduleLinkTexter::class)->composeForThread($fx['thread'], $fx['admin'])['message'])->toContain('We had your consultation');
    \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-09-30 10:00', $tz));
    expect(app(ConsultScheduleLinkTexter::class)->composeForThread($fx['thread'], $fx['admin'])['message'])->toContain('Pick a consultation time with GSC here: ');

    // A consult later today that has not started is still "booked for".
    \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-09-21 08:00', $tz));
    expect(app(ConsultScheduleLinkTexter::class)->composeForThread($fx['thread'], $fx['admin'])['message'])->toContain('is booked for Mon, Sep 21 at 9:00 AM.');
    \Carbon\Carbon::setTestNow();
});
