<?php

use App\Jobs\SendRealtimeTaskNotification;
use App\Jobs\SyncMeetTaskFromCalendar;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\Vendor;
use App\Services\MeetTaskCalendarService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * A Meet moved on the calendar comes back to its task: event.updated queues a
 * re-read of the event, and meet:sync-from-calendar is the manual catch-up.
 * Only the day and time move, only when the calendar changed after Hive last
 * saved the task, and nothing is written back to the calendar.
 */
beforeEach(function () {
    config([
        'nylas.api_key' => 'test-api-key',
        'nylas.webhook_secret' => 'test-secret',
    ]);

    Http::preventStrayRequests();
    Queue::fake();
});

/**
 * A Chicago vendor's Meet with its invite on the calendar, last saved an hour
 * ago — Patryk/Mark Consult as it stood before the Outlook move.
 *
 * @param  array<string, mixed>  $timeSettings
 */
function calendarSyncMeet(
    string $date = '2026-09-23',
    array $timeSettings = ['use_time' => true, 'start_time' => '14:00', 'end_time' => '14:30'],
    string $eventId = 'evt_meet',
): Task {
    $vendor = Vendor::factory()->create(['timezone' => 'America/Chicago']);

    $project = Project::withoutEvents(fn () => Project::create([
        'project_name' => '3154 Violet',
        'client_id' => Client::factory()->create()->id,
        'address' => '3154 Violet Ln',
        'city' => 'Northbrook',
        'state' => 'IL',
        'zip_code' => '60062',
        'belongs_to_vendor_id' => $vendor->id,
    ]));

    test()->travel(-1)->hours();

    $task = Task::withoutEvents(fn () => Task::create([
        'title' => 'Patryk/Mark Consult',
        'type' => 'Meet',
        'order' => 1,
        'project_id' => $project->id,
        'user_ids' => ['1'],
        'belongs_to_vendor_id' => $vendor->id,
        'created_by_user_id' => 1,
        'start_date' => $date,
        'end_date' => $date,
        'options' => [
            'dates' => [$date],
            'time_settings' => [$date => $timeSettings],
            'meeting_participants' => ['patryk@gs.construction', 'mbrodson@gmail.com'],
            'nylas_meet_event' => [
                'event_id' => $eventId,
                'grant_id' => 'grant_meet',
                'calendar_id' => 'cal_meet',
                'organizer_email' => 'patryk@gs.construction',
            ],
        ],
    ]));

    test()->travelBack();

    return $task;
}

/**
 * The event as Nylas returns it, changed on the calendar just now.
 *
 * @param  array<string, mixed>  $when
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function calendarSyncEvent(array $when, array $overrides = []): array
{
    return ['data' => array_merge([
        'id' => 'evt_meet',
        'grant_id' => 'grant_meet',
        'calendar_id' => 'cal_meet',
        'object' => 'event',
        'status' => 'confirmed',
        'title' => 'Patryk/Mark Consult',
        'updated_at' => now()->timestamp,
        'when' => $when,
    ], $overrides)];
}

/**
 * A Chicago timespan, the way an Outlook event comes back.
 *
 * @return array<string, mixed>
 */
function chicagoTimespan(string $start, string $end): array
{
    return [
        'object' => 'timespan',
        'start_time' => Carbon::parse($start, 'America/Chicago')->timestamp,
        'end_time' => Carbon::parse($end, 'America/Chicago')->timestamp,
        'start_timezone' => 'America/Chicago',
        'end_timezone' => 'America/Chicago',
    ];
}

/**
 * @param  array<string, mixed>  $response
 */
function fakeCalendarEvent(array $response, int $status = 200): void
{
    Http::fake([
        'https://api.us.nylas.com/v3/grants/grant_meet/events/evt_meet*' => Http::response($response, $status),
    ]);
}

