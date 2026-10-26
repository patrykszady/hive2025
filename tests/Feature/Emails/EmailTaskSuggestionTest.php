<?php

use App\Livewire\Projects\ProjectCommunications;
use App\Livewire\Tasks\TaskCreate;
use App\Models\EmailMessage;
use App\Models\ProjectActionItem;
use App\Models\Task;
use App\Models\Vendor;
use App\Services\ActionItems\ProjectActionItemExtractor;
use App\Services\Sms\MessageTaskSuggestionBuilder;
use App\Services\SmsTaskExtractionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Support/t3-fixtures.php';
require_once __DIR__.'/../../Support/service-call-fixtures.php';

/*
 * Emails find tasks the way texts do (2026-10-26). Kama at Ultimate Stone
 * wrote on the Abbey thread: "Measurement is scheduled for Tuesday, 11/3",
 * then nine minutes later "Actually, the measurement is scheduled for
 * Thursday, 10/29th between 11-1pm" — and the project's undated "Counter
 * Measure" never got its day. Now the email has the same "Create Task" a
 * text has (SmsTaskExtractionService + MessageTaskSuggestionBuilder → the
 * task dialog), and the planner reads emails with the same pass as texts.
 */

beforeEach(function () {
    config(['services.openai.api_key' => 'test-key', 'scout.driver' => null]);
    $this->travelTo(Carbon::parse('2026-10-26 20:00:00'));
});

/** The Abbey world: the project's undated Counter Measure, and the counter template thread. */
function abbeyThread(?Vendor $vendor = null): array
{
    $world = serviceCallWorld('active', $vendor);
    test()->actingAs($world['admin']);

    $measure = Task::withoutEvents(fn () => Task::create([
        'title' => 'Counter Measure', 'project_id' => $world['project']->id, 'type' => 'Task', 'order' => 1,
        'belongs_to_vendor_id' => $world['vendor']->id, 'created_by_user_id' => $world['admin']->id, 'options' => ['dates' => [], 'time_settings' => []],
    ]));
    $install = Task::withoutEvents(fn () => Task::create([
        'title' => 'Cabinet Install', 'project_id' => $world['project']->id, 'type' => 'Task', 'order' => 2,
        'start_date' => '2026-10-26', 'end_date' => '2026-10-29',
        'belongs_to_vendor_id' => $world['vendor']->id, 'created_by_user_id' => $world['admin']->id, 'options' => ['dates' => ['2026-10-26', '2026-10-29'], 'time_settings' => []],
    ]));

    $email = fn (array $attributes) => EmailMessage::factory()->create(array_merge([
        'belongs_to_vendor_id' => $world['vendor']->id,
        'project_id' => $world['project']->id,
        'client_id' => $world['client']->id,
        'mailbox' => $world['mailbox'],
        'to' => [$world['mailbox']],
        'summarized_at' => now(),
    ], $attributes));

    $ask = $email(['from_email' => 'jenn@jpeterson-design.com', 'from_name' => 'Jennifer Peterson', 'subject' => 'Abbey counter template', 'sent_at' => '2026-10-26 19:19:24',
        'body_text' => "Hi Kama,\nWe are ready for the counter template at Abbey as early as Thursday. When is your first available?\n-- Jenn Peterson"]);
    $tuesday = $email(['from_email' => 'kama@ultimatestone.com', 'from_name' => 'Kama Dabrzalska', 'subject' => 'Re: Abbey counter template', 'sent_at' => '2026-10-26 19:32:38',
        'body_text' => "Hi Jennifer-\nMeasurement is scheduled for Tuesday, 11/3rd.\nThank you,\nKAMA DABRZALSKA\n\nFrom: Jennifer Peterson <jenn@jpeterson-design.com>\nSent: Monday, October 26, 2026 2:19 PM\nHi Kama, We are ready for the counter template"]);
    $thursday = $email(['from_email' => 'kama@ultimatestone.com', 'from_name' => 'Kama Dabrzalska', 'subject' => 'Re: Abbey counter template', 'sent_at' => '2026-10-26 19:41:58',
        'body_text' => "Hi-\nActually, the measurement is scheduled for Thursday, 10/29th between 11-1pm.\nThank you,\nKAMA DABRZALSKA\n\nFrom: Kama Dabrzalska <kama@ultimatestone.com>\nSent: Monday, October 26, 2026 2:32 PM\nHi Jennifer- Measurement is scheduled for Tuesday, 11/3rd."]);

    return $world + compact('measure', 'install', 'ask', 'tuesday', 'thursday');
}

