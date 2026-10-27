<?php

use App\Console\Commands\Meets\CancelOrphanedMeetInvites;
use App\Jobs\DeleteMeetTaskCalendarEvent;
use App\Models\Task;
use App\Services\MeetTaskCalendarService;
use App\Services\Tasks\TaskWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Support/t3-fixtures.php';

/*
 * Task 1216 (2026-10-26): the 10 am "Connelly/GS Consult" was taken off the
 * calendar in Hive by removing its date, but every save path only touched
 * the calendar event while the task still had a date — so Carla's invite
 * stayed. Unscheduling a Meet, or turning it into a plain task, cancels the
 * invite now, from whichever screen does it, and the leftover ones are
 * cleaned up by meets:cancel-orphaned-invites.
 */

const CARLA_EVENT = ['event_id' => 'evt-1216', 'grant_id' => 'grant-1', 'calendar_id' => 'cal-1', 'organizer_email' => 'patryk@gs.construction'];

/** @return array{task: Task, project: \App\Models\Project} */
function bookedConsult(): array
{
    $vendor = sec3_makeVendor('GS');
    $admin = sec3_makeAdmin($vendor);
    test()->actingAs($admin);
    $project = sec3_makeProject($vendor, sec3_makeClient($vendor));

    $task = Task::create([
        'title' => 'Connelly/GS Consult',
        'type' => 'Meet',
        'project_id' => $project->id,
        'start_date' => '2026-10-27',
        'end_date' => '2026-10-27',
        'options' => [
            'dates' => ['2026-10-27'],
            'time_settings' => ['2026-10-27' => ['use_time' => true, 'start_time' => '10:00', 'end_time' => '10:30']],
            'meeting_participants' => ['patryk@gs.construction', 'carconn2@yahoo.com'],
            'meeting_location_type' => 'in_person',
            'nylas_meet_event' => CARLA_EVENT,
        ],
    ]);

    return ['task' => Task::find($task->id), 'project' => $project];
}

it('cancels the calendar invite when a Meet loses its date, and forgets the event so a re-booking sends a fresh one', function () {
    Queue::fake();
    ['task' => $task, 'project' => $project] = bookedConsult();

    app(TaskWriter::class)->update($task, [
        'title' => 'Connelly/GS Consult',
        'project_id' => $project->id,
        'dates' => [],
        'time_settings' => ['2026-10-27' => ['use_time' => true, 'start_time' => '10:00', 'end_time' => '10:30']],
        'type' => 'Meet',
        'meeting_location_type' => 'in_person',
        'meeting_participants' => ['patryk@gs.construction', 'carconn2@yahoo.com'],
    ]);

    Queue::assertPushed(DeleteMeetTaskCalendarEvent::class, fn (DeleteMeetTaskCalendarEvent $job) => $job->taskId === $task->id && $job->eventMeta === CARLA_EVENT);

    $saved = Task::find($task->id);
    expect($saved->start_date)->toBeNull()
        ->and(data_get($saved->options, 'nylas_meet_event'))->toBeNull()
        ->and(data_get($saved->options, 'meeting_participants'))->toBe(['patryk@gs.construction', 'carconn2@yahoo.com']);
});

it('cancels the invite when a Meet is turned into a plain task', function () {
    Queue::fake();
    ['task' => $task] = bookedConsult();

    $task->update(['type' => 'Task']);

    Queue::assertPushed(DeleteMeetTaskCalendarEvent::class, fn (DeleteMeetTaskCalendarEvent $job) => $job->taskId === $task->id);
    expect(data_get(Task::find($task->id)->options, 'nylas_meet_event'))->toBeNull();
});

it('leaves the invite alone when a booked Meet merely changes its title or moves to another day', function () {
    Queue::fake();
    ['task' => $task] = bookedConsult();

    $task->update(['title' => 'Connelly/GS Consult (kitchen)']);
    $task->update(['start_date' => '2026-10-28', 'end_date' => '2026-10-28', 'options' => array_merge((array) json_decode(json_encode($task->options), true), ['dates' => ['2026-10-28']])]);

    Queue::assertNotPushed(DeleteMeetTaskCalendarEvent::class);
    expect((array) data_get(Task::find($task->id)->options, 'nylas_meet_event'))->toBe(CARLA_EVENT);
});

it('cleans up invites left behind before the fix — unscheduled, converted or deleted Meets — and leaves past meetings alone', function () {
    ['task' => $orphan, 'project' => $project] = bookedConsult();
    // The way task 1216 sat on prod: no date, event still on the task, its times set for tomorrow.
    $tomorrow = now()->addDay()->toDateString();
    Task::whereKey($orphan->id)->update(['start_date' => null, 'end_date' => null, 'options' => json_encode(array_merge((array) json_decode(json_encode($orphan->options), true), ['time_settings' => [$tomorrow => ['use_time' => true, 'start_time' => '10:00', 'end_time' => '10:30']]]))]);

    // Deleted months ago, with its event still noted on the row: nobody needs a cancellation for that now.
    $past = Task::create([
        'title' => 'Contract Review', 'type' => 'Meet', 'project_id' => $project->id, 'start_date' => '2026-04-24', 'end_date' => '2026-04-24',
        'options' => ['dates' => ['2026-04-24'], 'nylas_meet_event' => ['event_id' => 'evt-past', 'grant_id' => 'grant-1', 'calendar_id' => 'cal-1']],
    ]);
    Task::whereKey($past->id)->update(['deleted_at' => '2026-04-24 12:00:00']);

    $booked = Task::create([
        'title' => 'Still on', 'type' => 'Meet', 'project_id' => $project->id, 'start_date' => '2026-10-29', 'end_date' => '2026-10-29',
        'options' => ['dates' => ['2026-10-29'], 'nylas_meet_event' => ['event_id' => 'evt-keep', 'grant_id' => 'grant-1', 'calendar_id' => 'cal-1']],
    ]);

    $calendar = Mockery::mock(MeetTaskCalendarService::class);
    $calendar->shouldReceive('deleteMeetEventByMeta')->once()->with($orphan->id, CARLA_EVENT);
    app()->instance(MeetTaskCalendarService::class, $calendar);

    $this->artisan(CancelOrphanedMeetInvites::class, ['--dry-run' => true])
        ->expectsOutputToContain('Would cancel task '.$orphan->id)
        ->expectsOutputToContain('Leaving task '.$past->id)
        ->assertSuccessful();
    expect((array) data_get(Task::find($orphan->id)->options, 'nylas_meet_event'))->toBe(CARLA_EVENT);

    $this->artisan(CancelOrphanedMeetInvites::class)
        ->expectsOutputToContain('Cancelling task '.$orphan->id)
        ->expectsOutputToContain('Cancelled 1 invite(s).')
        ->assertSuccessful();

    expect(data_get(Task::find($orphan->id)->options, 'nylas_meet_event'))->toBeNull()
        ->and(data_get(Task::withTrashed()->find($past->id)->options, 'nylas_meet_event.event_id'))->toBe('evt-past')
        ->and(data_get(Task::find($booked->id)->options, 'nylas_meet_event.event_id'))->toBe('evt-keep');

    // --all reaches the past one too.
    $calendar->shouldReceive('deleteMeetEventByMeta')->once()->with($past->id, Mockery::on(fn ($m) => $m['event_id'] === 'evt-past'));
    $this->artisan(CancelOrphanedMeetInvites::class, ['--all' => true])->expectsOutputToContain('Cancelled 1 invite(s).')->assertSuccessful();
    expect(data_get(Task::withTrashed()->find($past->id)->options, 'nylas_meet_event'))->toBeNull();
});
