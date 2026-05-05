<?php

use App\Models\CallLog;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    Cache::flush();
    // Create required tables for SQLite in-memory test DB
    if (! Schema::hasTable('vendors')) {
        Schema::create('vendors', function (Blueprint $table): void {
            $table->id();
            $table->string('business_name')->nullable();
            $table->string('business_type')->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('zip_code')->nullable();
            $table->string('timezone')->nullable();
            $table->json('options')->nullable();
            $table->timestamps();
        });
    }

    if (! Schema::hasTable('users')) {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('email')->nullable();
            $table->decimal('cell_phone', 16, 0)->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
    }

    if (! Schema::hasTable('call_logs')) {
        Schema::create('call_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('call_id')->nullable();
            $table->string('call_control_id')->nullable()->index();
            $table->string('call_session_id')->nullable()->index();
            $table->string('call_leg_id')->nullable();
            $table->string('connection_id')->nullable();
            $table->string('direction')->nullable();
            $table->string('from_number')->nullable();
            $table->string('to_number')->nullable();
            $table->string('caller_name')->nullable();
            $table->string('status')->nullable();
            $table->string('forwarded_to')->nullable();
            $table->integer('duration_seconds')->nullable();
            $table->string('disconnect_cause')->nullable();
            $table->string('hangup_cause')->nullable();
            $table->text('notes')->nullable();
            $table->string('recording_url')->nullable();
            $table->boolean('has_voicemail')->default(false);
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('client_id')->nullable();
            $table->unsignedBigInteger('contact_user_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
        });
    }

    if (! Schema::hasTable('clients')) {
        Schema::create('clients', function (Blueprint $table): void {
            $table->id();
            $table->string('home_phone')->nullable();
            $table->timestamps();
        });
    }

    if (! Schema::hasTable('blocked_callers')) {
        Schema::create('blocked_callers', function (Blueprint $table): void {
            $table->id();
            $table->string('phone_number');
            $table->unsignedBigInteger('vendor_id')->nullable();
            $table->string('reason')->nullable();
            $table->boolean('auto_blocked')->default(false);
            $table->timestamps();
        });
    }

    if (! Schema::hasTable('notification_settings')) {
        Schema::create('notification_settings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->boolean('realtime_sms')->default(true);
            $table->timestamps();
        });
    }

    if (! Schema::hasTable('push_subscriptions')) {
        Schema::create('push_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->morphs('subscribable');
            $table->string('endpoint', 500)->unique();
            $table->string('public_key')->nullable();
            $table->string('auth_token')->nullable();
            $table->string('content_encoding')->nullable();
            $table->timestamps();
        });
    }

    // Vendor->ytd_expense_sum (used by Scout's toSearchableArray) reads from
    // expenses; create an empty table so saves don't blow up.
    if (! Schema::hasTable('expenses')) {
        Schema::create('expenses', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('vendor_id')->nullable()->index();
            $table->date('date')->nullable();
            $table->decimal('amount', 12, 2)->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    // Disable Scout syncing across the whole test run — Meilisearch isn't
    // available in unit tests and toSearchableArray triggers extra queries.
    config(['scout.driver' => null]);
    \Laravel\Scout\ModelObserver::disableSyncingFor(Vendor::class);

    // Allow tests to mass-assign vendor `options` (which isn't in $fillable).
    Vendor::unguard();

    // Create vendor with phone system settings
    $this->vendor = Vendor::withoutSyncingToSearch(fn () => Vendor::factory()->create([
        'id' => 1,
        'business_name' => 'GS Construction',
        'timezone' => 'America/Chicago',
        'options' => (object) [
            'short_name' => 'GS Construction',
            'call_recipients' => [],
            'voicemail_enabled' => true,
        ],
    ]));

    // Fake all outbound HTTP so we don't call Telnyx API
    Http::fake([
        'api.telnyx.com/*' => Http::response(['data' => ['result' => 'ok', 'call_control_id' => 'admin-cc-id-1']], 200),
    ]);

    // Set Telnyx config
    config([
        'services.telnyx.api_key' => 'test-api-key',
        'services.telnyx.from' => '+12247354200',
        'services.telnyx.connection_id' => 'test-connection-id',
        'services.telnyx.voice_timeout' => 30,
        'services.telnyx.hold_audio_url' => 'https://example.com/audio/ringback.wav',
        'services.telnyx.tts_voice' => 'Azure.en-US-AvaMultilingualNeural',
        'services.telnyx.tts_voice_type' => 'azure',
        'services.telnyx.public_url' => 'https://example.com',
        'app.url' => 'https://example.com',
    ]);
});