/** What SmsTaskExtractionService's model answers for Kama's correction. */
function counterMeasureExtraction(array $overrides = []): void
{
    Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => json_encode(array_merge([
        'has_task' => true, 'title' => 'Counter Measure', 'type' => 'Task', 'meeting_type' => null,
        'date' => '2026-10-29', 'start_time' => '11:00', 'end_time' => '13:00', 'project_hint' => 'counter',
        'notes' => 'Actually, the measurement is scheduled for Thursday, 10/29th between 11-1pm.',
        'assignee_names' => [], 'checklist' => [], 'additional_tasks' => [],
    ], $overrides))]]]])]);
}

it('suggests the project\'s own undated Counter Measure for Kama\'s email, on Thursday 11 to 1', function () {
    $w = abbeyThread();
    counterMeasureExtraction();

    $result = app(MessageTaskSuggestionBuilder::class)->suggestForEmail($w['thursday'], app(SmsTaskExtractionService::class));

    expect($result['ok'])->toBeTrue()
        ->and($result['payload'])->toMatchArray([
            'task_id' => $w['measure']->id,
            'title' => 'Counter Measure',
            'project_id' => $w['project']->id,
            'client_id' => $w['client']->id,
            'vendor_id' => null,
            'date' => '2026-10-29',
            'start_time' => '11:00',
            'end_time' => '13:00',
            'email_message_id' => $w['thursday']->id,
        ]);

    // The same extractor texts use, told the project's tasks and shown the
    // earlier emails of the thread (not the quoted history twice).
    Http::assertSent(function ($request) {
        $content = (string) data_get($request->data(), 'messages.1.content');

        return data_get($request->data(), 'response_format.json_schema.name') === 'sms_task'
            && str_contains($content, "Tasks already on this project's schedule: ")
            && str_contains($content, 'Counter Measure')
            && str_contains($content, 'Jennifer Peterson: Hi Kama,')
            && str_contains($content, 'Kama Dabrzalska: Hi Jennifer-')
            && str_contains($content, 'Message from Kama Dabrzalska')
            && str_contains($content, 'Actually, the measurement is scheduled for Thursday');
    });
});

it('opens the task dialog from the email on the project page, filled in like a text', function () {
    $w = abbeyThread();
    counterMeasureExtraction();

    Livewire::test(ProjectCommunications::class, ['project' => $w['project']])
        ->call('selectEmail', $w['thursday']->id)
        ->assertSeeHtml('data-create-task-from-email')
        ->call('createTaskFromEmail', $w['thursday']->id)
        ->assertSet('showEmailModal', false)
        ->assertDispatched('prefillTaskFromSms', fn ($name, $params) => ($params['payload']['task_id'] ?? null) === $w['measure']->id
            && ($params['payload']['date'] ?? null) === '2026-10-29');
});

it('dates the undated Counter Measure in the task dialog instead of adding a second one', function () {
    $w = abbeyThread();
    counterMeasureExtraction();
    $payload = app(MessageTaskSuggestionBuilder::class)->suggestForEmail($w['thursday'], app(SmsTaskExtractionService::class))['payload'];

    Livewire::test(TaskCreate::class)
        ->call('prefillTaskFromSms', $payload)
        ->assertSet('form.task_id', $w['measure']->id)
        ->assertSet('form.title', 'Counter Measure')
        ->assertSet('form.dates', ['2026-10-29'])
        ->assertSet('form.time_settings.2026-10-29.use_time', true)
        ->assertSet('form.time_settings.2026-10-29.start_time', '11:00')
        ->assertSet('form.time_settings.2026-10-29.end_time', '13:00');

    // Same for a title the extractor words differently, as long as the day matches or the task waits for one.
    $payload['task_id'] = null;
    Livewire::test(TaskCreate::class)
        ->call('prefillTaskFromSms', $payload)
        ->assertSet('form.task_id', $w['measure']->id);
});

