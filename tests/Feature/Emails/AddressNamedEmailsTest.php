<?php

use App\Jobs\SummarizeEmailMessage;
use App\Models\EmailMessage;
use App\Models\Project;
use App\Models\ProjectActionItem;
use App\Models\ProjectStatus;
use App\Models\Task;
use App\Services\ActionItems\ScheduledVisitApplier;
use App\Services\Emails\EmailStakeholders;
use App\Services\Emails\StakeholderEmailStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Support/t3-fixtures.php';
require_once __DIR__.'/../../Support/service-call-fixtures.php';

/*
 * Patryk, 2026-10-27: the Village of Northbrook's "3154 VIOLET LN -
 * PB26-0157 - Water Service - Confirmation of Scheduled Inspection" came
 * from NOREPLY@northbrook.il.us — nobody on the job — so it was never kept.
 * It "should have matched to project 364 and show under communications"
 * and "created an Inspection task with the date and time in the email".
 */

beforeEach(function () {
    config(['emails.stakeholder_sync.enabled' => true, 'scout.driver' => null]);
    Storage::fake('files');
    Queue::fake();
});

/** @return array<string, mixed> */
function violetWorld(): array
{
    $world = serviceCallWorld('active');
    $violet = sec3_makeProject($world['vendor'], $world['client'], ['project_name' => 'Home Renovation', 'address' => '3154 Violet Ln', 'city' => 'Northbrook', 'state' => 'IL', 'zip_code' => '60062']);
    ProjectStatus::create(['project_id' => $violet->id, 'belongs_to_vendor_id' => $world['vendor']->id, 'status_code' => 6, 'start_date' => now()->subMonths(5)->format('Y-m-d')]);

    return $world + ['violet' => Project::withoutGlobalScopes()->find($violet->id)];
}

function villageEmail(array $world, array $overrides = []): array
{
    return array_replace([
        'id' => 'msg-'.uniqid(),
        'thread_id' => 'thread-village-'.uniqid(),
        'date' => now()->subMinutes(5)->getTimestamp(),
        'subject' => 'VILLAGE OF NORTHBROOK - 3154 VIOLET LN - PB26-0157 - Water Service - Confirmation of Scheduled Inspection',
        'snippet' => 'An Inspection has been scheduled upon the subject permit.',
        'from' => [['name' => '', 'email' => 'NOREPLY@northbrook.il.us']],
        'to' => [['email' => $world['mailbox']]],
        'cc' => [],
        'body' => "<p>Village of Northbrook Development and Planning Services</p><p>Patryk Szady</p><p>An Inspection has been scheduled upon the subject permit.</p>"
            ."<p>Address: 3154 VIOLET LN<br>Permit: PB26-0157<br>Inspection: Water Service<br>Date of Inspection: 10/29/2026</p>"
            ."<p>***AFTERNOON INSPECTION***<br>The scheduled inspection will take place between 1:00PM and 4:00PM</p><p>DPS Permit Desk - 847/664-4050</p>",
        'headers' => [['name' => 'Message-ID', 'value' => '<village-'.uniqid().'@northbrook.il.us>']],
        'attachments' => [],
    ], $overrides);
}

it('keeps the village\'s inspection notice on the project its address names, though nobody on the job sent it', function () {
    $w = violetWorld();

    $row = app(StakeholderEmailStore::class)->store(villageEmail($w), 'grant-1', $w['mailbox']);

    expect($row)->not->toBeNull()
        ->and($row->project_id)->toBe($w['violet']->id)
        ->and($row->client_id)->toBe($w['client']->id)
        ->and($row->vendor_id)->toBeNull()
        ->and($row->user_id)->toBeNull()
        ->and($row->from_email)->toBe('noreply@northbrook.il.us')
        ->and($row->candidate_project_ids)->toBe([$w['violet']->id])
        ->and($row->body_text)->toContain('between 1:00PM and 4:00PM');
    Queue::assertPushed(SummarizeEmailMessage::class, fn ($job) => $job->emailMessageId === $row->id);

    // The project's communications list it.
    expect(EmailMessage::query()->forProject($w['violet'])->pluck('id')->all())->toContain($row->id);
});

