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
function consultTextFixture(bool $optedIn = true, bool $withClient = true): array
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

    $thread = SmsGroupThread::create([
        'from_number' => '+12247354200',
        'vendor_id' => $vendor->id,
        'participants' => ['+12245321090'],
        'client_id' => $client?->id,
    ]);
    $thread->threadParticipants()->create(['phone_number' => '+12245321090', 'opted_in_at' => $optedIn ? now() : null]);

    return compact('vendor', 'admin', 'contact', 'client', 'thread');
}

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
        // Nothing new arrived: no "new lead" notification for the team.
        ->and(AppNotification::count())->toBe(0);

    // A second text reuses the lead rather than minting another.
    $this->mock(GroupSmsService::class, fn ($mock) => $mock->shouldReceive('sendToThread')->once());
    app(ConsultScheduleLinkTexter::class)->textToThread($fx['thread'], $fx['admin']);
    expect(Lead::withoutGlobalScopes()->where('user_id', $fx['contact']->id)->count())->toBe(1);
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

it('is reachable from the conversation menu', function () {
    $fx = consultTextFixture();

    $this->mock(GroupSmsService::class, fn ($mock) => $mock->shouldReceive('sendToThread')->once());

    Livewire::actingAs($fx['admin'])
        ->test(SmsConversation::class)
        ->call('loadThread', $fx['thread']->id)
        ->assertSee('Text consult scheduling link')
        ->call('textConsultScheduleLink')
        ->assertHasNoErrors();
});