// =========================================================================
// Issue #1: Ringback silence — playback.ended should re-loop the audio
// =========================================================================

it('re-loops ringback audio when playback ends while caller is waiting', function () {
    $callLog = CallLog::factory()->create([
        'status' => CallLog::STATUS_ANSWERED,
        'metadata' => ['admin_call_control_ids' => ['admin-cc-1'], 'joined_admin_ids' => []],
    ]);

    $response = $this->postJson('/webhooks/telnyx/voice', [
        'data' => [
            'event_type' => 'call.playback.ended',
            'record_type' => 'event',
            'payload' => [
                'call_control_id' => 'incoming-cc-id',
                'client_state' => base64_encode(json_encode([
                    'action' => 'caller_waiting',
                    'call_log_id' => $callLog->id,
                ])),
            ],
        ],
    ]);

    $response->assertSuccessful();

    // Should have sent playback_start to re-loop the ringback
    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'incoming-cc-id/actions/playback_start')
            && str_contains($request['audio_url'], 'ringback.wav');
    });
});

it('does not re-loop ringback after admin has already bridged', function () {
    $callLog = CallLog::factory()->create([
        'status' => CallLog::STATUS_TRANSFERRED,
        'metadata' => ['admin_call_control_ids' => ['admin-cc-1'], 'joined_admin_ids' => [1]],
    ]);

    $response = $this->postJson('/webhooks/telnyx/voice', [
        'data' => [
            'event_type' => 'call.playback.ended',
            'record_type' => 'event',
            'payload' => [
                'call_control_id' => 'incoming-cc-id',
                'client_state' => base64_encode(json_encode([
                    'action' => 'caller_waiting',
                    'call_log_id' => $callLog->id,
                ])),
            ],
        ],
    ]);

    $response->assertSuccessful();

    // Should NOT have sent playback_start since call is already bridged
    Http::assertNotSent(function ($request) {
        return str_contains($request->url(), 'incoming-cc-id/actions/playback_start');
    });
});

it('re-loops ringback for click-to-call waiting', function () {
    $callLog = CallLog::factory()->create([
        'status' => CallLog::STATUS_ANSWERED,
        'metadata' => [],
    ]);

    $response = $this->postJson('/webhooks/telnyx/voice', [
        'data' => [
            'event_type' => 'call.playback.ended',
            'record_type' => 'event',
            'payload' => [
                'call_control_id' => 'user-cc-id',
                'client_state' => base64_encode(json_encode([
                    'action' => 'click_to_call_waiting',
                    'call_log_id' => $callLog->id,
                ])),
            ],
        ],
    ]);

    $response->assertSuccessful();

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'user-cc-id/actions/playback_start');
    });
});

// =========================================================================
// Issue #2: TTS should not use CNAM reverse-lookup names for unknown callers
// =========================================================================

it('uses first name in TTS for known users', function () {
    $user = User::factory()->create([
        'first_name' => 'Katie',
        'last_name' => 'Smith',
        'cell_phone' => '6308621038',
    ]);

    $this->vendor->update(['options' => (object) [
        'short_name' => 'GS Construction',
        'call_recipients' => [$user->id],
        'voicemail_enabled' => true,
    ]]);

    $response = $this->postJson('/webhooks/telnyx/voice', [
        'data' => [
            'event_type' => 'call.initiated',
            'record_type' => 'event',
            'payload' => [
                'call_control_id' => 'test-cc-id',
                'direction' => 'incoming',
                'from' => '+16308621038',
                'to' => '+12247354200',
                'call_session_id' => 'test-session',
                'call_leg_id' => 'test-leg',
                'connection_id' => 'test-conn',
            ],
        ],
    ]);

    $response->assertSuccessful();

    // The answer command should include the known user's first name in client_state
    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'test-cc-id/actions/answer')) {
            return false;
        }

        $clientState = json_decode(base64_decode($request['client_state']), true);

        return $clientState['caller_name'] === 'Katie';
    });
});