it('finds the address in the body when the subject leaves it out, and keeps nothing that names no project', function () {
    $w = violetWorld();

    $bodyOnly = app(StakeholderEmailStore::class)->store(villageEmail($w, ['subject' => 'Confirmation of Scheduled Inspection - PB26-0157']), 'grant-1', $w['mailbox']);
    expect($bodyOnly?->project_id)->toBe($w['violet']->id);

    $stranger = app(StakeholderEmailStore::class)->store(villageEmail($w, [
        'subject' => 'Your Home Depot order has shipped',
        'body' => '<p>Order 4421 ships to 88 Elm St, Glenview. Track it online.</p>',
        'from' => [['name' => 'Home Depot', 'email' => 'orders@homedepot.com']],
    ]), 'grant-1', $w['mailbox']);
    expect($stranger)->toBeNull()->and(EmailMessage::query()->count())->toBe(1);
});

it('never files a stranger\'s email under a cancelled project, nor guesses between two jobs at the same house', function () {
    $w = violetWorld();

    // 415 Lincoln Ave holds an active job and a cancelled one: only the active one counts.
    $lincoln = app(StakeholderEmailStore::class)->store(villageEmail($w, ['subject' => 'Permit issued - 415 LINCOLN AVE', 'body' => '<p>Permit issued.</p>']), 'grant-1', $w['mailbox']);
    expect($lincoln?->project_id)->toBe($w['project']->id);

    // A second open job at 3154 Violet: the email is kept with both as candidates, on neither.
    $second = sec3_makeProject($w['vendor'], $w['client'], ['project_name' => 'Garage', 'address' => '3154 Violet Ln']);
    ProjectStatus::create(['project_id' => $second->id, 'belongs_to_vendor_id' => $w['vendor']->id, 'status_code' => 6, 'start_date' => now()->format('Y-m-d')]);
    $ambiguous = app(StakeholderEmailStore::class)->store(villageEmail($w), 'grant-1', $w['mailbox']);
    expect($ambiguous)->toBeNull('a stranger naming two jobs is not kept on a guess');
});

it('lets the address decide between a client\'s jobs when the client writes', function () {
    $w = violetWorld();

    $row = app(StakeholderEmailStore::class)->store(villageEmail($w, [
        'subject' => 'Water inspection at 3154 Violet',
        'from' => [['name' => 'Katie Love', 'email' => $w['katie']->email]],
        'body' => '<p>The village says Thursday afternoon.</p>',
    ]), 'grant-1', $w['mailbox']);

    expect($row->user_id)->toBe($w['katie']->id)
        ->and($row->project_id)->toBe($w['violet']->id)
        ->and($row->candidate_project_ids[0])->toBe($w['violet']->id);
});

it('matches a street however a sender writes it', function (string $address, string $text, bool $matches) {
    $pattern = EmailStakeholders::addressPattern($address);

    expect($pattern !== null && preg_match($pattern, mb_strtolower($text)) === 1)->toBe($matches);
})->with([
    'caps and suffix' => ['3154 Violet Ln', 'VILLAGE OF NORTHBROOK - 3154 VIOLET LN - PB26-0157', true],
    'suffix spelled out' => ['3154 Violet Ln', 'Walkthrough at 3154 Violet Lane', true],
    'no suffix' => ['3154 Violet Ln', 'Re: 3154 Violet draw', true],
    'other house on the street' => ['3154 Violet Ln', '3156 Violet Ln', false],
    'longer street word' => ['3154 Violet Ln', '3154 Violetta Ct', false],
    'direction written out' => ['400 N Wheeling Rd', '400 North Wheeling Road', true],
    'direction left out' => ['400 N Wheeling Rd', '400 Wheeling Rd', true],
    'unit after the street' => ['123 Villa Circle Drive, Unit 4', '123 Villa Circle Dr', true],
    'no house number' => ['Villa Circle Drive', '123 Villa Circle Dr', false],
]);

