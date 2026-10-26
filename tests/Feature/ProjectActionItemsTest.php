<?php

use App\Models\CallLog;
use App\Models\CallTranscript;
use App\Models\Client;
use App\Models\EmailMessage;
use App\Models\Project;
use App\Models\ProjectActionItem;
use App\Models\ProjectActionItemScan;
use App\Models\SmsGroupThread;
use App\Models\SmsMessage;
use App\Models\User;
use App\Models\Vendor;
use App\Services\ActionItems\ProjectActionItemExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

// =========================================================================
// Action tasks from a project's calls and texts (2026-10-12): calls carry
// action items from their transcript summary, texts get one model pass per
// batch, and the same task said twice is one row with two sources. Calls and
// threads belong to the client, so the project's conversations are found
// through its client's people.
// =========================================================================

/**
 * @return array{project: Project, client: Client, mark: User}
 */
function actionItemFixture(): array
{
    $vendor = Vendor::factory()->create(['options' => ['short_name' => 'GS']]);

    // Creating a project runs ProjectObserver, which stamps the signed-in
    // user's vendor on it.
    $admin = new User();
    $admin->forceFill(['first_name' => 'Patryk', 'last_name' => 'Admin', 'email' => 'items-admin.'.uniqid().'@example.test', 'cell_phone' => '2247354200', 'primary_vendor_id' => $vendor->id]);
    $admin->save();
    $vendor->users()->attach($admin->id, ['role_id' => 1]);
    test()->actingAs($admin);

    $client = Client::factory()->create();
    $client->vendors()->attach($vendor->id);
    $mark = User::factory()->create(['first_name' => 'Mark', 'last_name' => 'Brodson', 'cell_phone' => '7732513666']);
    $client->users()->attach($mark->id);
    $project = Project::factory()->create(['client_id' => $client->id, 'address' => '3154 Violet', 'created_at' => now()->subMonths(3)]);
    $project->vendors()->attach($vendor->id, ['client_id' => $client->id]);

    return ['project' => $project, 'client' => $client, 'mark' => $mark];
}

/** @param  list<string>  $items */
function transcribedCall(string $with, array $items, string $at): CallLog
{
    $call = CallLog::factory()->create(['direction' => 'outgoing', 'from_number' => '+12247354200', 'to_number' => $with, 'created_at' => $at, 'updated_at' => $at]);
    CallTranscript::create(['call_log_id' => $call->id, 'engine' => 'test', 'language' => 'en', 'text' => 'transcript', 'status' => 'completed', 'action_items' => $items, 'summarized_at' => now()]);

    return $call;
}

function textMessage(SmsGroupThread $thread, string $direction, string $text, string $at): SmsMessage
{
    $inbound = $direction === 'inbound';
    $message = new SmsMessage([
        'thread_id' => $thread->id, 'provider' => 'telnyx', 'direction' => $direction,
        'from_number' => $inbound ? '+17732513666' : '+12247354200', 'to_numbers' => [$inbound ? '+12247354200' : '+17732513666'],
        'text' => $text, 'status' => $inbound ? 'received' : 'delivered',
    ]);
    // Timestamps are not mass-assignable; the extractor reads them.
    $message->created_at = $at;
    $message->updated_at = $at;
    $message->save();

    return $message;
}

beforeEach(function () {
    // No key: no model calls; grouping falls back to word overlap.
    config(['services.openai.api_key' => null]);
    // The fixtures carry real dates; "today" must stay next to them.
    $this->travelTo(\Illuminate\Support\Carbon::parse('2026-10-12 18:00:00', 'America/Chicago'));
});

it('lists the action items of the client\'s calls, one row per task however often it came up', function () {
    ['project' => $project] = actionItemFixture();
    $first = transcribedCall('+17732513666', ['Patryk: Send Mark the latest window invoice', 'Mark: Research Provia door pricing and quality'], '2026-10-08 10:00:00');
    $second = transcribedCall('+17732513666', ['Patryk: Send the latest window invoice to Mark'], '2026-10-10 15:00:00');
    transcribedCall('+15555550123', ['Patryk: Call the other client back'], '2026-10-09 09:00:00');

    $stats = app(ProjectActionItemExtractor::class)->run($project);

    expect($stats)->toMatchArray(['calls' => 2, 'messages' => 0, 'created' => 2, 'merged' => 1, 'done' => 0]);

    $items = ProjectActionItem::where('project_id', $project->id)->orderBy('id')->get();
    expect($items)->toHaveCount(2);

    $invoice = $items->firstWhere('title', 'Send Mark the latest window invoice');
    expect($invoice->owner)->toBe('Patryk')
        ->and($invoice->mentions)->toBe(2)
        ->and($invoice->status)->toBe(ProjectActionItem::STATUS_OPEN)
        ->and(collect($invoice->sources)->pluck('id')->all())->toBe([$first->id, $second->id])
        ->and($invoice->first_mentioned_at->toDateString())->toBe('2026-10-08')
        ->and($invoice->last_mentioned_at->toDateString())->toBe('2026-10-10');

    expect($items->firstWhere('title', 'Research Provia door pricing and quality')->owner)->toBe('Mark');
    expect(ProjectActionItem::where('title', 'Call the other client back')->exists())->toBeFalse();
});

