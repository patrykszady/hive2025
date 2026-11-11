<?php

/**
 * Searching a project's Communications card: one word ("Allowances") finds
 * the calls, texts, emails, meetings and planner action items that say it —
 * the search index names the matches (ProjectCommunicationsSearch), the
 * timeline's own queries keep them to this project.
 */

use App\Livewire\Projects\ProjectCommunications;
use App\Models\CallLog;
use App\Models\CallTranscript;
use App\Models\EmailMessage;
use App\Models\MeetingRecording;
use App\Models\ProjectActionItem;
use App\Models\SmsGroupThread;
use App\Models\SmsMessage;
use App\Services\Communications\ProjectCommunicationsSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Scout\EngineManager;
use Livewire\Livewire;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Support/t3-fixtures.php';
require_once __DIR__.'/../../Support/service-call-fixtures.php';

beforeEach(function () {
    config(['scout.driver' => 'collection']);
});

/**
 * One of everything on the project, each mentioning allowances somewhere,
 * plus one of each that doesn't.
 *
 * @return array<string, mixed>
 */
function allowancesWorld(): array
{
    $world = serviceCallWorld();
    $digits = substr(preg_replace('/\D/', '', $world['katie']->cell_phone), -10);
    $project = $world['project'];

    $call = CallLog::withoutEvents(fn () => CallLog::create([
        'direction' => 'incoming', 'from_number' => '+1'.$digits, 'to_number' => '+12247354200', 'status' => 'completed', 'duration_seconds' => 300,
    ]));
    CallTranscript::create([
        'call_log_id' => $call->id,
        'summary' => 'Katie asked about the tile schedule.',
        'text' => str_repeat('We talked about the tile for a long while. ', 20).'Then she asked whether the allowances cover the vanity lights.',
    ]);
    $otherCall = CallLog::withoutEvents(fn () => CallLog::create([
        'direction' => 'incoming', 'from_number' => '+1'.$digits, 'to_number' => '+12247354200', 'status' => 'completed', 'duration_seconds' => 20,
    ]));
    CallTranscript::create(['call_log_id' => $otherCall->id, 'summary' => 'Running late.', 'text' => 'I am running ten minutes late.']);

    $thread = SmsGroupThread::withoutEvents(fn () => SmsGroupThread::create(['client_id' => $world['client']->id, 'participants' => ['+1'.$digits], 'from_number' => '+12247354200']));
    $text = SmsMessage::withoutEvents(fn () => SmsMessage::create(['thread_id' => $thread->id, 'direction' => 'inbound', 'from_number' => '+1'.$digits, 'text' => 'Can you send the Allowances sheet?']));
    SmsMessage::withoutEvents(fn () => SmsMessage::create(['thread_id' => $thread->id, 'direction' => 'inbound', 'from_number' => '+1'.$digits, 'text' => 'Thursday works!']));

    $email = EmailMessage::factory()->create([
        'belongs_to_vendor_id' => $world['vendor']->id,
        'project_id' => $project->id,
        'subject' => 'Lighting',
        'summary' => 'Katie picked the lights.',
        'body_text' => 'Hi — these are over our allowances by $200, is that ok?',
    ]);
    EmailMessage::factory()->create([
        'belongs_to_vendor_id' => $world['vendor']->id,
        'project_id' => $project->id,
        'subject' => 'Grout',
        'summary' => 'Cracked grout.',
        'body_text' => 'The grout cracked.',
    ]);

    $meeting = MeetingRecording::factory()->create([
        'belongs_to_vendor_id' => $world['vendor']->id,
        'project_id' => $project->id,
        'status' => MeetingRecording::STATUS_READY,
        'summary' => 'Walkthrough.',
        'action_items' => ['Send Katie the updated allowances'],
        'transcript' => 'We walked the basement.',
    ]);

    $actionItem = ProjectActionItem::create([
        'project_id' => $project->id,
        'belongs_to_vendor_id' => $world['vendor']->id,
        'title' => 'Review allowances with Katie',
        'status' => ProjectActionItem::STATUS_OPEN,
        'mentions' => 1,
        'first_mentioned_at' => now(),
        'last_mentioned_at' => now(),
        'sources' => [['kind' => 'email', 'id' => $email->id, 'at' => now()->toIso8601String(), 'excerpt' => 'over our allowances by $200']],
        'fingerprint' => sha1('review allowances'),
    ]);
    ProjectActionItem::create([
        'project_id' => $project->id,
        'belongs_to_vendor_id' => $world['vendor']->id,
        'title' => 'Order the vanity',
        'status' => ProjectActionItem::STATUS_OPEN,
        'mentions' => 1,
        'first_mentioned_at' => now(),
        'last_mentioned_at' => now(),
        'sources' => [['kind' => 'call', 'id' => $otherCall->id, 'at' => now()->toIso8601String(), 'excerpt' => 'order it']],
        'fingerprint' => sha1('order the vanity'),
    ]);

    return $world + compact('call', 'text', 'email', 'meeting', 'actionItem');
}