it('makes the inspection the village set into a task with its day and afternoon window', function () {
    $w = violetWorld();
    $date = now('America/Chicago')->addDays(2)->toDateString();
    // Earlier inspections on the job are dated and done: none of them is this one.
    Task::withoutEvents(fn () => Task::create(['project_id' => $w['violet']->id, 'belongs_to_vendor_id' => $w['vendor']->id, 'created_by_user_id' => $w['admin']->id, 'title' => 'Site Inspection', 'type' => 'Task', 'order' => 0, 'start_date' => now()->subMonths(3)->toDateString(), 'end_date' => now()->subMonths(3)->toDateString(), 'options' => ['dates' => [now()->subMonths(3)->toDateString()]]]));

    $item = ProjectActionItem::create(['fingerprint' => md5('Water Service Inspection'), 'project_id' => $w['violet']->id, 'title' => 'Water Service Inspection', 'owner' => 'Village of Northbrook', 'due_on' => $date, 'start_time' => '13:00', 'end_time' => '16:00', 'status' => ProjectActionItem::STATUS_OPEN, 'first_mentioned_at' => now(), 'last_mentioned_at' => now()]);

    $task = app(ScheduledVisitApplier::class)->apply($item, ['at' => Carbon::now(), 'source' => ['kind' => 'email', 'id' => 501, 'excerpt' => 'between 1:00PM and 4:00PM']]);

    expect($task)->not->toBeNull()
        ->and($task->title)->toBe('Water Service Inspection')
        ->and($task->type)->toBe('Task')
        ->and($task->start_date->toDateString())->toBe($date)
        ->and($task->belongs_to_vendor_id)->toBe($w['vendor']->id)
        ->and((array) data_get($task->options, "time_settings.$date"))->toBe(['use_time' => true, 'start_time' => '13:00', 'end_time' => '16:00'])
        ->and(data_get($task->options, 'scheduled_from.id'))->toBe(501)
        ->and($item->fresh()->status)->toBe(ProjectActionItem::STATUS_TASK)
        ->and($item->fresh()->task_id)->toBe($task->id)
        ->and(Task::query()->where('project_id', $w['violet']->id)->count())->toBe(2);

    // Read again later: the same task, not a second one.
    expect(app(ScheduledVisitApplier::class)->apply($item->fresh(), ['at' => Carbon::now(), 'source' => ['kind' => 'email', 'id' => 501]])?->id)->toBe($task->id)
        ->and(Task::query()->where('project_id', $w['violet']->id)->count())->toBe(2);
});

it('dates an undated inspection task the team already made instead of adding one, and leaves other visits for a person', function () {
    $w = violetWorld();
    $date = now('America/Chicago')->addDays(2)->toDateString();
    $planned = Task::withoutEvents(fn () => Task::create(['project_id' => $w['violet']->id, 'belongs_to_vendor_id' => $w['vendor']->id, 'created_by_user_id' => $w['admin']->id, 'title' => 'Water Service Inspection', 'type' => 'Task', 'order' => 0, 'options' => ['dates' => []]]));

    $inspection = ProjectActionItem::create(['fingerprint' => md5('Water Service Inspection'), 'project_id' => $w['violet']->id, 'title' => 'Water Service Inspection', 'due_on' => $date, 'start_time' => '13:00', 'end_time' => '16:00', 'status' => ProjectActionItem::STATUS_OPEN, 'first_mentioned_at' => now(), 'last_mentioned_at' => now()]);
    expect(app(ScheduledVisitApplier::class)->apply($inspection, ['at' => Carbon::now(), 'source' => ['kind' => 'email', 'id' => 7]])?->id)->toBe($planned->id);

    $delivery = ProjectActionItem::create(['fingerprint' => md5('Dumpster Delivery'), 'project_id' => $w['violet']->id, 'title' => 'Dumpster Delivery', 'due_on' => $date, 'status' => ProjectActionItem::STATUS_OPEN, 'first_mentioned_at' => now(), 'last_mentioned_at' => now()]);
    expect(app(ScheduledVisitApplier::class)->apply($delivery, ['at' => Carbon::now(), 'source' => ['kind' => 'email', 'id' => 8]]))->toBeNull()
        ->and(Task::query()->where('project_id', $w['violet']->id)->count())->toBe(1);
});