it('reads each conversation only past where it left off', function () {
    ['project' => $project] = actionItemFixture();
    $first = transcribedCall('+17732513666', ['Patryk: Send Mark the latest window invoice'], '2026-10-08 10:00:00');
    app(ProjectActionItemExtractor::class)->run($project);

    // The same call again changes nothing; a new call adds one source.
    app(ProjectActionItemExtractor::class)->run($project);
    expect(ProjectActionItem::where('project_id', $project->id)->first()->mentions)->toBe(1)
        ->and(ProjectActionItemScan::lastIdFor($project->id, ProjectActionItemScan::KIND_CALLS))->toBe($first->id);

    $second = transcribedCall('+17732513666', ['Patryk: Send Mark the window invoice'], '2026-10-11 10:00:00');
    $stats = app(ProjectActionItemExtractor::class)->run($project);

    expect($stats['calls'])->toBe(1)
        ->and(ProjectActionItem::where('project_id', $project->id)->count())->toBe(1)
        ->and(ProjectActionItem::where('project_id', $project->id)->first()->mentions)->toBe(2)
        ->and(ProjectActionItemScan::lastIdFor($project->id, ProjectActionItemScan::KIND_CALLS))->toBe($second->id);
});

it('turns a text thread into items with one model pass, groups them with the calls, and closes what a later text reports done', function () {
    config(['services.openai.api_key' => 'test-key']);
    ['project' => $project, 'client' => $client] = actionItemFixture();
    $call = transcribedCall('+17732513666', ['Patryk: Send Mark the latest window invoice', 'Mark: Have the window check ready for delivery on Monday'], '2026-10-10 15:00:00');

    $thread = SmsGroupThread::create(['name' => 'Mark Brodson', 'from_number' => '+17732513666', 'participants' => ['+17732513666'], 'client_id' => $client->id, 'last_activity_at' => now()]);
    $ask = textMessage($thread, 'outbound', 'Windows will be there within the hour. Greg is there, can you give him a 14K check? -PS', '2026-10-12 12:42:00');
    $clear = textMessage($thread, 'inbound', 'Is Arturo coming today or tomorrow? We need to clear the basement out for Ramjack on Wednesday', '2026-10-12 12:47:00');
    $done = textMessage($thread, 'inbound', 'Thanks! Greg has check in hand', '2026-10-12 13:55:00');

    $prompts = [];
    Http::fake([
        // Stand-in for semantic vectors: every phrasing of the window-check
        // task shares one vector, anything else is word-hashed.
        'api.openai.com/v1/embeddings' => function ($request) {
            $data = [];
            foreach ((array) $request['input'] as $i => $text) {
                $vector = array_fill(0, 64, 0.0);
                if (str_contains(strtolower($text), 'window check')) {
                    $vector[0] = 1.0;
                } else {
                    foreach (explode(' ', ProjectActionItem::normalizedWords($text)) as $word) {
                        if (strlen($word) >= 3) {
                            $vector[1 + crc32($word) % 63] += 1.0;
                        }
                    }
                }
                $data[] = ['index' => $i, 'embedding' => $vector];
            }

            return Http::response(['data' => $data]);
        },
        'api.openai.com/v1/chat/completions' => function ($request) use (&$prompts, $ask, $clear, $done) {
            if (data_get($request->data(), 'response_format.json_schema.name') === 'action_item_closures') {
                return Http::response(['choices' => [['message' => ['content' => json_encode(['decisions' => []])]]]]);
            }
            if (data_get($request->data(), 'response_format.json_schema.name') === 'action_item_merges') {
                return Http::response(['choices' => [['message' => ['content' => json_encode(['groups' => []])]]]]);
            }
            $prompts[] = (string) data_get($request->data(), 'messages.1.content');

            return Http::response(['choices' => [['message' => ['content' => json_encode(['items' => [
                ['title' => 'Give Greg the ~$14K window check', 'owner' => 'Mark', 'due_date' => '2026-10-12', 'kind' => 'todo', 'message_ids' => [$ask->id]],
                ['title' => 'Clear the basement out for Ramjack', 'owner' => 'Mark', 'due_date' => '2026-10-14', 'kind' => 'todo', 'message_ids' => [$clear->id]],
                ['title' => 'Hand over the window check', 'owner' => 'Mark', 'due_date' => null, 'kind' => 'done', 'message_ids' => [$done->id]],
            ]])]]]]);
        },
    ]);

    $stats = app(ProjectActionItemExtractor::class)->run($project);

    expect($stats)->toMatchArray(['calls' => 1, 'messages' => 3, 'created' => 3, 'merged' => 1, 'done' => 1]);

    // One pass for the thread, every message on its own dated line — in
    // the company's time (Chicago), not the server's UTC, so "tomorrow" in
    // a 9 pm text is the right day.
    expect($prompts)->toHaveCount(1)
        ->and($prompts[0])->toContain('#'.$ask->id.' [2026-10-12 07:42 Mon] GS: Windows will be there')
        ->and($prompts[0])->toContain('#'.$clear->id.' [2026-10-12 07:47 Mon] Mark: Is Arturo coming');

    $items = ProjectActionItem::where('project_id', $project->id)->get();
    expect($items)->toHaveCount(3);

    // "Give Greg the window check" (text) folded into the call's "have the
    // window check ready" — then the thanks closed it.
    $check = $items->firstWhere('title', 'Have the window check ready for delivery on Monday');
    expect($check->mentions)->toBe(2)
        ->and(collect($check->sources)->pluck('kind')->all())->toBe(['call', 'sms'])
        ->and(collect($check->sources)->pluck('id')->all())->toBe([$call->id, $ask->id])
        ->and($check->due_on->toDateString())->toBe('2026-10-12')
        ->and($check->status)->toBe(ProjectActionItem::STATUS_DONE)
        ->and($check->done_evidence['id'])->toBe($done->id)
        ->and($check->done_at->format('Y-m-d H:i'))->toBe('2026-10-12 13:55');

    $basement = $items->firstWhere('title', 'Clear the basement out for Ramjack');
    expect($basement->owner)->toBe('Mark')
        ->and($basement->due_on->toDateString())->toBe('2026-10-14')
        ->and($basement->status)->toBe(ProjectActionItem::STATUS_OPEN)
        ->and($basement->sources[0])->toMatchArray(['kind' => 'sms', 'id' => $clear->id, 'thread_id' => $thread->id]);

    expect($items->firstWhere('title', 'Send Mark the latest window invoice')->status)->toBe(ProjectActionItem::STATUS_OPEN)
        ->and(ProjectActionItemScan::lastIdFor($project->id, ProjectActionItemScan::KIND_SMS_THREAD, $thread->id))->toBe($done->id);
});