it('finds a word across calls, texts, emails, meetings and action items', function () {
    $world = allowancesWorld();

    $items = app(ProjectCommunicationsSearch::class)->search($world['project'], 'Allowances');

    expect(collect($items)->map(fn ($item) => $item['kind'].':'.$item['id'])->sort()->values()->all())->toBe(collect([
        "action_item:{$world['actionItem']->id}",
        "call:{$world['call']->id}",
        "email:{$world['email']->id}",
        "meeting:{$world['meeting']->id}",
        "sms:{$world['text']->id}",
    ])->sort()->values()->all());

    $byKind = collect($items)->keyBy('kind');

    // The call's row shows its summary; the excerpt is read from the transcript.
    expect($byKind['call']['match'])->toContain('allowances cover the vanity lights')->toStartWith('…')
        ->and($byKind['meeting']['match'])->toBe('Send Katie the updated allowances')
        ->and($byKind['email']['match'])->toContain('over our allowances')
        ->and($byKind['action_item']['who'])->toBe('Review allowances with Katie')
        ->and($byKind['action_item']['source_kind'])->toBe('email');
});

it('keeps a search to the project: another client\'s matches stay out', function () {
    $world = allowancesWorld();

    $strangerThread = SmsGroupThread::withoutEvents(fn () => SmsGroupThread::create(['participants' => ['+13125550199'], 'from_number' => '+12247354200']));
    SmsMessage::withoutEvents(fn () => SmsMessage::create(['thread_id' => $strangerThread->id, 'direction' => 'inbound', 'from_number' => '+13125550199', 'text' => 'What are my allowances?']));
    $strangerCall = CallLog::withoutEvents(fn () => CallLog::create([
        'direction' => 'incoming', 'from_number' => '+13125550199', 'to_number' => '+12247354200', 'status' => 'completed', 'notes' => 'Asked about allowances',
    ]));
    EmailMessage::factory()->create(['belongs_to_vendor_id' => $world['vendor']->id, 'project_id' => $world['cancelled']->id ?? null, 'subject' => 'Allowances']);
    ProjectActionItem::create([
        'project_id' => null, 'belongs_to_vendor_id' => $world['vendor']->id, 'title' => 'Allowances for another job', 'status' => ProjectActionItem::STATUS_OPEN,
        'mentions' => 1, 'first_mentioned_at' => now(), 'last_mentioned_at' => now(), 'sources' => [], 'fingerprint' => sha1('other'),
    ]);

    $items = app(ProjectCommunicationsSearch::class)->search($world['project'], 'allowances');

    expect($items)->toHaveCount(5)
        ->and(collect($items)->where('kind', 'call')->pluck('id')->all())->not->toContain($strangerCall->id);
});

it('falls back to a plain match when the search index cannot answer', function () {
    $world = allowancesWorld();

    app(EngineManager::class)->extend('broken', fn () => throw new RuntimeException('index down'));
    config(['scout.driver' => 'broken']);

    $items = app(ProjectCommunicationsSearch::class)->search($world['project'], 'allowances');

    expect(collect($items)->pluck('kind')->sort()->values()->all())->toBe(['action_item', 'call', 'email', 'sms']);
});

