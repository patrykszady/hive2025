<?php

/**
 * Each stakeholder email gets the AI read a call gets, and a project's
 * calls, texts and emails come back as one newest-first timeline.
 */

use App\Jobs\SummarizeEmailMessage;
use App\Models\CallLog;
use App\Models\EmailMessage;
use App\Models\ProjectStatus;
use App\Models\SmsGroupThread;
use App\Models\SmsMessage;
use App\Services\Communications\ProjectCommunicationsTimeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Support/t3-fixtures.php';
require_once __DIR__.'/../../Support/service-call-fixtures.php';

beforeEach(function () {
    config(['services.openai.api_key' => 'test-key', 'scout.driver' => null]);
    // SummarizeEmailMessage queues ExtractProjectActionItems when it saves
    // action items on a placed email — fake the queue so these tests, which
    // call handle() directly, don't also run that extraction synchronously
    // (QUEUE_CONNECTION=sync in testing) and send it through this file's
    // single-purpose OpenAI fake.
    Queue::fake();
});

it('summarizes an email like a call and places it on the project it is about', function () {
    $world = serviceCallWorld();
    $kitchen = sec3_makeProject($world['vendor'], $world['client'], ['project_name' => 'Kitchen', 'address' => '415 Lincoln Ave']);
    ProjectStatus::create(['project_id' => $kitchen->id, 'belongs_to_vendor_id' => $world['vendor']->id, 'status_code' => 6, 'start_date' => today()->format('Y-m-d')]);

    $email = EmailMessage::factory()->create([
        'belongs_to_vendor_id' => $world['vendor']->id,
        'mailbox' => $world['mailbox'],
        'from_email' => $world['katie']->email,
        'client_id' => $world['client']->id,
        'candidate_project_ids' => [$kitchen->id, $world['project']->id],
        'subject' => 'Cabinet hardware',
        'body_text' => "Can you send the hardware options for the kitchen by Friday?\n\nOn Mon, Oct 12, 2026 at 9:00 AM Patryk wrote:\n> Here is the schedule.",
    ]);

    Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => json_encode([
        'summary' => 'Katie asks for kitchen cabinet hardware options by Friday.',
        'action_items' => ['Patryk: Send the kitchen hardware options by Friday'],
        'topics' => ['cabinet hardware'],
        'next_steps' => ['Katie picks hardware'],
        'sentiment' => 'neutral',
        'intent' => 'request hardware options',
        'project_id' => $kitchen->id,
    ])]]]])]);

    (new SummarizeEmailMessage($email->id))->handle();

    $email->refresh();
    expect($email->summary)->toBe('Katie asks for kitchen cabinet hardware options by Friday.')
        ->and($email->action_items)->toBe(['Patryk: Send the kitchen hardware options by Friday'])
        ->and($email->project_id)->toBe($kitchen->id)
        ->and($email->summarized_at)->not->toBeNull();

    Http::assertSent(function (HttpRequest $request) use ($kitchen) {
        $prompt = $request['messages'][1]['content'];

        return str_contains($prompt, "id {$kitchen->id}: Kitchen")
            // The client's name rides along, so "Abbey-plumbing delivery" can find the Abbeys' job.
            && str_contains($prompt, '(client: ')
            && str_contains($prompt, "New message:\nCan you send the hardware options")
            && str_contains($prompt, 'Quoted history (context only)');
    });
});

