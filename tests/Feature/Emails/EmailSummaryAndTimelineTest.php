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
