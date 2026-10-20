<?php

/**
 * A known client's email about a problem on finished work opens a service
 * call: project → Service Call, a pending task, the schedule-link email and
 * text queued, the office alerted — once per email, and never for new work,
 * strangers, or projects that aren't finished.
 */

use App\Jobs\SendPendingTaskReminderToClients;
use App\Jobs\SendServiceCallScheduleEmail;
use App\Jobs\TextServiceCallScheduleLink;
use App\Mail\PendingTasksReminderEmail;
use App\Models\AppNotification;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\ServiceCallEmail;
use App\Models\Task;
use App\Services\ServiceCalls\ServiceCallEmailIntake;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Support/t3-fixtures.php';
require_once __DIR__.'/../../Support/service-call-fixtures.php';

beforeEach(function () {
    config([
        'service_calls.email_intake.enabled' => true,
        'services.openai.api_key' => 'test-key',
        'scout.driver' => null,
    ]);
    Queue::fake();
});

it('opens a service call from a client\'s email about finished work', function () {
    $world = serviceCallWorld();
    fakeServiceCallVerdict(['project_id' => $world['project']->id]);

    $row = app(ServiceCallEmailIntake::class)->consider(katieEmail($world));

    expect($row->outcome)->toBe(ServiceCallEmail::OUTCOME_OPENED)
        ->and($row->project_id)->toBe($world['project']->id)
        ->and($row->user_id)->toBe($world['katie']->id)
        ->and($row->actor_user_id)->toBe($world['admin']->id)
        ->and(data_get($row->verdict, 'reply.cc'))->toBe([mb_strtolower($world['jon']->email)]);

    $project = Project::withoutGlobalScopes()->with('latestStatus')->find($world['project']->id);
    expect((int) $project->latestStatus->status_code)->toBe(8);

    $task = Task::withoutGlobalScopes()->find($row->task_id);
    expect($task->title)->toBe('Seal cracked grout in both bathrooms')
        ->and($task->project_id)->toBe($world['project']->id)
        ->and($task->start_date)->toBeNull()
        ->and($task->created_by_user_id)->toBe($world['admin']->id)
        ->and($task->notes)->toContain('grout cracking in both bathrooms');

    // Nobody is contacted until client contact is switched on.
    Queue::assertNotPushed(SendServiceCallScheduleEmail::class);
    Queue::assertNotPushed(TextServiceCallScheduleLink::class);

    $alert = AppNotification::where('user_id', $world['admin']->id)->where('type', 'service_call_opened')->sole();
    expect($alert->body)->toContain('have not been contacted');

    // The model only ever sees this client's finished projects.
    Http::assertSent(function (HttpRequest $request) use ($world) {
        $prompt = $request['messages'][1]['content'];

        return str_contains($prompt, "id {$world['project']->id}: Bathrooms & Basement")
            && ! str_contains($prompt, 'Basement Repairs');
    });
});

it('leaves a request for new work to the leads pipeline', function () {
    $world = serviceCallWorld();
    fakeServiceCallVerdict(['is_service_request' => false, 'confidence' => 0.93, 'project_id' => null, 'task_title' => null, 'issue' => null]);

    $row = app(ServiceCallEmailIntake::class)->consider(katieEmail($world, ['body' => 'We loved working with you. Could you quote our kitchen next spring?']));

    expect($row->outcome)->toBe(ServiceCallEmail::OUTCOME_NOT_SERVICE)
        ->and(Task::withoutGlobalScopes()->where('project_id', $world['project']->id)->count())->toBe(0)
        ->and((int) Project::withoutGlobalScopes()->find($world['project']->id)->latestStatus->status_code)->toBe(7);

    Queue::assertNotPushed(SendServiceCallScheduleEmail::class);
    Queue::assertNotPushed(TextServiceCallScheduleLink::class);
});

it('does not act on an unsure verdict', function () {
    $world = serviceCallWorld();
    fakeServiceCallVerdict(['project_id' => $world['project']->id, 'confidence' => 0.6]);

    $row = app(ServiceCallEmailIntake::class)->consider(katieEmail($world));

    expect($row->outcome)->toBe(ServiceCallEmail::OUTCOME_NOT_SERVICE)
        ->and(Task::withoutGlobalScopes()->where('project_id', $world['project']->id)->count())->toBe(0);
});

it('ignores strangers and clients without a finished project, without asking the model', function () {
    $world = serviceCallWorld('active');
    fakeServiceCallVerdict();
    $intake = app(ServiceCallEmailIntake::class);

    expect($intake->consider(katieEmail($world)))->toBeNull()
        ->and($intake->consider(katieEmail($world, ['from_email' => 'stranger@example.test', 'cc' => []])))->toBeNull()
        ->and(ServiceCallEmail::count())->toBe(0);

    Http::assertNothingSent();
});

