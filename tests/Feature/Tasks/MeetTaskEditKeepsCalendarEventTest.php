<?php

use App\Models\Task;
use App\Services\Tasks\TaskWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Support/t3-fixtures.php';

/*
 * Task 1209 (2026-10-23): a Meet's note was edited a minute after it was
 * booked; the save rebuilt the task's options from the form alone, dropped
 * the calendar event id the booking job had just written, and the invite
 * kept the old note ("Cannot update Meet event: missing metadata").
 */

it('keeps a Meet\'s calendar event, and anything else the form does not manage, across an edit', function () {
    Queue::fake();
    $vendor = sec3_makeVendor('GS');
    $admin = sec3_makeAdmin($vendor);
    $this->actingAs($admin);
    $project = sec3_makeProject($vendor, sec3_makeClient($vendor));

    $task = Task::create([
        'title' => 'Meet',
        'type' => 'Meet',
        'project_id' => $project->id,
        'start_date' => '2026-10-26',
        'end_date' => '2026-10-26',
        'notes' => '12-PM = Arrival Time. Not length of Meet.',
        'options' => ['dates' => ['2026-10-26'], 'time_settings' => [], 'meeting_participants' => ['patryk@gs.construction']],
    ]);

    // The edit form loads the task before the booking job saves the event.
    $openInForm = Task::find($task->id);
    $event = ['event_id' => 'evt-1209', 'grant_id' => 'grant-1', 'calendar_id' => 'cal-1', 'organizer_email' => 'patryk@gs.construction'];
    Task::whereKey($task->id)->update(['options' => json_encode([...(array) json_decode(json_encode($task->options), true), 'nylas_meet_event' => $event, 'source_sms_message_id' => 77])]);

    app(TaskWriter::class)->update($openInForm, [
        'title' => 'Meet',
        'project_id' => $project->id,
        'dates' => ['2026-10-26'],
        'time_settings' => ['2026-10-26' => ['use_time' => true, 'start_time' => '12:00', 'end_time' => '13:00']],
        'type' => 'Meet',
        'notes' => '12-1PM = Arrival Time. Not length of Meet.',
        'meeting_location_type' => 'in_person',
        'meeting_participants' => ['patryk@gs.construction', 'greg@gs.construction'],
    ]);

    $saved = Task::find($task->id);

    expect($saved->notes)->toBe('12-1PM = Arrival Time. Not length of Meet.')
        ->and((array) data_get($saved->options, 'nylas_meet_event'))->toBe($event)
        ->and(data_get($saved->options, 'source_sms_message_id'))->toBe(77)
        // What the form does manage is the form's.
        ->and(data_get($saved->options, 'meeting_participants'))->toBe(['patryk@gs.construction', 'greg@gs.construction'])
        ->and((array) data_get($saved->options, 'time_settings.2026-10-26'))->toBe(['use_time' => true, 'start_time' => '12:00', 'end_time' => '13:00']);
});