function signedCalendarWebhook(array $payload)
{
    $body = json_encode($payload);

    return test()->call('POST', '/webhooks/nylas', [], [], [], [
        'HTTP_X-Nylas-Signature' => hash_hmac('sha256', $body, 'test-secret'),
        'CONTENT_TYPE' => 'application/json',
    ], $body);
}

it('moves a meet to the time it was given on the calendar', function () {
    $task = calendarSyncMeet();
    fakeCalendarEvent(calendarSyncEvent(chicagoTimespan('2026-09-23 13:45', '2026-09-23 14:15')));

    $move = app(MeetTaskCalendarService::class)->syncFromCalendar($task);

    $task->refresh();

    expect($move)->toBe(['from' => 'Sep 23, 2:00 PM–2:30 PM', 'to' => 'Sep 23, 1:45 PM–2:15 PM'])
        ->and($task->start_date->toDateString())->toBe('2026-09-23')
        ->and($task->end_date->toDateString())->toBe('2026-09-23')
        ->and((array) $task->options->time_settings->{'2026-09-23'})->toBe(['use_time' => true, 'start_time' => '13:45', 'end_time' => '14:15'])
        ->and($task->options->meeting_participants)->toBe(['patryk@gs.construction', 'mbrodson@gmail.com'])
        ->and($task->options->nylas_meet_event->event_id)->toBe('evt_meet');

    Http::assertSent(fn (Request $request) => $request->method() === 'GET'
        && str_contains($request->url(), 'calendar_id=cal_meet'));
    Http::assertNotSent(fn (Request $request) => $request->method() !== 'GET');
});

it('moves a meet to another day, keeping it to that one date', function () {
    $task = calendarSyncMeet();
    fakeCalendarEvent(calendarSyncEvent(chicagoTimespan('2026-09-25 09:00', '2026-09-25 09:30')));

    app(MeetTaskCalendarService::class)->syncFromCalendar($task);

    $task->refresh();

    expect($task->start_date->toDateString())->toBe('2026-09-25')
        ->and($task->end_date->toDateString())->toBe('2026-09-25')
        ->and($task->options->dates)->toBe(['2026-09-25'])
        ->and(array_keys((array) $task->options->time_settings))->toBe(['2026-09-25'])
        ->and($task->options->time_settings->{'2026-09-25'}->start_time)->toBe('09:00');
});

it('reads the day in the vendor timezone, not UTC', function () {
    $task = calendarSyncMeet();
    fakeCalendarEvent(calendarSyncEvent(chicagoTimespan('2026-09-23 19:30', '2026-09-23 20:00')));

    app(MeetTaskCalendarService::class)->syncFromCalendar($task);

    $task->refresh();

    expect($task->start_date->toDateString())->toBe('2026-09-23')
        ->and($task->options->time_settings->{'2026-09-23'}->start_time)->toBe('19:30')
        ->and($task->options->time_settings->{'2026-09-23'}->end_time)->toBe('20:00');
});

it('turns a meet made all-day on the calendar into an all-day task', function () {
    $task = calendarSyncMeet();
    fakeCalendarEvent(calendarSyncEvent(['object' => 'date', 'date' => '2026-09-24']));

    $move = app(MeetTaskCalendarService::class)->syncFromCalendar($task);

    $task->refresh();

    expect($move['to'])->toBe('Sep 24 (all day)')
        ->and($task->start_date->toDateString())->toBe('2026-09-24')
        ->and((array) $task->options->time_settings->{'2026-09-24'})->toBe(['use_time' => false]);
});

it('keeps a Hive edit that the calendar has not caught up with yet', function () {
    $task = calendarSyncMeet();
    fakeCalendarEvent(calendarSyncEvent(chicagoTimespan('2026-09-23 13:45', '2026-09-23 14:15'), [
        'updated_at' => now()->subHours(2)->timestamp,
    ]));

    expect(app(MeetTaskCalendarService::class)->syncFromCalendar($task))->toBeNull()
        ->and($task->fresh()->options->time_settings->{'2026-09-23'}->start_time)->toBe('14:00');
});