it('does not include CNAM name in TTS for unknown callers', function () {
    // Reset HTTP factory so the catch-all stub from beforeEach doesn't shadow our number_lookup stub.
    app()->forgetInstance('Illuminate\Http\Client\Factory');
    Http::swap(new \Illuminate\Http\Client\Factory());

    // No user with this phone number — CNAM lookup would return the carrier name
    Http::fake([
        'api.telnyx.com/v2/number_lookup/*' => Http::response([
            'data' => [
                'caller_name' => [
                    'caller_name' => 'SZADY GRZEGORZ',
                    'error_code' => null,
                ],
            ],
        ], 200),
        'api.telnyx.com/*' => Http::response(['data' => ['result' => 'ok', 'call_control_id' => 'admin-cc-id-1']], 200),
    ]);

    $response = $this->postJson('/webhooks/telnyx/voice', [
        'data' => [
            'event_type' => 'call.initiated',
            'record_type' => 'event',
            'payload' => [
                'call_control_id' => 'unknown-caller-cc',
                'direction' => 'incoming',
                'from' => '+18472123894',
                'to' => '+12247354200',
                'call_session_id' => 'test-session-2',
                'call_leg_id' => 'test-leg-2',
                'connection_id' => 'test-conn-2',
            ],
        ],
    ]);

    $response->assertSuccessful();

    // The CNAM name should be stored in the call log for records.
    // CNAM "LAST FIRST" all-caps is flipped to "FIRST LAST" by lookUpCallerViaCnam.
    $callLog = CallLog::where('call_control_id', 'unknown-caller-cc')->first();
    expect($callLog->caller_name)->toBe('GRZEGORZ SZADY');

    // But the answer command's client_state should have null caller_name (not CNAM name)
    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'unknown-caller-cc/actions/answer')) {
            return false;
        }

        $clientState = json_decode(base64_decode($request['client_state']), true);

        return $clientState['caller_name'] === null;
    });
});

// =========================================================================
// Issue #3: AMD should hang up admin legs that reach carrier voicemail
// =========================================================================

it('ignores AMD on admin ring legs (DTMF screening replaces AMD)', function () {
    // AMD is no longer used for admin legs — DTMF "press any key" screening
    // is used instead. AMD events on admin_ring legs should be a no-op.
    $callLog = CallLog::factory()->create([
        'status' => CallLog::STATUS_ANSWERED,
        'metadata' => ['admin_call_control_ids' => ['admin-cc-vm']],
    ]);

    $response = $this->postJson('/webhooks/telnyx/voice', [
        'data' => [
            'event_type' => 'call.machine.detection.ended',
            'record_type' => 'event',
            'payload' => [
                'call_control_id' => 'admin-cc-vm',
                'result' => 'machine',
                'client_state' => base64_encode(json_encode([
                    'action' => 'admin_ring',
                    'call_log_id' => $callLog->id,
                    'incoming_call_control_id' => 'incoming-cc-id',
                    'admin_user_id' => 1,
                ])),
            ],
        ],
    ]);

    $response->assertSuccessful();

    // No hangup should be sent — DTMF screening (or its absence) handles this now.
    Http::assertNotSent(function ($request) {
        return str_contains($request->url(), 'admin-cc-vm/actions/hangup');
    });
});

it('does not hang up admin leg when AMD detects human', function () {
    $callLog = CallLog::factory()->create([
        'status' => CallLog::STATUS_ANSWERED,
        'metadata' => ['admin_call_control_ids' => ['admin-cc-human']],
    ]);

    $response = $this->postJson('/webhooks/telnyx/voice', [
        'data' => [
            'event_type' => 'call.machine.detection.ended',
            'record_type' => 'event',
            'payload' => [
                'call_control_id' => 'admin-cc-human',
                'result' => 'human',
                'client_state' => base64_encode(json_encode([
                    'action' => 'admin_ring',
                    'call_log_id' => $callLog->id,
                    'incoming_call_control_id' => 'incoming-cc-id',
                    'admin_user_id' => 1,
                ])),
            ],
        ],
    ]);

    $response->assertSuccessful();

    // Should NOT have sent hangup — human detected means a real person answered
    Http::assertNotSent(function ($request) {
        return str_contains($request->url(), 'admin-cc-human/actions/hangup');
    });
});