it('says so when an email is on no project, or holds no task', function () {
    $w = abbeyThread();
    $unplaced = EmailMessage::factory()->create(['belongs_to_vendor_id' => $w['vendor']->id, 'project_id' => null, 'body_text' => 'Measurement is Thursday.', 'sent_at' => now()]);

    expect(app(MessageTaskSuggestionBuilder::class)->suggestForEmail($unplaced, app(SmsTaskExtractionService::class))['reason'])->toBe('no_project');

    counterMeasureExtraction(['has_task' => false, 'title' => '']);
    expect(app(MessageTaskSuggestionBuilder::class)->suggestForEmail($w['ask'], app(SmsTaskExtractionService::class))['reason'])->toBe('no_task');
});

it('gives the app the same suggestion through the controller texts use, and only for the viewer\'s own email', function () {
    $vendor = Vendor::query()->find(1) ?? Vendor::factory()->create(['id' => 1, 'business_name' => 'GS Construction']);
    $w = abbeyThread($vendor);
    counterMeasureExtraction();
    Sanctum::actingAs($w['admin'], ['app']);

    $this->postJson("/api/app/v1/emails/{$w['thursday']->id}/task-suggestion")
        ->assertOk()
        ->assertJsonPath('data.task_id', $w['measure']->id)
        ->assertJsonPath('data.start_time', '11:00');

    $theirs = EmailMessage::factory()->create(['belongs_to_vendor_id' => Vendor::factory()->create()->id]);
    $this->postJson("/api/app/v1/emails/{$theirs->id}/task-suggestion")->assertForbidden();
    $this->postJson('/api/app/v1/emails/999999/task-suggestion')->assertNotFound();
});

it('puts the measure on the planner from the emails alone, and Kama\'s correction moves it to Thursday 11 to 1', function () {
    $w = abbeyThread();
    // The first pass sees Jenn's ask and Kama's Tuesday; the correction comes in after.
    $w['thursday']->forceFill(['summarized_at' => null])->save();

    $prompts = [];
    Http::fake([
        'api.openai.com/v1/embeddings' => Http::response(['data' => []]),
        'api.openai.com/v1/chat/completions' => function ($request) use (&$prompts, $w) {
            $name = data_get($request->data(), 'response_format.json_schema.name');
            if ($name !== 'thread_action_items') {
                return Http::response(['choices' => [['message' => ['content' => json_encode($name === 'action_item_closures' ? ['decisions' => []] : ['groups' => []])]]]]);
            }
            $prompt = (string) data_get($request->data(), 'messages.1.content');
            $prompts[] = ['system' => (string) data_get($request->data(), 'messages.0.content'), 'user' => $prompt];
            $items = str_contains($prompt, '#'.$w['thursday']->id.' [')
                ? [['title' => 'Counter Measure', 'owner' => 'Kama', 'due_date' => '2026-10-29', 'start_time' => '11:00', 'end_time' => '13:00', 'kind' => 'todo', 'message_ids' => [$w['thursday']->id]]]
                : [['title' => 'Counter Measure', 'owner' => 'Kama', 'due_date' => '2026-11-03', 'start_time' => null, 'end_time' => null, 'kind' => 'todo', 'message_ids' => [$w['tuesday']->id]]];

            return Http::response(['choices' => [['message' => ['content' => json_encode(['items' => $items])]]]]);
        },
    ]);

    app(ProjectActionItemExtractor::class)->run($w['project']);

    $item = ProjectActionItem::where('project_id', $w['project']->id)->sole();
    expect($item->title)->toBe('Counter Measure')
        ->and($item->due_on->toDateString())->toBe('2026-11-03')
        ->and($item->start_time)->toBeNull()
        ->and($item->sources[0])->toMatchArray(['kind' => 'email', 'id' => $w['tuesday']->id, 'subject' => 'Re: Abbey counter template']);

    // The same reading pass as texts, told it is email, the project's tasks, and that a set visit counts.
    expect($prompts[0]['system'])->toContain('emails between a remodeling company')
        ->toContain("Tasks already on this project's schedule: ")
        ->toContain('Counter Measure')
        ->toContain('Measurement is scheduled for Thursday, 10/29 between 11-1pm')
        ->and($prompts[0]['user'])->toContain('Kama Dabrzalska: Subject: Re: Abbey counter template — Hi Jennifer- Measurement is scheduled for Tuesday, 11/3rd.')
        ->not->toContain('From: Jennifer Peterson');

    $w['thursday']->forceFill(['summarized_at' => now()])->save();
    app(ProjectActionItemExtractor::class)->run($w['project']);

    $item = ProjectActionItem::where('project_id', $w['project']->id)->sole();
    expect($item->due_on->toDateString())->toBe('2026-10-29')
        ->and($item->start_time)->toBe('11:00')
        ->and($item->end_time)->toBe('13:00')
        ->and($item->mentions)->toBe(2);
});

