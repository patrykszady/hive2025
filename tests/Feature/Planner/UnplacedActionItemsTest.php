<?php

use App\Jobs\ExtractUnplacedActionItems;
use App\Models\CallLog;
use App\Models\CallTranscript;
use App\Models\ProjectActionItem;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/../../Support/planner-action-fixtures.php';

uses(RefreshDatabase::class);

// =========================================================================
// "Needs a project" (2026-11-05). Jenn (J. Peterson Design) called about
// "the Abby project" — a designer talks about many jobs, and when the model
// cannot name the one a call or email is about, what it asks for is read
// anyway, waits in the planner with no project, and a person places it.
// =========================================================================

/** A summarized call on no project, from the company's own line. */
function unplacedCall(int $vendorId, string $summary, array $nextSteps = []): CallLog
{
    $call = CallLog::create(['call_control_id' => 'cc-'.uniqid(), 'direction' => 'incoming', 'from_number' => '+18475550000', 'to_number' => '+12247354200', 'status' => 'completed', 'vendor_id' => $vendorId, 'created_at' => '2026-11-05 15:00:00']);
    CallTranscript::create(['call_log_id' => $call->id, 'engine' => 'assemblyai', 'language' => 'en', 'status' => CallTranscript::STATUS_READY, 'text' => 'Speaker A: '.$summary, 'summary' => $summary, 'next_steps' => $nextSteps, 'topics' => []]);

    return $call;
}

it('reads a call the model could not place into items with no project', function () {
    config(['services.openai.api_key' => 'test-key']);
    ['vendor' => $vendor] = actionPlannerFixture();
    $call = unplacedCall($vendor->id, 'Jennifer and Patryk discussed the Abby kitchen; the ABS delivery moved to Monday the 16th.', ['ABS delivery arrives Monday the 16th']);

    $prompts = [];
    Http::fake(['api.openai.com/v1/chat/completions' => function ($request) use (&$prompts, $call) {
        $prompts[] = (string) data_get($request->data(), 'messages.0.content');

        return Http::response(['choices' => [['message' => ['content' => json_encode(['items' => [
            ['title' => 'ABS Delivery', 'owner' => 'ABS', 'due_date' => '2026-11-16', 'start_time' => null, 'end_time' => null, 'kind' => 'visit', 'message_ids' => [$call->id]],
        ]])]]]]);
    }]);

    (new ExtractUnplacedActionItems('call', $call->id))->handle(app(\App\Services\ActionItems\ProjectActionItemExtractor::class));
    (new ExtractUnplacedActionItems('call', $call->id))->handle(app(\App\Services\ActionItems\ProjectActionItemExtractor::class));

    // The prompt says the job is unknown; a second read updates, never doubles.
    expect($prompts)->toHaveCount(2)
        ->and($prompts[0])->toContain('which one is not yet known');

    $items = ProjectActionItem::query()->unplaced($vendor->id)->get();
    expect($items)->toHaveCount(1)
        ->and($items[0]->project_id)->toBeNull()
        ->and($items[0]->title)->toBe('ABS Delivery')
        ->and($items[0]->owner)->toBe('ABS')
        ->and($items[0]->due_on->toDateString())->toBe('2026-11-16')
        ->and($items[0]->isVisit())->toBeTrue()
        ->and($items[0]->sources[0]['kind'])->toBe('call')
        ->and($items[0]->sources[0]['id'])->toBe($call->id);
});

it('skips a call that is already on a project, or has no summary yet', function () {
    config(['services.openai.api_key' => 'test-key']);
    ['vendor' => $vendor, 'project' => $project] = actionPlannerFixture();
    Http::fake();

    $placed = unplacedCall($vendor->id, 'Placed already.');
    $placed->forceFill(['project_id' => $project->id])->saveQuietly();
    $silent = CallLog::create(['call_control_id' => 'cc-silent', 'direction' => 'incoming', 'from_number' => '+18475550001', 'to_number' => '+12247354200', 'status' => 'completed', 'vendor_id' => $vendor->id]);

    $extractor = app(\App\Services\ActionItems\ProjectActionItemExtractor::class);
    (new ExtractUnplacedActionItems('call', $placed->id))->handle($extractor);
    (new ExtractUnplacedActionItems('call', $silent->id))->handle($extractor);

    Http::assertNothingSent();
    expect(ProjectActionItem::query()->whereNull('project_id')->count())->toBe(0);
});