it('does NOT send AMD config when dialing admins (DTMF screening replaces AMD)', function () {
    $admin = User::factory()->create([
        'first_name' => 'Patryk',
        'last_name' => 'Szady',
        'cell_phone' => '2249993880',
    ]);

    $this->vendor->update(['options' => (object) [
        'short_name' => 'GS Construction',
        'call_recipients' => [$admin->id],
        'voicemail_enabled' => true,
    ]]);

    // Trigger incoming call that will dial admins
    $this->postJson('/webhooks/telnyx/voice', [
        'data' => [
            'event_type' => 'call.initiated',
            'record_type' => 'event',
            'payload' => [
                'call_control_id' => 'incoming-amd-test',
                'direction' => 'incoming',
                'from' => '+18005551234',
                'to' => '+12247354200',
                'call_session_id' => 'amd-session',
                'call_leg_id' => 'amd-leg',
                'connection_id' => 'amd-conn',
            ],
        ],
    ]);

    // Then simulate the answer which triggers admin dialing
    $callLog = CallLog::where('call_control_id', 'incoming-amd-test')->first();

    $this->postJson('/webhooks/telnyx/voice', [
        'data' => [
            'event_type' => 'call.answered',
            'record_type' => 'event',
            'payload' => [
                'call_control_id' => 'incoming-amd-test',
                'client_state' => base64_encode(json_encode([
                    'action' => 'welcome_or_ring',
                    'call_log_id' => $callLog->id,
                    'original_caller' => '+18005551234',
                    'caller_name' => null,
                    'caller_user_id' => null,
                ])),
            ],
        ],
    ]);

    // Admin dial must NOT include AMD anymore (DTMF screening is used instead).
    Http::assertNotSent(function ($request) {
        if (! str_contains($request->url(), 'api.telnyx.com/v2/calls') || $request->method() !== 'POST') {
            return false;
        }

        return isset($request->data()['answering_machine_detection']);
    });
});

// =========================================================================
// Conference flow (multi-recipient ring + late join + DTMF 9 invite)
// =========================================================================

it('first admin answering creates conference and joins both legs (no screening prompt)', function () {
    $admin = User::factory()->create([
        'first_name' => 'Patryk',
        'cell_phone' => '2249993880',
    ]);

    $callLog = CallLog::factory()->create([
        'call_control_id' => 'incoming-cc',
        'status' => CallLog::STATUS_ANSWERED,
        'from_number' => '+18472123894',
        'metadata' => [
            'admin_call_control_ids' => ['admin-cc-1'],
            'tts_complete' => true,
            'joined_admin_ids' => [],
        ],
    ]);

    // Override the catch-all so /v2/conferences and /actions/join return matching shapes.
    app()->forgetInstance('Illuminate\Http\Client\Factory');
    Http::swap(new \Illuminate\Http\Client\Factory());
    Http::fake([
        'api.telnyx.com/v2/conferences' => Http::response(['data' => ['id' => 'conf-uuid-123']], 200),
        'api.telnyx.com/*' => Http::response(['data' => ['result' => 'ok']], 200),
    ]);

    $response = $this->postJson('/webhooks/telnyx/voice', [
        'data' => [
            'event_type' => 'call.answered',
            'record_type' => 'event',
            'payload' => [
                'call_control_id' => 'admin-cc-1',
                'client_state' => base64_encode(json_encode([
                    'action' => 'admin_ring',
                    'call_log_id' => $callLog->id,
                    'incoming_call_control_id' => 'incoming-cc',
                    'admin_user_id' => $admin->id,
                ])),
            ],
        ],
    ]);

    $response->assertSuccessful();

    // No screening TTS / gather should be sent to the admin.
    Http::assertNotSent(function ($request) {
        return str_contains($request->url(), 'admin-cc-1/actions/gather_using_speak')
            || str_contains($request->url(), 'admin-cc-1/actions/speak');
    });

    // Conference creation request with proper shape
    Http::assertSent(function ($request) use ($callLog) {
        if (! str_ends_with($request->url(), '/v2/conferences') || $request->method() !== 'POST') {
            return false;
        }
        $body = $request->data();
        return $body['call_control_id'] === 'incoming-cc'
            && str_starts_with($body['name'], "call_{$callLog->id}_")
            && ($body['beep_enabled'] ?? null) === 'on_enter_exit'
            && ($body['comfort_noise'] ?? null) === true;
    });

    // Admin joined the conference
    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/v2/conferences/conf-uuid-123/actions/join')
            && $request->method() === 'POST'
            && ($request->data()['call_control_id'] ?? null) === 'admin-cc-1';
    });

    $callLog->refresh();
    expect($callLog->metadata['conference_id'] ?? null)->toBe('conf-uuid-123');
    expect($callLog->metadata['joined_admin_ids'] ?? [])->toContain($admin->id);
    expect($callLog->status)->toBe(CallLog::STATUS_TRANSFERRED);
});