it('lists the open items from the console, and a dry run keeps nothing', function () {
    ['project' => $project] = actionItemFixture();
    transcribedCall('+17732513666', ['Patryk: Send Mark the latest window invoice'], '2026-10-08 10:00:00');

    $this->artisan('project:action-items', ['project' => $project->id, '--dry-run' => true])
        ->expectsOutputToContain('Send Mark the latest window invoice')
        ->assertExitCode(0);
    expect(ProjectActionItem::count())->toBe(0);

    $this->artisan('project:action-items', ['project' => $project->id])->assertExitCode(0);
    expect(ProjectActionItem::count())->toBe(1);
});

it('splits "who: what" the way the call summaries write it', function () {
    $extractor = app(ProjectActionItemExtractor::class);

    expect($extractor->splitOwner('Patryk: Send Mark the latest window invoice'))->toBe(['Patryk', 'Send Mark the latest window invoice'])
        ->and($extractor->splitOwner('Mark and Gail: Visit American Flooring'))->toBe(['Mark and Gail', 'Visit American Flooring'])
        ->and($extractor->splitOwner('Confirm the delivery time: Monday 9am'))->toBe([null, 'Confirm the delivery time: Monday 9am'])
        ->and(ProjectActionItemExtractor::wordOverlap('Send Mark the latest window invoice', 'Send the latest window invoice to Mark'))->toBeGreaterThan(0.6);
});

