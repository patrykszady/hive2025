<?php

/**
 * SummarizeEmailMessage queues a re-read of the project's action items
 * (ExtractProjectActionItems) once an email it just read is placed on a
 * project and carries any — delayed ~10 minutes so several emails that
 * land close together are read in one pass (2026-10-19).
 */

use App\Jobs\ExtractProjectActionItems;
use App\Jobs\SummarizeEmailMessage;
use App\Models\EmailMessage;
use App\Models\ProjectActionItem;
use App\Services\ActionItems\ProjectActionItemExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Support/t3-fixtures.php';
require_once __DIR__.'/../../Support/service-call-fixtures.php';

beforeEach(function () {
    config(['services.openai.api_key' => 'test-key']);
});

/** @param  array<string, mixed>  $overrides */
function fakeEmailSummary(array $overrides = [], int $status = 200): void
{
    Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => json_encode(array_merge([
        'summary' => 'A short summary.',
        'action_items' => [],
        'topics' => [],
        'next_steps' => [],
        'sentiment' => 'neutral',
        'intent' => 'ask',
        'project_id' => null,
    ], $overrides))]]]], $status)]);
}

it('queues a re-read of the project\'s action items when a stakeholder email brings new ones in', function () {
    Queue::fake();
    $world = serviceCallWorld('active'); // status_code 6: Active, a planner status
    $email = EmailMessage::factory()->create([
        'belongs_to_vendor_id' => $world['vendor']->id,
        'client_id' => $world['client']->id,
        'candidate_project_ids' => [$world['project']->id],
    ]);
    fakeEmailSummary(['action_items' => ['Patryk: Send the hardware options'], 'project_id' => $world['project']->id]);

    (new SummarizeEmailMessage($email->id))->handle();

    Queue::assertPushed(ExtractProjectActionItems::class, function (ExtractProjectActionItems $job) use ($world) {
        return $job->projectId === $world['project']->id
            && $job->delay !== null
            && abs(Carbon::instance($job->delay)->diffInMinutes(now())) >= 9;
    });
});

it('does not queue a re-read when the email carries no action items', function () {
    Queue::fake();
    $world = serviceCallWorld('active');
    $email = EmailMessage::factory()->create([
        'belongs_to_vendor_id' => $world['vendor']->id,
        'client_id' => $world['client']->id,
        'candidate_project_ids' => [$world['project']->id],
    ]);
    fakeEmailSummary(['action_items' => [], 'project_id' => $world['project']->id]);

    (new SummarizeEmailMessage($email->id))->handle();

    Queue::assertNotPushed(ExtractProjectActionItems::class);
});

it('does not queue a re-read when the AI could not place the email on a project', function () {
    Queue::fake();
    $world = serviceCallWorld('active');
    $email = EmailMessage::factory()->create([
        'belongs_to_vendor_id' => $world['vendor']->id,
        'client_id' => $world['client']->id,
        'candidate_project_ids' => [$world['project']->id],
    ]);
    // The model declines to pick a project: project_id stays null.
    fakeEmailSummary(['action_items' => ['Patryk: Do something'], 'project_id' => null]);

    (new SummarizeEmailMessage($email->id))->handle();

    Queue::assertNotPushed(ExtractProjectActionItems::class);
});

it('runs the extractor for a planner-active project once the delay elapses', function () {
    $world = serviceCallWorld('active'); // status_code 6
    EmailMessage::factory()->create([
        'belongs_to_vendor_id' => $world['vendor']->id,
        'client_id' => $world['client']->id,
        'project_id' => $world['project']->id,
        'action_items' => ['Patryk: Send the revised quote'],
        'summarized_at' => now(),
    ]);

    (new ExtractProjectActionItems($world['project']->id))->handle(app(ProjectActionItemExtractor::class));

    expect(ProjectActionItem::where('project_id', $world['project']->id)->where('title', 'Send the revised quote')->exists())->toBeTrue();
});

it('does nothing for a project the planner is not showing', function () {
    $world = serviceCallWorld(); // default 'complete' -> status_code 7, not a planner status
    EmailMessage::factory()->create([
        'belongs_to_vendor_id' => $world['vendor']->id,
        'client_id' => $world['client']->id,
        'project_id' => $world['project']->id,
        'action_items' => ['Patryk: Send the revised quote'],
        'summarized_at' => now(),
    ]);

    (new ExtractProjectActionItems($world['project']->id))->handle(app(ProjectActionItemExtractor::class));

    expect(ProjectActionItem::where('project_id', $world['project']->id)->exists())->toBeFalse();
});
