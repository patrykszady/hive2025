<?php

use App\Models\AppNotification;
use App\Models\Client;
use App\Models\DeliveryText;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\SmsGroupThread;
use App\Models\SmsMessage;
use App\Models\Task;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Deliveries\DeliveryTextIntake;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * Studio 41 texted from a number nobody had saved (2026-10-21): "Delivery is
 * scheduled for Wed 10/28 12-3pm at 949 SOUTH ELM COURT, PALATINE … Reply 1
 * to CONFIRM … order# S2472002". The house has four projects; the Active
 * one (381, the kitchen) is where it goes.
 */
const STUDIO41_TEXT = 'Studio 41 delivery confirmation needed! Delivery is scheduled for Wed 10/28 12-3pm at 949 SOUTH ELM COURT, PALATINE. The driver will call/text 30 mins prior with curbside/tailgate only. Reply 1 to CONFIRM or 2 to RESCHEDULE. Kindly note that if we do not receive delivery confirmation, delivery will be canceled. Reference order# S2472002. Thank you!';

/** @return array<string, mixed> */
function deliveryFixture(): array
{
    config(['services.openai.api_key' => 'test-key', 'deliveries.sms_intake.enabled' => true]);

    $company = Vendor::factory()->create(['business_name' => 'GS Construction & Remodeling, Inc']);
    $admin = new User();
    $admin->forceFill(['first_name' => 'Patryk', 'last_name' => 'Admin', 'email' => 'deliveries.'.uniqid().'@example.test', 'cell_phone' => fake()->unique()->numerify('224780####'), 'primary_vendor_id' => $company->id]);
    $admin->save();
    $company->users()->attach($admin->id, ['role_id' => 1]);
    test()->actingAs($admin);

    $client = Client::factory()->create();
    $project = fn (string $name, int $status) => tap(Project::factory()->create(['client_id' => $client->id, 'project_name' => $name, 'address' => '949 S Elm Ct', 'city' => 'Palatine']), function (Project $created) use ($company, $status) {
        $created->forceFill(['belongs_to_vendor_id' => $company->id])->saveQuietly();
        ProjectStatus::create(['project_id' => $created->id, 'belongs_to_vendor_id' => $company->id, 'status_code' => $status, 'start_date' => now()->toDateString()]);
    });

    $finished = $project('Bathroom', 7);
    $kitchen = $project('Kitchen', 6);

    $thread = SmsGroupThread::create(['from_number' => '+12247354200', 'participants' => ['+17732808830'], 'vendor_id' => $company->id, 'last_activity_at' => now()]);
    \Illuminate\Support\Facades\Auth::forgetUser();

    return compact('company', 'admin', 'kitchen', 'finished', 'thread');
}

function deliveryText(SmsGroupThread $thread, string $text, string $at = '2026-10-21 14:48:51'): SmsMessage
{
    $message = new SmsMessage(['thread_id' => $thread->id, 'provider' => 'telnyx', 'direction' => SmsMessage::DIRECTION_INBOUND, 'from_number' => '+17732808830', 'to_numbers' => ['+12247354200'], 'text' => $text, 'status' => 'received']);
    $message->created_at = $at;
    $message->save();

    return $message;
}

/**
 * The reader's answers, one per text read, in order.
 *
 * @param  array<string, mixed>  ...$readings  overrides of the Studio 41 reading
 */
function fakeDeliveryReading(array ...$readings): void
{
    $sequence = Http::fakeSequence('api.openai.com/*');

    foreach ($readings ?: [[]] as $overrides) {
        $sequence->push(['choices' => [['message' => ['content' => json_encode(array_merge([
            'is_delivery' => true, 'confidence' => 0.97, 'supplier' => 'Studio 41', 'date' => '2026-10-28',
            'start_time' => '12:00', 'end_time' => '15:00', 'address' => '949 SOUTH ELM COURT, PALATINE',
            'order_number' => 'S2472002', 'needs_confirmation' => true,
            'summary' => 'curbside/tailgate only; driver calls 30 min before', 'reason' => 'Supplier delivery window.',
        ], $overrides))]]]]);
    }
}

it('schedules a supplier\'s delivery text on the open project at its address and alerts the admins', function () {
    $fx = deliveryFixture();
    fakeDeliveryReading();
    $message = deliveryText($fx['thread'], STUDIO41_TEXT);

    $decision = app(DeliveryTextIntake::class)->consider($message);

    $task = Task::withoutGlobalScopes()->find($decision->task_id);
    expect($decision->outcome)->toBe(DeliveryText::OUTCOME_SCHEDULED)
        ->and($decision->project_id)->toBe($fx['kitchen']->id)
        ->and($task->title)->toBe('Studio 41 delivery')
        ->and($task->project_id)->toBe($fx['kitchen']->id)
        ->and($task->start_date->toDateString())->toBe('2026-10-28')
        ->and(data_get($task->options, 'time_settings.2026-10-28.start_time'))->toBe('12:00')
        ->and(data_get($task->options, 'time_settings.2026-10-28.end_time'))->toBe('15:00')
        ->and($task->notes)->toContain('Studio 41 order S2472002: delivery Wed 10/28, 12–3 pm at 949 SOUTH ELM COURT, PALATINE')
        ->and($task->notes)->toContain('They cancel unless it is confirmed')
        ->and($task->notes)->toContain('Reference order# S2472002');

    $alert = AppNotification::query()->where('user_id', $fx['admin']->id)->sole();
    expect($alert->type)->toBe('delivery_scheduled')
        ->and($alert->title)->toBe('Delivery scheduled: Studio 41, Wed 10/28')
        ->and($alert->action_url)->toBe('/messages?threadId='.$fx['thread']->id);

    // Confirming is a person's call: nothing is texted back.
    expect(SmsMessage::query()->where('direction', SmsMessage::DIRECTION_OUTBOUND)->count())->toBe(0);
});