it('second admin answering joins existing conference directly without TTS prompt', function () {
    $first = User::factory()->create([
        'first_name' => 'Patryk',
        'cell_phone' => '2249993880',
    ]);
    $second = User::factory()->create([
        'first_name' => 'Mary',
        'cell_phone' => '2249991111',
    ]);

    $this->vendor->update(['options' => (object) [
        'short_name' => 'GS Construction',
        'call_recipients' => [$first->id, $second->id],
        'voicemail_enabled' => true,
    ]]);

    $callLog = CallLog::factory()->create([
        'call_control_id' => 'incoming-cc',
        'status' => CallLog::STATUS_TRANSFERRED,
        'caller_name' => 'Bob Smith',
        'metadata' => [
            'admin_call_control_ids' => ['admin-cc-1', 'admin-cc-2'],
            'tts_complete' => true,
            'joined_admin_ids' => [$first->id],
            'conference_id' => 'conf-uuid-existing',
            'conference_name' => "call_1_abcdef",
        ],
    ]);

    // Override catch-all so /v2/conferences/{id}/actions/join is matched explicitly.
    app()->forgetInstance('Illuminate\Http\Client\Factory');
    Http::swap(new \Illuminate\Http\Client\Factory());
    Http::fake([
        'api.telnyx.com/v2/conferences/conf-uuid-existing/actions/join' => Http::response(['data' => ['result' => 'ok']], 200),
        'api.telnyx.com/*' => Http::response(['data' => ['result' => 'ok']], 200),
    ]);

    // 2nd admin's call is answered → should join the conference IMMEDIATELY (no prompt).
    $response = $this->postJson('/webhooks/telnyx/voice', [
        'data' => [
            'event_type' => 'call.answered',
            'record_type' => 'event',
            'payload' => [
                'call_control_id' => 'admin-cc-2',
                'client_state' => base64_encode(json_encode([
                    'action' => 'admin_ring',
                    'call_log_id' => $callLog->id,
                    'incoming_call_control_id' => 'incoming-cc',
                    'admin_user_id' => $second->id,
                ])),
            ],
        ],
    ]);

    $response->assertSuccessful();

    // No TTS / gather should be sent to the late answerer.
    Http::assertNotSent(function ($request) {
        return str_contains($request->url(), 'admin-cc-2/actions/gather_using_speak')
            || str_contains($request->url(), 'admin-cc-2/actions/speak');
    });

    // Should join the existing conference directly.
    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/v2/conferences/conf-uuid-existing/actions/join')
            && ($request->data()['call_control_id'] ?? null) === 'admin-cc-2';
    });

    // No new conference was created
    Http::assertNotSent(function ($request) {
        return str_ends_with($request->url(), '/v2/conferences') && $request->method() === 'POST';
    });

    $callLog->refresh();
    expect($callLog->metadata['joined_admin_ids'] ?? [])->toContain($second->id);
});