it('keeps the newer date when an older mention is read later', function () {
    $w = abbeyThread();
    $item = ProjectActionItem::create([
        'project_id' => $w['project']->id, 'title' => 'Counter Measure', 'owner' => 'Kama', 'due_on' => '2026-10-29', 'start_time' => '11:00', 'end_time' => '13:00',
        'status' => ProjectActionItem::STATUS_OPEN, 'mentions' => 1, 'first_mentioned_at' => '2026-10-26 19:41:58', 'last_mentioned_at' => '2026-10-26 19:41:58',
        'sources' => [['kind' => 'email', 'id' => $w['thursday']->id, 'excerpt' => 'Thursday']], 'fingerprint' => ProjectActionItem::fingerprintFor('Counter Measure'),
    ]);

    $item->absorb(['owner' => 'Kama', 'due_on' => '2026-11-03', 'start_time' => null, 'end_time' => null, 'at' => Carbon::parse('2026-10-26 19:32:38'), 'source' => ['kind' => 'email', 'id' => $w['tuesday']->id, 'excerpt' => 'Tuesday']]);

    expect($item->fresh()->due_on->toDateString())->toBe('2026-10-29')
        ->and($item->fresh()->start_time)->toBe('11:00')
        ->and($item->fresh()->mentions)->toBe(2);
});

it('updates the undated Counter Measure even when the email words it differently', function () {
    $w = abbeyThread();
    counterMeasureExtraction(['title' => 'Countertop Template Measurement']);

    $payload = app(MessageTaskSuggestionBuilder::class)->suggestForEmail($w['thursday'], app(SmsTaskExtractionService::class))['payload'];

    expect($payload['task_id'])->toBe($w['measure']->id);

    // Never a guess between two candidates.
    Task::withoutEvents(fn () => Task::create(['title' => 'Countertop Measure', 'project_id' => $w['project']->id, 'type' => 'Task', 'order' => 3,
        'belongs_to_vendor_id' => $w['vendor']->id, 'created_by_user_id' => $w['admin']->id, 'options' => ['dates' => [], 'time_settings' => []]]));
    expect(app(MessageTaskSuggestionBuilder::class)->matchExistingTask($w['project']->id, 'Countertop Template Measurement', '2026-10-29'))->toBeNull()
        ->and(app(MessageTaskSuggestionBuilder::class)->matchExistingTask($w['project']->id, 'Counter Measure', '2026-10-29')?->id)->toBe($w['measure']->id);
});