it('keeps an email that is not about any job out of the unplaced inbox and off the projects', function () {
    $world = serviceCallWorld();
    $kitchen = sec3_makeProject($world['vendor'], $world['client'], ['project_name' => 'Kitchen', 'address' => '415 Lincoln Ave']);
    ProjectStatus::create(['project_id' => $kitchen->id, 'belongs_to_vendor_id' => $world['vendor']->id, 'status_code' => 6, 'start_date' => today()->format('Y-m-d')]);

    // Patryk forwards a meetup invitation to a client who has one job in
    // progress — which would otherwise put it on that job.
    $email = EmailMessage::factory()->create([
        'belongs_to_vendor_id' => $world['vendor']->id,
        'mailbox' => $world['mailbox'],
        'from_email' => 'patryk.szady@live.com',
        'client_id' => $world['client']->id,
        'candidate_project_ids' => [$kitchen->id],
        'subject' => 'Fw: Reminder: Real Estate Meetup – November 4th!',
        'body_text' => 'Join us Tuesday — Patryk speaks on contractor pitfalls for new investors.',
    ]);

    Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => json_encode([
        'summary' => 'Patryk forwarded an invitation to a real estate meetup where he is the featured speaker.',
        'action_items' => [],
        'topics' => ['real estate meetup'],
        'next_steps' => [],
        'sentiment' => 'positive',
        'intent' => 'share event information',
        'about_a_project' => false,
        'project_id' => null,
    ])]]]])]);

    (new SummarizeEmailMessage($email->id))->handle();

    $email->refresh();
    expect($email->project_id)->toBeNull()
        ->and($email->unplaced_dismissed_at)->not->toBeNull()
        ->and($email->isUnplaced())->toBeFalse()
        ->and(EmailMessage::query()->unplaced()->whereKey($email->id)->exists())->toBeFalse()
        ->and($email->summary)->toContain('real estate meetup');

    Http::assertSent(fn (HttpRequest $request) => isset($request['response_format']['json_schema']['schema']['properties']['about_a_project'])
        && str_contains($request['messages'][0]['content'], 'about_a_project'));
});

it('still waits in the unplaced inbox when it is about a job but the job is unclear', function () {
    $world = serviceCallWorld();
    $kitchen = sec3_makeProject($world['vendor'], $world['client'], ['project_name' => 'Kitchen', 'address' => '415 Lincoln Ave']);
    // Two jobs in progress, so no single one is the obvious answer.
    foreach ([$kitchen->id, $world['project']->id] as $projectId) {
        ProjectStatus::create(['project_id' => $projectId, 'belongs_to_vendor_id' => $world['vendor']->id, 'status_code' => 6, 'start_date' => today()->format('Y-m-d')]);
    }

    $email = EmailMessage::factory()->create([
        'belongs_to_vendor_id' => $world['vendor']->id,
        'mailbox' => $world['mailbox'],
        'from_email' => $world['katie']->email,
        'client_id' => $world['client']->id,
        'candidate_project_ids' => [$kitchen->id, $world['project']->id],
        'subject' => 'Question about the invoice',
        'body_text' => 'Which invoice is this one for?',
    ]);

    Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => json_encode([
        'summary' => 'Katie asks which job an invoice is for.',
        'action_items' => [], 'topics' => ['invoice'], 'next_steps' => [], 'sentiment' => 'neutral',
        'intent' => 'clarify invoice', 'about_a_project' => true, 'project_id' => null,
    ])]]]])]);

    (new SummarizeEmailMessage($email->id))->handle();

    expect($email->fresh()->isUnplaced())->toBeTrue();
});

it('reads the unplaced inbox again on request, so an email read before the rule leaves it', function () {
    $world = serviceCallWorld();
    $email = EmailMessage::factory()->create([
        'belongs_to_vendor_id' => $world['vendor']->id,
        'mailbox' => $world['mailbox'],
        'client_id' => $world['client']->id,
        'subject' => 'Fw: Reminder: Real Estate Meetup – November 4th!',
        'summary' => 'Patryk forwarded an invitation to a real estate meetup.',
        'summarized_at' => now()->subDay(),
    ]);
    $placed = EmailMessage::factory()->create([
        'belongs_to_vendor_id' => $world['vendor']->id,
        'project_id' => $world['project']->id,
        'summarized_at' => now()->subDay(),
    ]);
    expect($email->fresh()->isUnplaced())->toBeTrue();

    Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => json_encode([
        'summary' => 'Patryk forwarded an invitation to a real estate meetup.', 'action_items' => [], 'topics' => ['meetup'],
        'next_steps' => [], 'sentiment' => 'positive', 'intent' => 'share event information', 'about_a_project' => false, 'project_id' => null,
    ])]]]])]);

    // An already-read email is skipped by a plain re-dispatch…
    (new SummarizeEmailMessage($email->id))->handle();
    expect($email->fresh()->isUnplaced())->toBeTrue();

    // …and read again by the command's --unplaced, which leaves placed email alone.
    Queue::fake();
    $this->artisan('emails:summarize', ['--unplaced' => true])->expectsOutputToContain('Queued 1 email(s)')->assertSuccessful();
    Queue::assertPushed(SummarizeEmailMessage::class, fn ($job) => $job->emailMessageId === $email->id && $job->reread);
    Queue::assertNotPushed(SummarizeEmailMessage::class, fn ($job) => $job->emailMessageId === $placed->id);

    (new SummarizeEmailMessage($email->id, true))->handle();
    expect($email->fresh()->isUnplaced())->toBeFalse()
        ->and($email->fresh()->unplaced_dismissed_at)->not->toBeNull();
});