it('DTMF 9 from a joined admin invites only not-yet-joined recipients', function () {
    $first = User::factory()->create([
        'first_name' => 'Patryk',
        'cell_phone' => '2249993880',
    ]);
    $second = User::factory()->create([
        'first_name' => 'Mary',
        'cell_phone' => '2249991111',
    ]);
    $third = User::factory()->create([
        'first_name' => 'Alex',
        'cell_phone' => '2249992222',
    ]);

    $this->vendor->update(['options' => (object) [
        'short_name' => 'GS',
        'call_recipients' => [$first->id, $second->id, $third->id],
        'voicemail_enabled' => true,
    ]]);

    $callLog = CallLog::factory()->create([
        'call_control_id' => 'incoming-cc',
        'status' => CallLog::STATUS_TRANSFERRED,
        'caller_name' => 'Bob',
        'metadata' => [
            'admin_call_control_ids' => ['admin-cc-1'],
            'joined_admin_ids' => [$first->id],  // first already in
            'conference_id' => 'conf-uuid-1',
            'conference_name' => "call_1_a",
        ],
    ]);

    Cache::put('telnyx_bridged:admin-cc-1', true, now()->addMinutes(10));

    $response = $this->postJson('/webhooks/telnyx/voice', [
        'data' => [
            'event_type' => 'call.dtmf.received',
            'record_type' => 'event',
            'payload' => [
                'call_control_id' => 'admin-cc-1',
                'digit' => '9',
            ],
        ],
    ]);

    $response->assertSuccessful();

    // Should dial second and third (NOT first)
    Http::assertSent(function ($request) use ($second) {
        if (! str_ends_with($request->url(), '/v2/calls') || $request->method() !== 'POST') {
            return false;
        }
        return ($request->data()['to'] ?? null) === '+1' . $second->cell_phone;
    });
    Http::assertSent(function ($request) use ($third) {
        if (! str_ends_with($request->url(), '/v2/calls') || $request->method() !== 'POST') {
            return false;
        }
        return ($request->data()['to'] ?? null) === '+1' . $third->cell_phone;
    });
    Http::assertNotSent(function ($request) use ($first) {
        if (! str_ends_with($request->url(), '/v2/calls') || $request->method() !== 'POST') {
            return false;
        }
        return ($request->data()['to'] ?? null) === '+1' . $first->cell_phone;
    });
});

it('DTMF 9 is throttled to once per 10 seconds', function () {
    $admin2 = User::factory()->create([
        'first_name' => 'Mary',
        'cell_phone' => '2249991111',
    ]);

    $this->vendor->update(['options' => (object) [
        'short_name' => 'GS',
        'call_recipients' => [$admin2->id],
        'voicemail_enabled' => true,
    ]]);

    $callLog = CallLog::factory()->create([
        'call_control_id' => 'incoming-cc',
        'status' => CallLog::STATUS_TRANSFERRED,
        'metadata' => [
            'admin_call_control_ids' => ['admin-cc-1'],
            'joined_admin_ids' => [],
            'conference_id' => 'conf-uuid-x',
        ],
    ]);

    Cache::put('telnyx_bridged:admin-cc-1', true, now()->addMinutes(10));

    $payload = [
        'data' => [
            'event_type' => 'call.dtmf.received',
            'record_type' => 'event',
            'payload' => [
                'call_control_id' => 'admin-cc-1',
                'digit' => '9',
            ],
        ],
    ];

    $this->postJson('/webhooks/telnyx/voice', $payload);
    Http::assertSentCount(1);  // 1 dial

    $this->postJson('/webhooks/telnyx/voice', $payload);
    Http::assertSentCount(1);  // throttled — still only 1 dial
});

it('conference invite (Add to Call): intro TTS done causes leg to join existing conference', function () {
    $callLog = CallLog::factory()->create([
        'call_control_id' => 'incoming-cc',
        'status' => CallLog::STATUS_TRANSFERRED,
        'caller_name' => 'Bob',
        'metadata' => [
            'admin_call_control_ids' => ['admin-cc-1'],
            'joined_admin_ids' => [1],
            'conference_id' => 'conf-uuid-invite',
            'conference_name' => 'call_1_xyz',
        ],
    ]);

    Http::fake([
        'api.telnyx.com/v2/conferences/conf-uuid-invite/actions/join' => Http::response(['data' => ['result' => 'ok']], 200),
        'api.telnyx.com/*' => Http::response(['data' => ['result' => 'ok']], 200),
    ]);

    $response = $this->postJson('/webhooks/telnyx/voice', [
        'data' => [
            'event_type' => 'call.speak.ended',
            'record_type' => 'event',
            'payload' => [
                'call_control_id' => 'invitee-cc',
                'status' => 'completed',
                'client_state' => base64_encode(json_encode([
                    'action' => 'conference_invite_intro_done',
                    'call_log_id' => $callLog->id,
                    'conference_name' => 'call_1_xyz',
                    'participant_name' => 'Friend',
                ])),
            ],
        ],
    ]);

    $response->assertSuccessful();

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/v2/conferences/conf-uuid-invite/actions/join')
            && ($request->data()['call_control_id'] ?? null) === 'invitee-cc';
    });
});