it('leaves out call-backs, reads "who to do what" and "who – what", and dates relative cues from the call\'s day', function () {
    ['project' => $project] = actionItemFixture();
    // A Saturday evening call, Chicago time.
    transcribedCall('+17732513666', [
        'Patryk: Call Mark back when available',
        'Mark to call Patryk back after lunch',
        'Patryk to call Matt Monday morning to set a meeting time on site',
        'Mark – Attend the site meeting with Yael tomorrow at 9:15',
        'Patryk: Review and send Mark the latest budget spreadsheet by end of day',
        'Mark (or Patryk) to send Steve\'s response to Matt once it is received',
    ], '2026-10-10 23:30:00');

    app(ProjectActionItemExtractor::class)->run($project);

    $items = ProjectActionItem::where('project_id', $project->id)->get()->keyBy('title');
    expect($items->keys()->sort()->values()->all())->toBe([
        'Attend the site meeting with Yael tomorrow at 9:15',
        'Call Matt Monday morning to set a meeting time on site',
        'Review and send Mark the latest budget spreadsheet by end of day',
        'Send Steve\'s response to Matt once it is received',
    ]);
    expect($items['Call Matt Monday morning to set a meeting time on site']->owner)->toBe('Patryk')
        ->and($items['Call Matt Monday morning to set a meeting time on site']->due_on->toDateString())->toBe('2026-10-12')
        ->and($items['Attend the site meeting with Yael tomorrow at 9:15']->owner)->toBe('Mark')
        ->and($items['Attend the site meeting with Yael tomorrow at 9:15']->due_on->toDateString())->toBe('2026-10-11')
        ->and($items['Review and send Mark the latest budget spreadsheet by end of day']->due_on->toDateString())->toBe('2026-10-10')
        ->and($items['Send Steve\'s response to Matt once it is received']->owner)->toBe('Mark (or Patryk)')
        ->and($items['Send Steve\'s response to Matt once it is received']->due_on)->toBeNull();
});

it('lapses an item whose day has passed and one nobody mentioned for a month, and never reads our schedule texts', function () {
    config(['services.openai.api_key' => 'test-key']);
    ['project' => $project, 'client' => $client] = actionItemFixture();
    transcribedCall('+17732513666', ['Mark: Have the window check ready for delivery on Monday'], '2026-10-01 15:00:00');
    transcribedCall('+17732513666', ['Patryk: Order the shed'], '2026-09-01 15:00:00');
    transcribedCall('+17732513666', ['Mark: Finalize tile selection for the basement'], '2026-10-07 15:00:00');

    $thread = SmsGroupThread::create(['name' => 'Mark Brodson', 'from_number' => '+17732513666', 'participants' => ['+17732513666'], 'client_id' => $client->id, 'last_activity_at' => now()]);
    textMessage($thread, 'outbound', 'Hi Mark & Gail, Upcoming tasks: Tomorrow Mon 10/12: - Windows delivery @ 8AM. View Schedule: https://hive.contractors/s/abc123', '2026-10-11 17:00:00');
    textMessage($thread, 'inbound', 'Thanks!', '2026-10-11 17:05:00');

    Http::fake();
    $stats = app(ProjectActionItemExtractor::class)->run($project);

    // The schedule text and the thanks are not worth a model pass.
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'chat/completions') && str_contains((string) data_get($request->data(), 'messages.1.content'), 'Upcoming tasks'));
    expect($stats)->toMatchArray(['messages' => 0, 'created' => 3, 'lapsed' => 2]);

    $items = ProjectActionItem::where('project_id', $project->id)->get()->keyBy('title');
    expect($items['Have the window check ready for delivery on Monday']->due_on->toDateString())->toBe('2026-10-05')
        ->and($items['Have the window check ready for delivery on Monday']->status)->toBe(ProjectActionItem::STATUS_LAPSED)
        ->and($items['Order the shed']->status)->toBe(ProjectActionItem::STATUS_LAPSED)
        ->and($items['Finalize tile selection for the basement']->status)->toBe(ProjectActionItem::STATUS_OPEN);

    expect(ProjectActionItemExtractor::isWorthReading('Will do. Heading over now.'))->toBeTrue()
        ->and(ProjectActionItemExtractor::isWorthReading('📞 Mark Brodson called and requested a callback via the phone menu.'))->toBeFalse()
        ->and(ProjectActionItemExtractor::isTransient('Call Denise on Monday for the final window invoice'))->toBeFalse()
        ->and(ProjectActionItemExtractor::isTransient('Circle back with Mark in approximately one hour'))->toBeTrue();
});