it('shows the unplaced items in the planner as a "Needs a project" group, for this company only', function () {
    ['vendor' => $vendor, 'project' => $project] = actionPlannerFixture();
    $onProject = actionItemRow($project, 'Send Mark the latest window invoice');
    $delivery = ProjectActionItem::create(['project_id' => null, 'belongs_to_vendor_id' => $vendor->id, 'title' => 'ABS Delivery', 'owner' => 'ABS', 'status' => ProjectActionItem::STATUS_OPEN, 'mentions' => 1, 'first_mentioned_at' => now()->subHour(), 'last_mentioned_at' => now()->subHour(), 'sources' => [['kind' => 'call', 'id' => 9, 'at' => now()->subHour()->toIso8601String(), 'excerpt' => 'ABS delivery moved', 'visit' => true]], 'fingerprint' => ProjectActionItem::fingerprintFor('ABS Delivery'), 'due_on' => '2026-11-16']);
    $old = ProjectActionItem::create(['project_id' => null, 'belongs_to_vendor_id' => $vendor->id, 'title' => 'Confirm the tile order', 'owner' => 'Jenn', 'status' => ProjectActionItem::STATUS_OPEN, 'mentions' => 1, 'first_mentioned_at' => now()->subDays(20), 'last_mentioned_at' => now()->subDays(20), 'sources' => [], 'fingerprint' => ProjectActionItem::fingerprintFor('Confirm the tile order')]);
    ProjectActionItem::create(['project_id' => null, 'belongs_to_vendor_id' => $vendor->id, 'title' => 'Dismissed one', 'status' => ProjectActionItem::STATUS_DISMISSED, 'mentions' => 1, 'first_mentioned_at' => now(), 'last_mentioned_at' => now(), 'sources' => [], 'fingerprint' => ProjectActionItem::fingerprintFor('Dismissed one')]);
    ProjectActionItem::create(['project_id' => null, 'belongs_to_vendor_id' => $vendor->id + 1000, 'title' => 'Another company\'s item', 'status' => ProjectActionItem::STATUS_OPEN, 'mentions' => 1, 'first_mentioned_at' => now(), 'last_mentioned_at' => now(), 'sources' => [], 'fingerprint' => ProjectActionItem::fingerprintFor('Another company\'s item')]);

    $planner = plannerWithProjects([$project]);

    expect($planner->unplacedActionItems()->pluck('id')->all())->toBe([$delivery->id, $old->id])
        ->and($planner->actionItemCounts())->toBe([$project->id => 1]);

    $byProject = $planner->actionItemsByProject();
    expect(array_keys($byProject))->toBe([$project->id, 0])
        ->and($byProject[0]['title'])->toBe('Needs a project')
        ->and($byProject[0]['recent'])->toBe(2)
        ->and($byProject[0]['older'])->toBe(0)
        ->and(array_keys($byProject[0]['groups']))->toBe(['ABS', 'Jenn'])
        ->and($byProject[$project->id]['groups']['Patryk']->pluck('id')->all())->toBe([$onProject->id]);
});

it('places an item on the job a person picks, takes the call with it, and makes the task for a dated delivery', function () {
    ['vendor' => $vendor, 'project' => $project] = actionPlannerFixture();
    $call = unplacedCall($vendor->id, 'ABS delivery moved to Monday the 16th.');
    $item = ProjectActionItem::create(['project_id' => null, 'belongs_to_vendor_id' => $vendor->id, 'title' => 'ABS Delivery', 'owner' => 'ABS', 'status' => ProjectActionItem::STATUS_OPEN, 'mentions' => 1, 'first_mentioned_at' => '2026-11-05 15:00:00', 'last_mentioned_at' => '2026-11-05 15:00:00', 'due_on' => now()->addDays(11)->toDateString(), 'start_time' => '12:00', 'end_time' => '17:00', 'sources' => [['kind' => 'call', 'id' => $call->id, 'thread_id' => null, 'at' => '2026-11-05T15:00:00+00:00', 'excerpt' => 'ABS delivery moved', 'visit' => true]], 'fingerprint' => ProjectActionItem::fingerprintFor('ABS Delivery')]);

    $planner = plannerWithProjects([$project]);
    $planner->placeActionItem($item->id, $project->id);

    $item->refresh();
    expect($item->project_id)->toBe($project->id)
        ->and($call->fresh()->project_id)->toBe($project->id)
        ->and($item->status)->toBe(ProjectActionItem::STATUS_TASK)
        ->and($planner->unplacedActionItems())->toHaveCount(0);

    $task = Task::withoutGlobalScopes()->where('project_id', $project->id)->where('title', 'ABS Delivery')->first();
    expect($task)->not->toBeNull()
        ->and($task->start_date->toDateString())->toBe($day = now()->addDays(11)->toDateString())
        ->and(data_get($task->options, "time_settings.{$day}.use_time"))->toBeTrue()
        ->and(data_get($task->options, "time_settings.{$day}.start_time"))->toBe('12:00')
        ->and(data_get($task->options, 'scheduled_from.action_item_id'))->toBe($item->id);
});

it('places a to-do on the job without making a task, and ignores a project that is not the company\'s', function () {
    ['vendor' => $vendor, 'project' => $project] = actionPlannerFixture();
    $todo = ProjectActionItem::create(['project_id' => null, 'belongs_to_vendor_id' => $vendor->id, 'title' => 'Confirm the tile order', 'owner' => 'Jenn', 'status' => ProjectActionItem::STATUS_OPEN, 'mentions' => 1, 'first_mentioned_at' => now(), 'last_mentioned_at' => now(), 'sources' => [['kind' => 'call', 'id' => 0, 'at' => now()->toIso8601String(), 'excerpt' => 'tile']], 'fingerprint' => ProjectActionItem::fingerprintFor('Confirm the tile order')]);
    $stranger = \App\Models\Project::factory()->create(['address' => '1 Far Away', 'client_id' => $project->client_id]);

    $planner = plannerWithProjects([$project]);
    $planner->placeActionItem($todo->id, $stranger->id);
    expect($todo->fresh()->project_id)->toBeNull();

    $planner->placeActionItem($todo->id, $project->id);
    expect($todo->fresh()->project_id)->toBe($project->id)
        ->and($todo->fresh()->status)->toBe(ProjectActionItem::STATUS_OPEN)
        ->and(Task::withoutGlobalScopes()->where('project_id', $project->id)->count())->toBe(0);
});
