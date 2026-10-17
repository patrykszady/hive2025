<?php

use App\Models\BlockedCaller;
use App\Models\CallLog;
use App\Models\CallTranscript;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

// =========================================================================
// The app's Calls tab mirrors the web calls dashboard (2026-10-17): the same
// list, filters, grouping, detail (recording, AI summary, transcript) and
// blocking, through CallHistory.
// =========================================================================

beforeEach(function () {
    config(['scout.driver' => null, 'services.telnyx.numbers' => ['+12247354200']]);
    $this->company = Vendor::query()->find(1) ?? Vendor::factory()->create(['id' => 1, 'business_name' => 'GS Construction']);
    $this->member = User::factory()->create(['first_name' => 'Patryk', 'cell_phone' => '2249993880', 'primary_vendor_id' => 1]);
    Sanctum::actingAs($this->member, ['app']);
});

function appCall(array $attributes = []): CallLog
{
    return CallLog::factory()->create(array_merge([
        'vendor_id' => 1,
        'direction' => 'incoming',
        'from_number' => '+17735550111',
        'to_number' => '+12247354200',
        'status' => CallLog::STATUS_COMPLETED,
        'caller_name' => 'Erin Abbey',
    ], $attributes));
}

it('lists calls newest first with repeats grouped, filters, and only the company\'s calls', function () {
    appCall(['status' => CallLog::STATUS_COMPLETED, 'created_at' => now()->subHours(3)]);
    appCall(['status' => CallLog::STATUS_MISSED, 'duration_seconds' => 0, 'created_at' => now()->subHours(2)]);
    appCall(['status' => CallLog::STATUS_MISSED, 'duration_seconds' => 0, 'created_at' => now()->subHour()]);
    appCall(['from_number' => '+13125550199', 'status' => CallLog::STATUS_BLOCKED, 'created_at' => now()]);
    appCall(['vendor_id' => Vendor::factory()->create()->id, 'from_number' => '+19995550000']);

    $all = $this->getJson('/api/app/v1/calls')->assertOk();
    expect(collect($all->json('data'))->map(fn ($row) => [$row['status'], $row['count'], $row['other_party']['phone']])->all())
        ->toBe([['missed', 2, '+17735550111'], ['completed', 1, '+17735550111']])
        ->and($all->json('data.0.missed'))->toBeTrue()
        ->and($all->json('data.0.other_party.name'))->toBe('Erin Abbey');

    $this->getJson('/api/app/v1/calls?filter=missed')->assertJsonCount(1, 'data')->assertJsonPath('data.0.count', 2);
    $this->getJson('/api/app/v1/calls?filter=blocked')->assertJsonCount(1, 'data')->assertJsonPath('data.0.blocked', true);
});

it('shows a call like the web detail panel: playable recording, AI summary and transcript', function () {
    Storage::fake('local');
    Storage::disk('local')->put('public/call-recordings/x.wav', 'RIFF-audio');
    $call = appCall(['recording_disk' => 'local', 'recording_path' => 'public/call-recordings/x.wav', 'recording_url' => '/storage/call-recordings/x.wav', 'duration_seconds' => 120]);
    CallTranscript::query()->create([
        'call_log_id' => $call->id,
        'status' => CallTranscript::STATUS_READY,
        'text' => 'Hi, can you come look at the bathroom tile?',
        'segments' => [['text' => 'Hi, can you come look at the bathroom tile?', 'speaker' => 'Speaker B', 'start' => 0, 'end' => 3]],
        'summary' => 'Erin wants a tile estimate.',
        'sentiment' => 'positive',
        'caller_intent' => 'Book an estimate',
        'action_items' => ['Schedule a visit'],
        'topics' => ['tile'],
        'next_steps' => ['Call back Monday'],
    ]);

    $response = $this->getJson("/api/app/v1/calls/{$call->id}")->assertOk();

    expect($response->json('data.transcript.summary'))->toBe('Erin wants a tile estimate.')
        ->and($response->json('data.transcript.action_items'))->toBe(['Schedule a visit'])
        ->and($response->json('data.transcript.segments.0.text'))->toContain('bathroom tile')
        ->and($response->json('data.recording_url'))->toContain('/calls/'.$call->id.'/recording/signed');

    // The signed link plays without a session; the bare path does not.
    auth()->forgetGuards();
    $this->get($response->json('data.recording_url'))->assertOk();
    $this->get('/calls/'.$call->id.'/recording/signed')->assertForbidden();
});

it('keeps another company\'s call out of reach, and says when a call does not exist', function () {
    $theirs = appCall(['vendor_id' => Vendor::factory()->create()->id]);

    $this->getJson("/api/app/v1/calls/{$theirs->id}")->assertForbidden();
    $this->getJson('/api/app/v1/calls/999999')->assertNotFound();
});

it('blocks a caller for the company and unblocks them again, like the web dashboard', function () {
    $call = appCall(['from_number' => '+13125550199']);
    $earlier = appCall(['from_number' => '+13125550199', 'status' => CallLog::STATUS_MISSED]);

    $this->postJson("/api/app/v1/calls/{$call->id}/block")->assertOk()->assertJson(['blocked' => true, 'phone' => '+13125550199']);

    expect(BlockedCaller::query()->where('phone_number', '+13125550199')->value('vendor_id'))->toBe(1)
        ->and($earlier->fresh()->status)->toBe(CallLog::STATUS_BLOCKED);
    $this->getJson("/api/app/v1/calls/{$call->id}")->assertJsonPath('data.blocked_number', true);

    $this->postJson('/api/app/v1/calls/unblock', ['phone' => '+13125550199'])->assertOk()->assertJson(['blocked' => false]);
    $this->postJson('/api/app/v1/calls/unblock', ['phone' => '+13125550199'])->assertUnprocessable();
});
