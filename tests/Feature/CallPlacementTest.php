<?php

use App\Jobs\SummarizeCallTranscript;
use App\Models\CallLog;
use App\Models\CallTranscript;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/../Support/t3-fixtures.php';
require_once __DIR__.'/../Support/service-call-fixtures.php';

uses(RefreshDatabase::class);

/**
 * A call is placed on the job it is about by its transcript, the way an
 * email is placed by its text — Jenn (J. Peterson Design) called about "the
 * Abby project" on 2026-11-05, was no client, and the call sat on no
 * project where the planner never read it.
 */
it('places a call on the project it is about, and queues the planner read', function () {
    // Jenn (J. Peterson Design) called about "the Abby project" (2026-11-05):
    // no client, so the call sat on no project and the planner never read
    // it. The company's planner-status jobs are listed; the model picks.
    config()->set('call_recording.summarization.driver', 'openai');
    config()->set('services.openai.api_key', 'test-key');
    \Illuminate\Support\Facades\Queue::fake();

    $w = serviceCallWorld('active');
    $other = \App\Models\Client::factory()->create();
    $other->vendors()->attach($w['vendor']->id);
    $brodson = sec3_makeProject($w['vendor'], $other, ['project_name' => 'Home Renovation', 'address' => '3154 Violet Ln', 'city' => 'Northbrook']);
    $brodson->vendors()->attach($w['vendor']->id, ['client_id' => $other->id]);
    \App\Models\ProjectStatus::create(['project_id' => $brodson->id, 'belongs_to_vendor_id' => $w['vendor']->id, 'status_code' => 6, 'start_date' => now()->toDateString()]);

    $callLog = CallLog::create(['call_control_id' => 'cc-place', 'direction' => 'incoming', 'from_number' => '+18475550000', 'to_number' => '+12247354200', 'status' => 'completed', 'vendor_id' => $w['vendor']->id]);
    $transcript = CallTranscript::create(['call_log_id' => $callLog->id, 'engine' => 'assemblyai', 'language' => 'en', 'status' => CallTranscript::STATUS_READY,
        'text' => 'Speaker A: Have you heard from ABS about the delivery for the Abby kitchen? Speaker B: It is rescheduled for Monday the 16th.']);

    $seen = [];
    Http::fake(['api.openai.com/*' => function ($request) use (&$seen, $w) {
        $seen[] = (string) data_get($request->data(), 'messages.1.content');

        return Http::response(['choices' => [['message' => ['content' => json_encode([
            'summary' => 'Jennifer and Patryk discussed the Abby kitchen; the ABS delivery moved to Monday the 16th.',
            'action_items' => [], 'topics' => ['ABS delivery'], 'next_steps' => ['ABS delivery arrives Monday the 16th'],
            'sentiment' => 'positive', 'caller_intent' => 'status check', 'recording_has_no_message' => false,
            'about_a_project' => true, 'project_id' => $w['project']->id,
        ])]]]]);
    }]);

    (new SummarizeCallTranscript($transcript->id))->handle();

    expect($seen[0])->toContain("Projects:\n")
        ->toContain('- id '.$w['project']->id.': ')
        ->toContain('- id '.$brodson->id.': Home Renovation, 3154 Violet Ln, Northbrook')
        ->and($callLog->fresh()->project_id)->toBe($w['project']->id);
    \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\ExtractProjectActionItems::class, fn ($job) => $job->projectId === $w['project']->id);
});

it('queues the unplaced read when the call is about a job the model could not name', function () {
    config()->set('call_recording.summarization.driver', 'openai');
    config()->set('services.openai.api_key', 'test-key');
    \Illuminate\Support\Facades\Queue::fake();
    $w = serviceCallWorld('active');

    $callLog = CallLog::create(['call_control_id' => 'cc-unclear', 'direction' => 'incoming', 'from_number' => '+18475550002', 'to_number' => '+12247354200', 'status' => 'completed', 'vendor_id' => $w['vendor']->id]);
    $transcript = CallTranscript::create(['call_log_id' => $callLog->id, 'engine' => 'assemblyai', 'language' => 'en', 'status' => CallTranscript::STATUS_READY, 'text' => 'Speaker A: The ABS delivery for the kitchen is Monday the 16th.']);
    Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => json_encode([
        'summary' => 'The ABS delivery moved to Monday the 16th.', 'action_items' => [], 'topics' => ['ABS delivery'], 'next_steps' => ['ABS delivery arrives Monday the 16th'], 'sentiment' => 'neutral', 'caller_intent' => 'status update',
        'recording_has_no_message' => false, 'about_a_project' => true, 'project_id' => null,
    ])]]]])]);

    (new SummarizeCallTranscript($transcript->id))->handle();

    expect($callLog->fresh()->project_id)->toBeNull();
    \Illuminate\Support\Facades\Queue::assertNotPushed(\App\Jobs\ExtractProjectActionItems::class);
    \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\ExtractUnplacedActionItems::class, fn ($job) => $job->kind === 'call' && $job->id === $callLog->id);
});

it('leaves a call that is not about a job on no project', function () {
    config()->set('call_recording.summarization.driver', 'openai');
    config()->set('services.openai.api_key', 'test-key');
    \Illuminate\Support\Facades\Queue::fake();
    $w = serviceCallWorld('active');

    $callLog = CallLog::create(['call_control_id' => 'cc-sales', 'direction' => 'incoming', 'from_number' => '+18475550001', 'to_number' => '+12247354200', 'status' => 'completed', 'vendor_id' => $w['vendor']->id]);
    $transcript = CallTranscript::create(['call_log_id' => $callLog->id, 'engine' => 'assemblyai', 'language' => 'en', 'status' => CallTranscript::STATUS_READY, 'text' => 'Speaker A: Would you like to hear about our new business credit card?']);
    Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => json_encode([
        'summary' => 'A sales call.', 'action_items' => [], 'topics' => [], 'next_steps' => [], 'sentiment' => 'neutral', 'caller_intent' => 'sell a credit card',
        'recording_has_no_message' => false, 'about_a_project' => false, 'project_id' => $w['project']->id,
    ])]]]])]);

    (new SummarizeCallTranscript($transcript->id))->handle();

    expect($callLog->fresh()->project_id)->toBeNull();
    \Illuminate\Support\Facades\Queue::assertNotPushed(\App\Jobs\ExtractProjectActionItems::class);
    \Illuminate\Support\Facades\Queue::assertNotPushed(\App\Jobs\ExtractUnplacedActionItems::class);
});