it('opens the planner item as the project\'s own Counter Measure, with its day and time', function () {
    $w = abbeyThread();
    $item = ProjectActionItem::create([
        'project_id' => $w['project']->id, 'title' => 'Countertop template measure', 'owner' => 'Kama', 'due_on' => '2026-10-29', 'start_time' => '11:00', 'end_time' => '13:00',
        'status' => ProjectActionItem::STATUS_OPEN, 'mentions' => 1, 'first_mentioned_at' => now(), 'last_mentioned_at' => now(),
        'sources' => [['kind' => 'email', 'id' => $w['thursday']->id, 'excerpt' => 'Actually, the measurement is scheduled for Thursday, 10/29th between 11-1pm.', 'subject' => 'Re: Abbey counter template']],
        'fingerprint' => ProjectActionItem::fingerprintFor('Countertop template measure'),
    ]);

    // CardsIndex with its projects stubbed: the real query needs MySQL's JSON_OVERLAPS.
    $planner = new class extends \App\Livewire\Planner\CardsIndex
    {
        public $stubProjects;

        #[\Livewire\Attributes\Computed]
        public function activeProjects()
        {
            return $this->stubProjects;
        }
    };
    $planner->stubProjects = collect([$w['project']]);

    $planner->actionItemToTask($item->id);

    $payload = collect(\Livewire\store($planner)->get('dispatched', []))
        ->map(fn ($event) => $event->serialize())
        ->firstWhere('name', 'prefillTaskFromSms')['params']['payload'] ?? null;

    expect($payload)->toMatchArray([
        'task_id' => $w['measure']->id,
        'title' => 'Counter Measure',
        'date' => '2026-10-29',
        'start_time' => '11:00',
        'end_time' => '13:00',
        'action_item_id' => $item->id,
    ]);
});


/*
 * Patryk, 2026-10-26: the Kama email "should have added times and date to
 * the Counter Measure task already on the project". A visit the planner's
 * reading pass finds (kind "visit") dates the project's own task.
 */

/**
 * The reading pass answers $items for the emails on the request; the review
 * pass answers $closures (default: try to close every item it is shown, the
 * way it closed Kama's measure as "scheduled").
 *
 * @param  callable(list<int>): list<array<string, mixed>>  $items
 */
function fakeVisitReading(callable $items, ?callable $closures = null, array &$reviews = []): void
{
    Http::fake([
        'api.openai.com/v1/embeddings' => Http::response(['data' => []]),
        'api.openai.com/v1/chat/completions' => function ($request) use ($items, $closures, &$reviews) {
            $name = data_get($request->data(), 'response_format.json_schema.name');
            $user = (string) data_get($request->data(), 'messages.1.content');
            if ($name === 'action_item_closures') {
                $reviews[] = ['system' => (string) data_get($request->data(), 'messages.0.content'), 'user' => $user];
                preg_match_all('/^#(\d+) \|/m', $user, $ids);
                $decisions = $closures ? $closures(array_map('intval', $ids[1])) : array_map(fn ($id) => ['id' => (int) $id, 'action' => 'done', 'reason' => '[email 2026-10-26] measurement is scheduled'], $ids[1]);

                return Http::response(['choices' => [['message' => ['content' => json_encode(['decisions' => $decisions])]]]]);
            }
            if ($name === 'action_item_merges') {
                return Http::response(['choices' => [['message' => ['content' => json_encode(['groups' => []])]]]]);
            }
            preg_match_all('/^#(\d+) /m', $user, $ids);

            return Http::response(['choices' => [['message' => ['content' => json_encode(['items' => $items(array_map('intval', $ids[1]))])]]]]);
        },
    ]);
}

function visitItem(string $title, string $date, ?string $start, ?string $end, array $ids): array
{
    return ['title' => $title, 'owner' => 'Ultimate Stone', 'due_date' => $date, 'start_time' => $start, 'end_time' => $end, 'kind' => 'visit', 'message_ids' => $ids];
}