it('moves the task when a later text reschedules the same order', function () {
    $fx = deliveryFixture();
    fakeDeliveryReading([], ['date' => '2026-10-30', 'start_time' => '08:00', 'end_time' => '11:00', 'needs_confirmation' => false]);
    $first = app(DeliveryTextIntake::class)->consider(deliveryText($fx['thread'], STUDIO41_TEXT));
    $second = app(DeliveryTextIntake::class)->consider(deliveryText($fx['thread'], 'Studio 41: your delivery for order S2472002 has been rescheduled to Fri 10/30 8-11am.', '2026-10-22 09:00:00'));

    $task = Task::withoutGlobalScopes()->find($first->task_id);
    expect($second->outcome)->toBe(DeliveryText::OUTCOME_RESCHEDULED)
        ->and($second->task_id)->toBe($first->task_id)
        ->and(Task::withoutGlobalScopes()->where('project_id', $fx['kitchen']->id)->count())->toBe(1)
        ->and($task->start_date->toDateString())->toBe('2026-10-30')
        ->and(data_get($task->options, 'time_settings.2026-10-30.start_time'))->toBe('08:00')
        ->and($task->notes)->toContain('delivery Fri 10/30, 8–11 am');
});

it('leaves a delivery that is already on the schedule alone', function () {
    $fx = deliveryFixture();
    fakeDeliveryReading([], []);
    $first = app(DeliveryTextIntake::class)->consider(deliveryText($fx['thread'], STUDIO41_TEXT));
    $repeat = app(DeliveryTextIntake::class)->consider(deliveryText($fx['thread'], STUDIO41_TEXT, '2026-10-22 09:00:00'));

    expect($repeat->outcome)->toBe(DeliveryText::OUTCOME_ALREADY_SCHEDULED)
        ->and($repeat->task_id)->toBe($first->task_id)
        ->and(AppNotification::query()->count())->toBe(1);
});

it('alerts instead of guessing when no open project is at the address', function () {
    $fx = deliveryFixture();
    fakeDeliveryReading(['address' => '12 Oak St, Skokie']);

    $decision = app(DeliveryTextIntake::class)->consider(deliveryText($fx['thread'], STUDIO41_TEXT));

    expect($decision->outcome)->toBe(DeliveryText::OUTCOME_UNPLACED)
        ->and($decision->task_id)->toBeNull()
        ->and(Task::withoutGlobalScopes()->count())->toBe(0)
        ->and(AppNotification::query()->sole()->action_url)->toBe('/messages?threadId='.$fx['thread']->id);
});

it('records a text the reader says is no delivery, and writes no task', function () {
    $fx = deliveryFixture();
    fakeDeliveryReading(['is_delivery' => false, 'confidence' => 0.9, 'date' => null]);

    $decision = app(DeliveryTextIntake::class)->consider(deliveryText($fx['thread'], 'Your Studio 41 invoice for order S2472002 is ready. Delivery questions? Call us.'));

    expect($decision->outcome)->toBe(DeliveryText::OUTCOME_NOT_DELIVERY)
        ->and(Task::withoutGlobalScopes()->count())->toBe(0)
        ->and(AppNotification::query()->count())->toBe(0);
});

it('never reads a client\'s conversation or a text with no delivery words', function () {
    $fx = deliveryFixture();
    Http::fake();
    $clientThread = SmsGroupThread::create(['from_number' => '+12247354200', 'participants' => ['+17735550100'], 'vendor_id' => $fx['company']->id, 'client_id' => Client::factory()->create()->id, 'last_activity_at' => now()]);

    expect(app(DeliveryTextIntake::class)->consider(deliveryText($clientThread, STUDIO41_TEXT)))->toBeNull()
        ->and(app(DeliveryTextIntake::class)->consider(deliveryText($fx['thread'], 'Thanks, see you Monday!')))->toBeNull();

    Http::assertNothingSent();
});

it('reports without writing on a dry run', function () {
    $fx = deliveryFixture();
    fakeDeliveryReading();

    $this->artisan('deliveries:read-texts', ['message' => [deliveryText($fx['thread'], STUDIO41_TEXT)->id], '--dry-run' => true])
        ->expectsOutputToContain('scheduled — Studio 41 2026-10-28, project '.$fx['kitchen']->id)
        ->assertSuccessful();

    expect(DeliveryText::query()->count())->toBe(0)
        ->and(Task::withoutGlobalScopes()->count())->toBe(0)
        ->and(AppNotification::query()->count())->toBe(0);
});