it('reviews the open list in two passes: closes what the log shows with a quoted line, drops non-tasks, then merges wordings', function () {
    config(['services.openai.api_key' => 'test-key']);
    ['project' => $project] = actionItemFixture();
    $first = transcribedCall('+17732513666', ['Mark: Pull the trigger and place the order for the Extend windows', 'Mark: Pick up the hot water heater before October 1st', 'GS: The crew keeps framing on the second floor'], '2026-09-29 15:00:00');
    CallTranscript::where('call_log_id', $first->id)->update(['summary' => 'Mark will order the Extend windows this week and pick up the water heater.']);
    $second = transcribedCall('+17732513666', ['Mark: Place the window order now that Patryk has confirmed his review', 'Patryk: Send Mark the latest window invoice'], '2026-10-02 15:00:00');
    CallTranscript::where('call_log_id', $second->id)->update(['summary' => 'Mark picked up the water heater yesterday. Windows to be ordered today.']);

    $requests = [];
    Http::fake([
        'api.openai.com/v1/embeddings' => Http::response(['data' => []]),
        'api.openai.com/v1/chat/completions' => function ($request) use (&$requests) {
            $name = data_get($request->data(), 'response_format.json_schema.name');
            $requests[$name][] = $request->data();
            $ids = ProjectActionItem::query()->orderBy('id')->pluck('id', 'title');

            if ($name === 'action_item_closures') {
                return Http::response(['choices' => [['message' => ['content' => json_encode(['decisions' => [
                    ['id' => $ids['Pull the trigger and place the order for the Extend windows'], 'action' => 'keep', 'reason' => ''],
                    ['id' => $ids['Place the window order now that Patryk has confirmed his review'], 'action' => 'keep', 'reason' => ''],
                    ['id' => $ids['Pick up the hot water heater before October 1st'], 'action' => 'done', 'reason' => '[call 2026-10-02] Mark picked up the water heater yesterday.'],
                    ['id' => $ids['The crew keeps framing on the second floor'], 'action' => 'drop', 'reason' => 'crew schedule, not a task'],
                    ['id' => $ids['Send Mark the latest window invoice'], 'action' => 'keep', 'reason' => ''],
                ]])]]]]);
            }

            return Http::response(['choices' => [['message' => ['content' => json_encode(['groups' => [
                ['keep_id' => $ids['Place the window order now that Patryk has confirmed his review'], 'merge_ids' => [$ids['Pull the trigger and place the order for the Extend windows']], 'reason' => 'same order'],
            ]])]]]]);
        },
    ]);

    $stats = app(ProjectActionItemExtractor::class)->run($project);

    expect($stats['consolidated'])->toBe(['merged' => 1, 'done' => 1, 'dropped' => 1]);

    // The closing pass saw the items and the window's call summaries; the
    // merge pass saw only what was still open, without the log.
    expect($requests)->toHaveKeys(['action_item_closures', 'action_item_merges']);
    $closing = (string) data_get($requests['action_item_closures'][0], 'messages.1.content');
    $merging = (string) data_get($requests['action_item_merges'][0], 'messages.1.content');
    expect($closing)->toContain('OPEN ITEMS:')
        ->and($closing)->toContain('[call 2026-10-02] Mark picked up the water heater yesterday.')
        ->and($merging)->not->toContain('CONVERSATION LOG')
        ->and($merging)->not->toContain('Pick up the hot water heater')
        ->and($merging)->toContain('Pull the trigger and place the order for the Extend windows');

    $items = ProjectActionItem::query()->get()->keyBy('title');
    $order = $items['Place the window order now that Patryk has confirmed his review'];
    expect($order->status)->toBe(ProjectActionItem::STATUS_OPEN)
        ->and($order->mentions)->toBe(2)
        ->and(collect($order->sources)->pluck('id')->sort()->values()->all())->toBe([$first->id, $second->id])
        ->and($items['Pull the trigger and place the order for the Extend windows']->status)->toBe(ProjectActionItem::STATUS_DISMISSED)
        ->and($items['Pull the trigger and place the order for the Extend windows']->done_evidence['kind'])->toBe('merged')
        ->and($items['Pick up the hot water heater before October 1st']->status)->toBe(ProjectActionItem::STATUS_DONE)
        ->and($items['Pick up the hot water heater before October 1st']->done_evidence['excerpt'])->toBe('[call 2026-10-02] Mark picked up the water heater yesterday.')
        ->and($items['The crew keeps framing on the second floor']->status)->toBe(ProjectActionItem::STATUS_DISMISSED)
        ->and($items['Send Mark the latest window invoice']->status)->toBe(ProjectActionItem::STATUS_OPEN);

    // Nothing new since: no review until --review asks for one.
    $requests = [];
    $again = app(ProjectActionItemExtractor::class)->run($project);
    expect($again['consolidated'])->toBe(['merged' => 0, 'done' => 0, 'dropped' => 0])
        ->and($requests)->toBe([]);
    app(ProjectActionItemExtractor::class)->run($project, review: true);
    expect($requests)->toHaveKey('action_item_closures');
});