it('puts Kama\'s measurement straight onto the undated Counter Measure, with its time, and texts nobody', function () {
    $w = abbeyThread();
    \Illuminate\Support\Facades\Queue::fake();
    fakeVisitReading(fn (array $ids) => in_array($w['thursday']->id, $ids, true)
        ? [visitItem('Counter Measure', '2026-10-29', '11:00', '13:00', [$w['thursday']->id])]
        : []);

    $stats = app(ProjectActionItemExtractor::class)->run($w['project']);

    $task = $w['measure']->fresh();
    expect($stats['scheduled'])->toBe(1)
        ->and($task->start_date->toDateString())->toBe('2026-10-29')
        ->and($task->end_date->toDateString())->toBe('2026-10-29')
        ->and((array) $task->options->dates)->toBe(['2026-10-29'])
        ->and((array) $task->options->time_settings->{'2026-10-29'})->toBe(['use_time' => true, 'start_time' => '11:00', 'end_time' => '13:00'])
        ->and($task->options->scheduled_from->kind)->toBe('email')
        ->and($task->options->scheduled_from->id)->toBe($w['thursday']->id)
        ->and($task->getArrivalTimeLabel('2026-10-29'))->not->toBeNull();

    // The item became that task, so the review pass never sees it to close it as "scheduled".
    $item = ProjectActionItem::where('project_id', $w['project']->id)->sole();
    expect($item->status)->toBe(ProjectActionItem::STATUS_TASK)
        ->and($item->task_id)->toBe($w['measure']->id)
        ->and(Task::where('project_id', $w['project']->id)->count())->toBe(2, 'no second Counter Measure');

    // No vendor availability text, no service-call text to the homeowner; the change is in the task's history.
    \Illuminate\Support\Facades\Queue::assertNothingPushed();
    expect(\Spatie\Activitylog\Models\Activity::where('subject_type', Task::class)->where('subject_id', $w['measure']->id)->latest('id')->value('description'))->toBe('Scheduled from an email');
});

it('moves the Counter Measure when Kama corrects the day, from Tuesday to Thursday 11 to 1', function () {
    $w = abbeyThread();
    $w['thursday']->forceFill(['summarized_at' => null])->save();
    fakeVisitReading(fn (array $ids) => array_values(array_filter([
        in_array($w['tuesday']->id, $ids, true) ? visitItem('Counter Measure', '2026-11-03', null, null, [$w['tuesday']->id]) : null,
        in_array($w['thursday']->id, $ids, true) ? visitItem('Counter Measure', '2026-10-29', '11:00', '13:00', [$w['thursday']->id]) : null,
    ])));

    app(ProjectActionItemExtractor::class)->run($w['project']);
    expect($w['measure']->fresh()->start_date->toDateString())->toBe('2026-11-03')
        ->and((array) $w['measure']->fresh()->options->time_settings->{'2026-11-03'})->toMatchArray(['use_time' => false]);

    $w['thursday']->forceFill(['summarized_at' => now()])->save();
    $stats = app(ProjectActionItemExtractor::class)->run($w['project']);

    $task = $w['measure']->fresh();
    expect($stats['scheduled'])->toBe(1)
        ->and($task->start_date->toDateString())->toBe('2026-10-29')
        ->and((array) $task->options->dates)->toBe(['2026-10-29'])
        ->and((array) $task->options->time_settings)->toHaveKey('2026-10-29')
        ->and((array) $task->options->time_settings)->not->toHaveKey('2026-11-03')
        ->and($task->options->scheduled_from->id)->toBe($w['thursday']->id)
        ->and(ProjectActionItem::where('project_id', $w['project']->id)->count())->toBe(1);
});

it('never moves a day the team set by hand, and leaves the visit on the planner instead', function () {
    $w = abbeyThread();
    $w['measure']->forceFill(['start_date' => '2026-10-30', 'end_date' => '2026-10-30', 'options' => ['dates' => ['2026-10-30'], 'time_settings' => ['2026-10-30' => ['use_time' => true, 'start_time' => '08:00', 'end_time' => '09:00']]]])->saveQuietly();
    fakeVisitReading(fn (array $ids) => in_array($w['thursday']->id, $ids, true)
        ? [visitItem('Counter Measure', '2026-10-29', '11:00', '13:00', [$w['thursday']->id])]
        : [], fn (array $ids) => array_map(fn ($id) => ['id' => $id, 'action' => 'keep', 'reason' => ''], $ids));

    $stats = app(ProjectActionItemExtractor::class)->run($w['project']);

    expect($stats['scheduled'])->toBe(0)
        ->and($w['measure']->fresh()->start_date->toDateString())->toBe('2026-10-30')
        ->and(ProjectActionItem::where('project_id', $w['project']->id)->sole()->status)->toBe(ProjectActionItem::STATUS_OPEN);
});