it('saves nothing when the calendar already matches', function () {
    $task = calendarSyncMeet();
    $savedAt = $task->updated_at->toDateTimeString();
    fakeCalendarEvent(calendarSyncEvent(chicagoTimespan('2026-09-23 14:00', '2026-09-23 14:30')));

    expect(app(MeetTaskCalendarService::class)->syncFromCalendar($task))->toBeNull()
        ->and($task->fresh()->updated_at->toDateTimeString())->toBe($savedAt);
});

it('leaves the task alone when its event was deleted', function () {
    $task = calendarSyncMeet();
    fakeCalendarEvent(['error' => ['type' => 'not_found_error']], 404);

    expect(app(MeetTaskCalendarService::class)->syncFromCalendar($task))->toBeNull()
        ->and($task->fresh()->options->time_settings->{'2026-09-23'}->start_time)->toBe('14:00');
});

it('leaves the task alone when its event was cancelled', function () {
    $task = calendarSyncMeet();
    fakeCalendarEvent(calendarSyncEvent(chicagoTimespan('2026-09-23 13:45', '2026-09-23 14:15'), ['status' => 'cancelled']));

    expect(app(MeetTaskCalendarService::class)->syncFromCalendar($task))->toBeNull()
        ->and($task->fresh()->options->time_settings->{'2026-09-23'}->start_time)->toBe('14:00');
});

it('reports the move without saving it on a dry run', function () {
    $task = calendarSyncMeet();
    fakeCalendarEvent(calendarSyncEvent(chicagoTimespan('2026-09-23 13:45', '2026-09-23 14:15')));

    expect(app(MeetTaskCalendarService::class)->syncFromCalendar($task, dryRun: true))
        ->toBe(['from' => 'Sep 23, 2:00 PM–2:30 PM', 'to' => 'Sep 23, 1:45 PM–2:15 PM'])
        ->and($task->fresh()->options->time_settings->{'2026-09-23'}->start_time)->toBe('14:00');
});

it('only syncs Meet tasks', function () {
    $task = calendarSyncMeet();
    $task->type = 'Task';
    Http::fake();

    expect(app(MeetTaskCalendarService::class)->syncFromCalendar($task))->toBeNull();

    Http::assertNothingSent();
});

it('sends the schedule-changed notification when a meet moved on the calendar is today', function () {
    $today = now()->toDateString();
    $task = calendarSyncMeet($today);
    fakeCalendarEvent(calendarSyncEvent(chicagoTimespan("{$today} 13:45", "{$today} 14:15")));

    app(MeetTaskCalendarService::class)->syncFromCalendar($task);

    Queue::assertPushed(SendRealtimeTaskNotification::class, fn (SendRealtimeTaskNotification $job) => $job->projectId === $task->project_id
        && $job->affectedUserIds === ['1']);
    expect($task->movedByCalendar)->toBeFalse();
});

it('sends no schedule-changed notification for a move on another day, like a Hive edit', function () {
    $date = now()->addDays(5)->toDateString();
    $task = calendarSyncMeet($date);
    fakeCalendarEvent(calendarSyncEvent(chicagoTimespan("{$date} 09:00", "{$date} 09:30")));

    app(MeetTaskCalendarService::class)->syncFromCalendar($task);

    expect($task->fresh()->options->time_settings->{$date}->start_time)->toBe('09:00');
    Queue::assertNotPushed(SendRealtimeTaskNotification::class);
});

it('still sends nothing for other saves made without a dashboard user', function () {
    $task = calendarSyncMeet(now()->toDateString());

    $task->update(['notes' => 'Bring the tile samples']);

    Queue::assertNotPushed(SendRealtimeTaskNotification::class);
});

it('moves the task when the queued re-read runs', function () {
    $task = calendarSyncMeet();
    fakeCalendarEvent(calendarSyncEvent(chicagoTimespan('2026-09-23 13:45', '2026-09-23 14:15')));

    (new SyncMeetTaskFromCalendar($task->id))->handle(app(MeetTaskCalendarService::class));

    expect($task->fresh()->options->time_settings->{'2026-09-23'}->start_time)->toBe('13:45');
});