it('searches from the Communications card and marks the word', function () {
    $world = allowancesWorld();

    $component = Livewire::actingAs($world['admin'])
        ->test(ProjectCommunications::class, ['project' => $world['project']])
        ->assertDontSee('Action items')
        ->set('search', 'Allowances')
        ->assertCount('items', 5)
        ->assertSet('nextBefore', null)
        ->assertSee('5 results')
        ->assertSee('Action items')
        ->assertSee('Review allowances with Katie')
        ->assertSeeHtml('allowances</mark>')
        ->assertDontSee('Thursday works!');

    $component->set('kind', 'action_item')->assertCount('items', 1);
    $component->set('kind', 'call')->assertCount('items', 1);

    // An action item said in an email opens that email in place.
    $component->call('openActionItemSource', 'email', $world['email']->id)
        ->assertSet('showEmailModal', true)
        ->assertSet('openEmailId', $world['email']->id);

    // Clearing the search is the plain timeline again, off the search-only tab.
    $component->set('kind', 'action_item')->set('search', '')
        ->assertSet('kind', 'all')
        ->assertCount('items', ProjectCommunications::FIRST_PAGE)
        ->assertSee('Thursday works!');
});

it('says so when nothing matches', function () {
    $world = allowancesWorld();

    Livewire::actingAs($world['admin'])
        ->test(ProjectCommunications::class, ['project' => $world['project']])
        ->set('search', 'zebra')
        ->assertCount('items', 0)
        ->assertSee('Nothing matches');
});

it('escapes what it marks', function () {
    $world = allowancesWorld();

    $html = (string) Livewire::actingAs($world['admin'])
        ->test(ProjectCommunications::class, ['project' => $world['project']])
        ->set('search', 'allow')
        ->instance()
        ->highlight('<b>allowances</b> & more');

    expect($html)->toContain('&lt;b&gt;')->toContain('>allow</mark>ances')->not->toContain('<b>');
});

it('opens a found call in place with the word marked in its transcript', function () {
    $world = allowancesWorld();

    Livewire::actingAs($world['admin'])
        ->test(ProjectCommunications::class, ['project' => $world['project']])
        ->set('search', 'Allowances')
        ->call('selectCall', $world['call']->id)
        ->assertSet('showCallModal', true)
        ->assertSeeHtml('data-call-modal')
        ->assertSee('Transcript')
        ->assertSeeHtml('allowances</mark> cover the vanity lights')
        ->assertSee('Open in Messages');
});

it('opens a found text inside its conversation', function () {
    $world = allowancesWorld();

    Livewire::actingAs($world['admin'])
        ->test(ProjectCommunications::class, ['project' => $world['project']])
        ->set('search', 'Allowances')
        ->call('selectText', $world['text']->id)
        ->assertSet('showTextModal', true)
        ->assertSeeHtml('data-selected-text')
        ->assertSeeHtml('Allowances</mark> sheet?')
        // The message after it in the thread, for context.
        ->assertSee('Thursday works!');
});

it('opens an action item said on a call at that call', function () {
    $world = allowancesWorld();

    Livewire::actingAs($world['admin'])
        ->test(ProjectCommunications::class, ['project' => $world['project']])
        ->call('openActionItemSource', 'call', $world['call']->id)
        ->assertSet('openCallId', $world['call']->id);
});

it('marks the word in a found email and meeting', function () {
    $world = allowancesWorld();

    Livewire::actingAs($world['admin'])
        ->test(ProjectCommunications::class, ['project' => $world['project']])
        ->set('search', 'Allowances')
        ->call('selectEmail', $world['email']->id)
        ->assertSeeHtml('over our <mark')
        ->call('selectMeeting', $world['meeting']->id)
        ->assertSeeHtml('Send Katie the updated <mark');
});

it('will not open another client\'s call or text', function () {
    $world = allowancesWorld();

    $thread = SmsGroupThread::withoutEvents(fn () => SmsGroupThread::create(['participants' => ['+13125550199'], 'from_number' => '+12247354200']));
    $text = SmsMessage::withoutEvents(fn () => SmsMessage::create(['thread_id' => $thread->id, 'direction' => 'inbound', 'from_number' => '+13125550199', 'text' => 'My allowances?']));
    $call = CallLog::withoutEvents(fn () => CallLog::create(['direction' => 'incoming', 'from_number' => '+13125550199', 'to_number' => '+12247354200', 'status' => 'completed']));

    Livewire::actingAs($world['admin'])
        ->test(ProjectCommunications::class, ['project' => $world['project']])
        ->call('selectCall', $call->id)->assertSet('showCallModal', false)
        ->call('selectText', $text->id)->assertSet('showTextModal', false);
});