it('runs every planner project in one nightly pass', function () {
    ['project' => $project] = actionItemFixture();
    $vendorId = $project->belongs_to_vendor_id;
    $project->statuses()->create(['status_code' => 6, 'start_date' => now()->toDateString(), 'belongs_to_vendor_id' => $vendorId]);
    $parked = Project::factory()->create(['client_id' => $project->client_id, 'address' => '9 Parked Ln']);
    $parked->vendors()->attach($vendorId, ['client_id' => $project->client_id]);
    $parked->statuses()->create(['status_code' => 1, 'start_date' => now()->toDateString(), 'belongs_to_vendor_id' => $vendorId]);
    transcribedCall('+17732513666', ['Patryk: Send Mark the latest window invoice'], '2026-10-10 10:00:00');

    $this->artisan('project:action-items', ['--active' => true])
        ->expectsOutputToContain('1 active projects')
        ->expectsOutputToContain('#'.$project->id)
        ->doesntExpectOutputToContain('9 Parked Ln')
        ->assertExitCode(0);

    expect(ProjectActionItem::where('project_id', $project->id)->count())->toBe(1)
        ->and(ProjectActionItem::where('project_id', $parked->id)->count())->toBe(0);
});

// =========================================================================
// Action tasks from a project's stakeholder email (2026-10-19): email
// already carries action items from its own AI read
// (EmailMessage::action_items, written by SummarizeEmailMessage) in the
// same "Owner: do the thing" shape as a call, so it is read the same way.
// Unlike calls and texts, email is matched by project id directly — an
// email the AI could not confidently place on one of the client's projects
// is never guessed onto any of them.
// =========================================================================

/** @param  list<string>  $items */
function summarizedEmail(?Project $project, array $items, string $sentAt, ?string $subject = 'Project update'): EmailMessage
{
    return EmailMessage::factory()->create([
        'project_id' => $project?->id,
        'sent_at' => $sentAt,
        'subject' => $subject,
        'body_text' => implode("\n", $items),
        'action_items' => $items,
        'summarized_at' => now(),
    ]);
}

/**
 * The model pass that reads a project's emails — the same one texts get
 * (2026-10-26). $itemsFor gets the email ids on the request and returns the
 * items the model "reads" from them; the consolidator's passes answer empty.
 *
 * @param  callable(list<int>, string): list<array<string, mixed>>  $itemsFor
 * @param  list<string>  $prompts  every reading prompt, filled in as they are sent
 */
function fakeEmailReading(callable $itemsFor, array &$prompts = []): void
{
    Http::fake([
        'api.openai.com/v1/embeddings' => Http::response(['data' => []]),
        'api.openai.com/v1/chat/completions' => function ($request) use ($itemsFor, &$prompts) {
            $name = data_get($request->data(), 'response_format.json_schema.name');
            if ($name === 'action_item_closures') {
                return Http::response(['choices' => [['message' => ['content' => json_encode(['decisions' => []])]]]]);
            }
            if ($name === 'action_item_merges') {
                return Http::response(['choices' => [['message' => ['content' => json_encode(['groups' => []])]]]]);
            }
            $prompt = (string) data_get($request->data(), 'messages.1.content');
            $prompts[] = $prompt;
            preg_match_all('/^#(\d+) /m', $prompt, $ids);

            return Http::response(['choices' => [['message' => ['content' => json_encode(['items' => $itemsFor(array_map('intval', $ids[1]), $prompt)])]]]]);
        },
    ]);
}

/** One item as the reading pass returns it. */
function readItem(string $title, ?string $owner, array $ids, ?string $due = null, ?string $start = null, ?string $end = null, string $kind = 'todo'): array
{
    return ['title' => $title, 'owner' => $owner, 'due_date' => $due, 'start_time' => $start, 'end_time' => $end, 'kind' => $kind, 'message_ids' => $ids];
}