it('fills in the time on a task the team already put on that day, without touching a time they set', function () {
    $w = abbeyThread();
    $w['measure']->forceFill(['start_date' => '2026-10-29', 'end_date' => '2026-10-29', 'options' => ['dates' => ['2026-10-29'], 'time_settings' => []]])->saveQuietly();
    fakeVisitReading(fn (array $ids) => in_array($w['thursday']->id, $ids, true)
        ? [visitItem('Counter Measure', '2026-10-29', '11:00', '13:00', [$w['thursday']->id])]
        : []);

    app(ProjectActionItemExtractor::class)->run($w['project']);

    expect((array) $w['measure']->fresh()->options->time_settings->{'2026-10-29'})->toBe(['use_time' => true, 'start_time' => '11:00', 'end_time' => '13:00']);
});

it('leaves a visit on a day already past alone', function () {
    $w = abbeyThread();
    $this->travelTo(Carbon::parse('2026-10-30 15:00:00'));
    fakeVisitReading(fn (array $ids) => in_array($w['thursday']->id, $ids, true)
        ? [visitItem('Counter Measure', '2026-10-29', '11:00', '13:00', [$w['thursday']->id])]
        : [], fn (array $ids) => array_map(fn ($id) => ['id' => $id, 'action' => 'keep', 'reason' => ''], $ids));

    app(ProjectActionItemExtractor::class)->run($w['project']);

    expect($w['measure']->fresh()->start_date)->toBeNull();
});

it('shows the review pass a visit as a visit, so being scheduled never closes it', function () {
    $w = abbeyThread();
    $reviews = [];
    fakeVisitReading(fn (array $ids) => in_array($w['thursday']->id, $ids, true)
        ? [visitItem('Backsplash Template', '2026-10-29', '11:00', '13:00', [$w['thursday']->id])]
        : [], fn (array $ids) => array_map(fn ($id) => ['id' => $id, 'action' => 'keep', 'reason' => ''], $ids), $reviews);

    app(ProjectActionItemExtractor::class)->run($w['project']);

    expect($reviews)->not->toBeEmpty()
        ->and($reviews[0]['user'])->toContain('| Backsplash Template | VISIT on 2026-10-29 11:00-13:00 |')
        ->and($reviews[0]['system'])->toContain('A VISIT item is a measurement, delivery, inspection or appointment on its date')
        ->and(ProjectActionItem::where('project_id', $w['project']->id)->sole()->status)->toBe(ProjectActionItem::STATUS_OPEN);
});

it('re-reads a project\'s emails from the console without losing its list', function () {
    $w = abbeyThread();
    \App\Models\ProjectActionItemScan::record($w['project']->id, \App\Models\ProjectActionItemScan::KIND_EMAILS, 0, $w['thursday']->id);
    fakeVisitReading(fn (array $ids) => in_array($w['thursday']->id, $ids, true)
        ? [visitItem('Counter Measure', '2026-10-29', '11:00', '13:00', [$w['thursday']->id])]
        : []);

    $this->artisan('project:action-items', ['project' => $w['project']->id])->assertSuccessful();
    expect($w['measure']->fresh()->start_date)->toBeNull('nothing new past the bookmark');

    $this->artisan('project:action-items', ['project' => $w['project']->id, '--reread-emails' => true])
        ->expectsOutputToContain('1 visits put on their tasks')
        ->assertSuccessful();
    expect($w['measure']->fresh()->start_date->toDateString())->toBe('2026-10-29');
});
