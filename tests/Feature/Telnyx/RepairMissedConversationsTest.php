<?php

use App\Jobs\StoreCallRecording;
use App\Models\CallLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

// =========================================================================
// Calls a team member took and hung up first were filed as missed with
// their recording thrown away (14 calls 2026-09-25 to 10-16): the command
// files them as answered and fetches the recording from Telnyx again.
// =========================================================================

beforeEach(function () {
    config(['scout.driver' => null, 'services.telnyx.api_key' => 'test-api-key']);
    Queue::fake();
});

function missedConversation(array $attributes = []): CallLog
{
    return CallLog::factory()->create(array_merge([
        'direction' => 'incoming',
        'status' => CallLog::STATUS_MISSED,
        'call_control_id' => 'v3:caller-'.uniqid(),
        'caller_name' => 'Erin Abbey',
        'forwarded_to' => '2249993880',
        'answered_at' => '2026-10-16 21:26:59',
        'ended_at' => '2026-10-16 21:32:10',
        'created_at' => '2026-10-16 21:26:58',
        'recording_url' => null,
        'duration_seconds' => null,
        'metadata' => ['conference_id' => 'conf-1', 'joined_admin_ids' => []],
    ], $attributes));
}

it('files a conversation the team member hung up first as answered, with its recording from Telnyx', function () {
    $call = missedConversation();
    // A leg that never joined: its rollback cleared forwarded_to and the conference.
    $dropped = missedConversation(['forwarded_to' => null, 'metadata' => ['joined_admin_ids' => []]]);

    Http::fake(['api.telnyx.com/v2/recordings*' => Http::response(['data' => [
        ['id' => 'rec-other', 'call_control_id' => 'v3:someone-else', 'download_urls' => ['wav' => 'https://s3.example/other.wav']],
        ['id' => 'rec-1', 'call_control_id' => $call->call_control_id, 'download_urls' => ['wav' => 'https://s3.example/erin.wav']],
    ]])]);

    $this->artisan('calls:repair-missed-conversations', ['--dry-run' => true])
        ->expectsOutputToContain('Would file 1 call(s) as answered.')
        ->assertSuccessful();
    expect($call->fresh()->status)->toBe(CallLog::STATUS_MISSED);

    $this->artisan('calls:repair-missed-conversations')->assertSuccessful();

    $call->refresh();
    expect($call->status)->toBe(CallLog::STATUS_COMPLETED)
        ->and($call->duration_seconds)->toBe(311)
        ->and($call->recording_url)->toBe('https://s3.example/erin.wav')
        ->and($call->recording_telnyx_id)->toBe('rec-1')
        ->and($dropped->fresh()->status)->toBe(CallLog::STATUS_MISSED);
    Queue::assertPushed(StoreCallRecording::class, fn ($job) => $job->callLogId === $call->id);
});