it('reads a project\'s stakeholder email with the same model pass as texts', function () {
    config(['services.openai.api_key' => 'test-key']);
    ['project' => $project] = actionItemFixture();
    $email = summarizedEmail($project, ['Patryk: Send the window quote by Friday', 'Mark: Pick the tile color'], '2026-10-08 09:00:00', 'Window quote');

    $prompts = [];
    fakeEmailReading(fn (array $ids) => in_array($email->id, $ids, true) ? [
        readItem('Send the window quote by Friday', 'Patryk', [$email->id], '2026-10-09'),
        readItem('Pick the tile color', 'Mark', [$email->id]),
    ] : [], $prompts);

    $stats = app(ProjectActionItemExtractor::class)->run($project);

    expect($stats)->toMatchArray(['calls' => 0, 'messages' => 0, 'emails' => 1, 'created' => 2, 'merged' => 0])
        ->and($prompts)->toHaveCount(1)
        ->and($prompts[0])->toContain('#'.$email->id.' [')->toContain('Subject: Window quote');

    $items = ProjectActionItem::where('project_id', $project->id)->orderBy('id')->get();
    expect($items)->toHaveCount(2);

    $quote = $items->firstWhere('title', 'Send the window quote by Friday');
    expect($quote->owner)->toBe('Patryk')
        ->and($quote->due_on->toDateString())->toBe('2026-10-09')
        ->and($quote->sources)->toBe([[
            'kind' => 'email',
            'id' => $email->id,
            'thread_id' => null,
            'at' => Carbon::parse('2026-10-08 09:00:00')->toIso8601String(),
            'excerpt' => 'Patryk: Send the window quote by Friday Mark: Pick the tile color',
            'subject' => 'Window quote',
        ]]);

    expect($items->firstWhere('title', 'Pick the tile color')->owner)->toBe('Mark');
    expect(ProjectActionItemScan::lastIdFor($project->id, ProjectActionItemScan::KIND_EMAILS))->toBe($email->id);
});

it('reads a project\'s email only past where it left off', function () {
    config(['services.openai.api_key' => 'test-key']);
    ['project' => $project] = actionItemFixture();
    $first = summarizedEmail($project, ['Patryk: Send Mark the latest window invoice'], '2026-10-08 10:00:00');
    $second = null;

    $prompts = [];
    fakeEmailReading(function (array $ids) use (&$first, &$second) {
        $items = [];
        if (in_array($first->id, $ids, true)) {
            $items[] = readItem('Send Mark the latest window invoice', 'Patryk', [$first->id]);
        }
        if ($second && in_array($second->id, $ids, true)) {
            $items[] = readItem('Send Mark the window invoice', 'Patryk', [$second->id]);
        }

        return $items;
    }, $prompts);

    app(ProjectActionItemExtractor::class)->run($project);

    // Running again with nothing new changes nothing, and asks nothing.
    app(ProjectActionItemExtractor::class)->run($project);
    expect(ProjectActionItem::where('project_id', $project->id)->first()->mentions)->toBe(1)
        ->and($prompts)->toHaveCount(1)
        ->and(ProjectActionItemScan::lastIdFor($project->id, ProjectActionItemScan::KIND_EMAILS))->toBe($first->id);

    $second = summarizedEmail($project, ['Patryk: Send Mark the window invoice'], '2026-10-11 10:00:00');
    $stats = app(ProjectActionItemExtractor::class)->run($project);

    expect($stats['emails'])->toBe(1)
        ->and($prompts[1])->toContain('#'.$second->id.' [')->not->toContain('#'.$first->id.' [')
        ->and(ProjectActionItem::where('project_id', $project->id)->count())->toBe(1)
        ->and(ProjectActionItem::where('project_id', $project->id)->first()->mentions)->toBe(2)
        ->and(ProjectActionItemScan::lastIdFor($project->id, ProjectActionItemScan::KIND_EMAILS))->toBe($second->id);
});