it('marks an email read when the model answers junk, so the schedule does not pay for it again', function () {
    $email = EmailMessage::factory()->create();
    Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => 'not json']]]])]);

    (new SummarizeEmailMessage($email->id))->handle();

    expect($email->fresh()->summarized_at)->not->toBeNull()
        ->and($email->fresh()->summary)->toBeNull();
});

it('cuts the quoted history off an email', function () {
    expect(SummarizeEmailMessage::latestPart("Sounds good, Thursday works.\n\nOn Tue, Oct 13, 2026, Greg wrote:\n> Can we come Thursday?"))->toBe('Sounds good, Thursday works.')
        ->and(SummarizeEmailMessage::latestPart("Approved.\n\n-----Original Message-----\nFrom: Patryk"))->toBe('Approved.');
});

it('merges a project\'s calls, texts and emails newest first, a page at a time', function () {
    $world = serviceCallWorld();
    $digits = substr(preg_replace('/\D/', '', $world['katie']->cell_phone), -10);

    $call = CallLog::withoutEvents(fn () => CallLog::create([
        'direction' => 'incoming', 'from_number' => '+1'.$digits, 'to_number' => '+12247354200', 'status' => 'completed', 'duration_seconds' => 95,
    ]));
    $call->forceFill(['created_at' => now()->subHours(3)])->saveQuietly();

    $thread = SmsGroupThread::withoutEvents(fn () => SmsGroupThread::create(['client_id' => $world['client']->id, 'participants' => ['+1'.$digits], 'from_number' => '+12247354200']));
    $text = SmsMessage::withoutEvents(fn () => SmsMessage::create(['thread_id' => $thread->id, 'direction' => 'inbound', 'from_number' => '+1'.$digits, 'text' => 'Thursday works!']));
    $text->forceFill(['created_at' => now()->subHours(2)])->saveQuietly();

    $email = EmailMessage::factory()->create([
        'belongs_to_vendor_id' => $world['vendor']->id,
        'client_id' => $world['client']->id,
        'project_id' => $world['project']->id,
        'subject' => 'Grout',
        'summary' => 'Katie reports cracked grout.',
        'sent_at' => now()->subHour(),
        'attachments' => [['path' => 'emails/x.jpg', 'name' => 'x.jpg', 'mime' => 'image/jpeg', 'size' => 1]],
    ]);
    EmailMessage::factory()->create(['belongs_to_vendor_id' => $world['vendor']->id, 'client_id' => 999999, 'sent_at' => now()]);

    $timeline = app(ProjectCommunicationsTimeline::class);
    $all = $timeline->forProject($world['project']);

    expect(collect($all['items'])->map(fn ($item) => $item['kind'].':'.$item['id'])->all())->toBe(["email:{$email->id}", "sms:{$text->id}", "call:{$call->id}"])
        ->and($all['items'][0]['preview'])->toBe('Katie reports cracked grout.')
        ->and($all['items'][0]['attachment_count'])->toBe(1)
        ->and($all['items'][1]['who'])->toBe('Katie Love')
        ->and($all['items'][2]['direction'])->toBe('inbound')
        ->and($all['next_before'])->toBeNull();

    $first = $timeline->forProject($world['project'], limit: 2);
    $rest = $timeline->forProject($world['project'], before: \Illuminate\Support\Carbon::parse($first['next_before']), limit: 2);
    expect(count($first['items']))->toBe(2)
        ->and(collect($rest['items'])->pluck('kind')->all())->toBe(['call']);

    expect(collect($timeline->forProject($world['project'], kinds: ['email'])['items'])->pluck('kind')->unique()->all())->toBe(['email']);
});