it('emails and texts the client the schedule link only once client contact is on', function () {
    config(['service_calls.email_intake.contact_client' => true]);
    $world = serviceCallWorld();
    fakeServiceCallVerdict(['project_id' => $world['project']->id]);

    $row = app(ServiceCallEmailIntake::class)->consider(katieEmail($world));

    Queue::assertPushed(SendServiceCallScheduleEmail::class, fn ($job) => $job->serviceCallEmailId === $row->id);
    Queue::assertPushed(TextServiceCallScheduleLink::class, fn ($job) => $job->serviceCallEmailId === $row->id);
    expect(AppNotification::where('type', 'service_call_opened')->sole()->body)->toContain('sent the schedule link');
});

it('judges and answers each email once, whichever path reads it again', function () {
    config(['service_calls.email_intake.contact_client' => true]);
    $world = serviceCallWorld();
    fakeServiceCallVerdict(['project_id' => $world['project']->id]);
    $email = katieEmail($world);
    $intake = app(ServiceCallEmailIntake::class);

    $first = $intake->consider($email);
    $again = $intake->consider($email + ['nylas_message_id' => 'the-crew-copy']);

    expect($again->id)->toBe($first->id)
        ->and(Task::withoutGlobalScopes()->where('project_id', $world['project']->id)->count())->toBe(1);
    Http::assertSentCount(1);
    Queue::assertPushed(SendServiceCallScheduleEmail::class, 1);
});

it('treats a reply in the same thread as part of the call already opened', function () {
    $world = serviceCallWorld();
    fakeServiceCallVerdict(['project_id' => $world['project']->id]);
    $intake = app(ServiceCallEmailIntake::class);
    $email = katieEmail($world);

    $intake->consider($email);
    $reply = $intake->consider(katieEmail($world, ['thread_id' => $email['thread_id'], 'body' => 'Thursday morning works for us!']));

    expect($reply->outcome)->toBe(ServiceCallEmail::OUTCOME_FOLLOW_UP)
        ->and(Task::withoutGlobalScopes()->where('project_id', $world['project']->id)->count())->toBe(1);
    Http::assertSentCount(1);
});

it('stands down when the team already moved the project to Service Call after the email', function () {
    $world = serviceCallWorld();
    fakeServiceCallVerdict(['project_id' => $world['project']->id]);
    ProjectStatus::create([
        'project_id' => $world['project']->id,
        'belongs_to_vendor_id' => $world['vendor']->id,
        'status_code' => 8,
        'start_date' => today()->format('Y-m-d'),
    ]);

    $row = app(ServiceCallEmailIntake::class)->consider(katieEmail($world));

    expect($row->outcome)->toBe(ServiceCallEmail::OUTCOME_ALREADY_HANDLED)
        ->and(Task::withoutGlobalScopes()->where('project_id', $world['project']->id)->count())->toBe(0);
    Queue::assertNotPushed(SendServiceCallScheduleEmail::class);
});

it('recognises the client writing from a new address with a known one in CC', function () {
    $world = serviceCallWorld();
    fakeServiceCallVerdict(['project_id' => $world['project']->id]);

    $row = app(ServiceCallEmailIntake::class)->consider(katieEmail($world, [
        'from_email' => 'katie.work@example.test',
        'cc' => [$world['katie']->email],
    ]));

    expect($row->outcome)->toBe(ServiceCallEmail::OUTCOME_OPENED)
        ->and($row->user_id)->toBe($world['katie']->id);
});

it('decides nothing when the model cannot be asked, so the next read tries again', function () {
    $world = serviceCallWorld();
    fakeServiceCallVerdict([], 500);

    expect(app(ServiceCallEmailIntake::class)->consider(katieEmail($world)))->toBeNull()
        ->and(ServiceCallEmail::count())->toBe(0);
});

it('never acts on old mail or when switched off', function () {
    $world = serviceCallWorld();
    fakeServiceCallVerdict(['project_id' => $world['project']->id]);
    $intake = app(ServiceCallEmailIntake::class);

    expect($intake->consider(katieEmail($world, ['received_at' => now()->subDays(4)])))->toBeNull();

    config(['service_calls.email_intake.enabled' => false]);
    expect($intake->consider(katieEmail($world)))->toBeNull()
        ->and(ServiceCallEmail::count())->toBe(0);
});

it('skips the generic "new task" email for the task it already answered with a schedule link', function () {
    Mail::fake();
    $world = serviceCallWorld();
    $world['katie']->forceFill(['registration' => ['registered' => false]])->save();
    fakeServiceCallVerdict(['project_id' => $world['project']->id]);

    app(ServiceCallEmailIntake::class)->consider(katieEmail($world));
    (new SendPendingTaskReminderToClients($world['project']->id))->handle();

    Mail::assertNotSent(PendingTasksReminderEmail::class);

    // A task the team adds by hand still gets it.
    $this->actingAs($world['admin']);
    Task::create(['project_id' => $world['project']->id, 'title' => 'Replace the vanity light', 'type' => 'Task']);
    (new SendPendingTaskReminderToClients($world['project']->id))->handle();

    Mail::assertSent(PendingTasksReminderEmail::class, fn ($mail) => $mail->tasks->pluck('title')->all() === ['Replace the vanity light']);
});