it('still reads an email once it is summarized, even after a later email was already read', function () {
    config(['services.openai.api_key' => 'test-key']);
    ['project' => $project] = actionItemFixture();

    // $early is placed on the project (one candidate) the moment it arrives,
    // but its AI read has not finished yet.
    $early = EmailMessage::factory()->create([
        'project_id' => $project->id, 'sent_at' => '2026-10-08 09:00:00',
        'body_text' => 'Patryk: Send the revised window quote',
        'action_items' => null, 'summarized_at' => null,
    ]);
    $late = summarizedEmail($project, ['Mark: Pick the tile color'], '2026-10-10 09:00:00');

    fakeEmailReading(fn (array $ids) => array_values(array_filter([
        in_array($early->id, $ids, true) ? readItem('Send the revised window quote', 'Patryk', [$early->id]) : null,
        in_array($late->id, $ids, true) ? readItem('Pick the tile color', 'Mark', [$late->id]) : null,
    ])));

    $stats = app(ProjectActionItemExtractor::class)->run($project);

    // $early still blocks the bookmark — a hole behind it must stay open.
    expect($stats['emails'])->toBe(1)
        ->and(ProjectActionItem::where('project_id', $project->id)->count())->toBe(1)
        ->and(ProjectActionItemScan::lastIdFor($project->id, ProjectActionItemScan::KIND_EMAILS))->toBe(0);

    // $early's AI read finishes later.
    $early->forceFill(['action_items' => ['Patryk: Send the revised window quote'], 'summarized_at' => now()])->save();

    $stats = app(ProjectActionItemExtractor::class)->run($project);

    expect(ProjectActionItem::where('project_id', $project->id)->count())->toBe(2)
        ->and(ProjectActionItem::where('title', 'Send the revised window quote')->exists())->toBeTrue()
        // The already-read item from $late is seen again (no bookmark to skip
        // it with) but is not duplicated: its one source stays one source.
        ->and(ProjectActionItem::where('title', 'Pick the tile color')->first()->mentions)->toBe(1)
        ->and(ProjectActionItemScan::lastIdFor($project->id, ProjectActionItemScan::KIND_EMAILS))->toBe($late->id);
});

it('never reads an email\'s action items for a project it was not placed on', function () {
    ['project' => $project] = actionItemFixture();
    $other = Project::factory()->create(['client_id' => $project->client_id, 'address' => '9 Elsewhere']);

    summarizedEmail(null, ['Patryk: Unplaced, the AI could not tell which project'], '2026-10-08 09:00:00');
    summarizedEmail($other, ['Patryk: On the other project, not this one'], '2026-10-08 09:00:00');

    $stats = app(ProjectActionItemExtractor::class)->run($project);

    expect($stats['emails'])->toBe(0)
        ->and(ProjectActionItem::where('project_id', $project->id)->count())->toBe(0)
        ->and(ProjectActionItem::count())->toBe(0);
});

it('shows the model recent email as evidence too, and can close an item on what one reports', function () {
    config(['services.openai.api_key' => 'test-key']);
    ['project' => $project] = actionItemFixture();
    transcribedCall('+17732513666', ['Mark: Pick up the water heater'], '2026-09-29 15:00:00');
    EmailMessage::factory()->create([
        'project_id' => $project->id,
        'sent_at' => '2026-10-05 09:00:00',
        'summary' => 'Mark confirms he already picked up the water heater.',
        'summarized_at' => now(),
    ]);

    $requests = [];
    Http::fake([
        'api.openai.com/v1/embeddings' => Http::response(['data' => []]),
        'api.openai.com/v1/chat/completions' => function ($request) use (&$requests, $project) {
            $name = data_get($request->data(), 'response_format.json_schema.name');
            $requests[$name][] = $request->data();
            $ids = ProjectActionItem::where('project_id', $project->id)->pluck('id', 'title');

            if ($name === 'action_item_closures') {
                return Http::response(['choices' => [['message' => ['content' => json_encode(['decisions' => [
                    ['id' => $ids['Pick up the water heater'], 'action' => 'done', 'reason' => '[email 2026-10-05] Mark confirms he already picked up the water heater.'],
                ]])]]]]);
            }

            return Http::response(['choices' => [['message' => ['content' => json_encode(['groups' => []])]]]]);
        },
    ]);

    $stats = app(ProjectActionItemExtractor::class)->run($project);

    expect($stats['consolidated']['done'])->toBe(1);
    $closing = (string) data_get($requests['action_item_closures'][0], 'messages.1.content');
    expect($closing)->toContain('[email 2026-10-05] Mark confirms he already picked up the water heater.');

    $item = ProjectActionItem::where('project_id', $project->id)->where('title', 'Pick up the water heater')->first();
    expect($item->status)->toBe(ProjectActionItem::STATUS_DONE);
});