/**
 * Erin Abbey forwarded two Menards delivery emails naming 949 S Elm Ct, where
 * three finished jobs and the active Kitchen sit. Nothing chose, so both went
 * unplaced (2026-10-29). The AI now sees each job's status, and when it still
 * does not choose, the client's one job in progress is the job.
 */
function abbeyHouse(): array
{
    $world = serviceCallWorld();
    $vendor = $world['vendor'];
    $make = function (string $name, int $code) use ($world, $vendor) {
        $project = sec3_makeProject($vendor, $world['client'], ['project_name' => $name, 'address' => '949 S Elm Ct']);
        ProjectStatus::create(['project_id' => $project->id, 'belongs_to_vendor_id' => $vendor->id, 'status_code' => $code, 'start_date' => today()->subMonths($code === 6 ? 1 : 20)->format('Y-m-d')]);

        return $project;
    };

    return $world + ['basement' => $make('Basement', 7), 'bar' => $make('Basement Bar', 7), 'kitchen' => $make('Kitchen', 6)];
}

function menardsForward(array $house): EmailMessage
{
    return EmailMessage::factory()->create([
        'belongs_to_vendor_id' => $house['vendor']->id,
        'mailbox' => $house['mailbox'],
        'from_email' => $house['katie']->email,
        'client_id' => $house['client']->id,
        'candidate_project_ids' => [$house['kitchen']->id, $house['bar']->id, $house['basement']->id],
        'subject' => 'Fwd: Thank you for your delivery purchase',
        'body_text' => 'Your Menards order will be delivered to 949 S Elm Ct.',
    ]);
}

function aiAnswers(?int $projectId): void
{
    Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => json_encode([
        'summary' => 'Menards delivery confirmation forwarded.',
        'action_items' => [], 'topics' => ['delivery'], 'next_steps' => [], 'sentiment' => 'neutral',
        'intent' => 'share delivery details', 'project_id' => $projectId,
    ])]]]])]);
}

it('places an email the AI did not place on the client\'s one job in progress', function () {
    $house = abbeyHouse();
    $email = menardsForward($house);
    aiAnswers(null);

    (new SummarizeEmailMessage($email->id))->handle();

    expect($email->fresh()->project_id)->toBe($house['kitchen']->id);
});

it('tells the AI which job is in progress and which are complete', function () {
    $house = abbeyHouse();
    $email = menardsForward($house);
    aiAnswers($house['kitchen']->id);

    (new SummarizeEmailMessage($email->id))->handle();

    Http::assertSent(function (HttpRequest $request) use ($house) {
        $prompt = $request['messages'][1]['content'];
        $system = $request['messages'][0]['content'];

        return str_contains($prompt, "id {$house['kitchen']->id}: Kitchen")
            && str_contains($prompt, '— Active since')
            && str_contains($prompt, '— Complete since')
            && str_contains($system, 'the email is about the one in progress');
    });
});

it('still lets the AI put a warranty email on the finished job', function () {
    $house = abbeyHouse();
    $email = menardsForward($house);
    aiAnswers($house['basement']->id);

    (new SummarizeEmailMessage($email->id))->handle();

    expect($email->fresh()->project_id)->toBe($house['basement']->id);
});

it('leaves an email unplaced when the client has two jobs in progress and the AI does not choose', function () {
    $house = abbeyHouse();
    ProjectStatus::create(['project_id' => $house['bar']->id, 'belongs_to_vendor_id' => $house['vendor']->id, 'status_code' => 6, 'start_date' => today()->format('Y-m-d')]);
    $email = menardsForward($house);
    aiAnswers(null);

    (new SummarizeEmailMessage($email->id))->handle();

    expect($email->fresh()->project_id)->toBeNull();
});