it('queues a re-read of a Meet whose event changed on the calendar', function () {
    $task = calendarSyncMeet();

    signedCalendarWebhook(['type' => 'event.updated', 'data' => ['object' => [
        'id' => 'evt_meet',
        'grant_id' => 'grant_meet',
        'when' => chicagoTimespan('2026-09-23 13:45', '2026-09-23 14:15'),
    ]]])->assertSuccessful();

    Queue::assertPushed(SyncMeetTaskFromCalendar::class, fn (SyncMeetTaskFromCalendar $job) => $job->taskId === $task->id);
});

it('acknowledges but ignores calendar events that are not a Meet invite', function () {
    calendarSyncMeet();

    signedCalendarWebhook(['type' => 'event.updated', 'data' => ['object' => ['id' => 'evt_other', 'grant_id' => 'grant_meet']]])
        ->assertSuccessful()
        ->assertJson(['ignored' => 'not a meet event']);

    signedCalendarWebhook(['type' => 'event.updated', 'data' => ['object' => ['id' => 'evt_meet', 'grant_id' => 'grant_someone_else']]])
        ->assertSuccessful();

    Queue::assertNotPushed(SyncMeetTaskFromCalendar::class);
});

it('sweeps the upcoming meets, and a past one only when asked for by id', function () {
    $upcomingDate = now()->addDays(3)->toDateString();
    $pastDate = now()->subDays(10)->toDateString();
    $upcoming = calendarSyncMeet($upcomingDate, eventId: 'evt_upcoming');
    $past = calendarSyncMeet($pastDate, eventId: 'evt_past');

    Http::fake([
        'https://api.us.nylas.com/v3/grants/grant_meet/events/evt_upcoming*' => Http::response(
            calendarSyncEvent(chicagoTimespan("{$upcomingDate} 10:00", "{$upcomingDate} 10:30")),
        ),
        'https://api.us.nylas.com/v3/grants/grant_meet/events/evt_past*' => Http::response(
            calendarSyncEvent(chicagoTimespan("{$pastDate} 11:00", "{$pastDate} 11:30")),
        ),
    ]);

    $this->artisan('meet:sync-from-calendar')
        ->expectsOutputToContain("#{$upcoming->id} Patryk/Mark Consult")
        ->assertSuccessful();

    expect($upcoming->fresh()->options->time_settings->{$upcomingDate}->start_time)->toBe('10:00')
        ->and($past->fresh()->options->time_settings->{$pastDate}->start_time)->toBe('14:00');

    $this->artisan('meet:sync-from-calendar', ['--task' => [$past->id]])->assertSuccessful();

    expect($past->fresh()->options->time_settings->{$pastDate}->start_time)->toBe('11:00');
});

it('catches up a meet moved today without texting anyone', function () {
    $today = now()->toDateString();
    $task = calendarSyncMeet($today);
    fakeCalendarEvent(calendarSyncEvent(chicagoTimespan("{$today} 13:45", "{$today} 14:15")));

    $this->artisan('meet:sync-from-calendar', ['--task' => [$task->id]])->assertSuccessful();

    expect($task->fresh()->options->time_settings->{$today}->start_time)->toBe('13:45');
    Queue::assertNotPushed(SendRealtimeTaskNotification::class);
});

it('saves nothing when the sweep is a dry run', function () {
    $date = now()->addDays(3)->toDateString();
    $task = calendarSyncMeet($date);
    fakeCalendarEvent(calendarSyncEvent(chicagoTimespan("{$date} 10:00", "{$date} 10:30")));

    $this->artisan('meet:sync-from-calendar', ['--dry-run' => true])
        ->expectsOutputToContain('[dry-run]')
        ->assertSuccessful();

    expect($task->fresh()->options->time_settings->{$date}->start_time)->toBe('14:00');
});
