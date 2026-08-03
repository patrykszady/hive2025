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
            $table->string('recording_disk')->nullable();
            $table->string('recording_path')->nullable();
            $table->string('recording_telnyx_id')->nullable();
            $table->timestamp('recording_started_at')->nullable();
            $table->boolean('recording_disclosure_played')->default(false);
            $table->string('language', 8)->nullable();
            $table->timestamp('purge_after')->nullable();
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

    if (! Schema::hasTable('client_user')) {
        Schema::create('client_user', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('client_id')->index();
            $table->unsignedBigInteger('user_id')->index();
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

    if (! Schema::hasTable('app_notifications')) {
        Schema::create('app_notifications', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('type');
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('action_url')->nullable();
            $table->json('data')->nullable();
            $table->timestamp('read_at')->nullable();
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

it('dials all click-to-call targets when target_phones are provided', function () {
    $caller = User::factory()->create([
        'first_name' => 'Patryk',
        'cell_phone' => '2249993880',
    ]);

    $callLog = CallLog::factory()->create([
        'status' => CallLog::STATUS_INITIATED,
        'user_id' => $caller->id,
        'metadata' => ['type' => 'click_to_call_multi'],
    ]);

    app()->forgetInstance('Illuminate\\Http\\Client\\Factory');
    Http::swap(new \Illuminate\Http\Client\Factory());
    Http::fake([
        'api.telnyx.com/v2/calls' => Http::sequence()
            ->push(['data' => ['call_control_id' => 'target-cc-1']], 200)
            ->push(['data' => ['call_control_id' => 'target-cc-2']], 200),
        'api.telnyx.com/*' => Http::response(['data' => ['result' => 'ok']], 200),
    ]);

    $response = $this->postJson('/webhooks/telnyx/voice', [
        'data' => [
            'event_type' => 'call.answered',
            'record_type' => 'event',
            'payload' => [
                'call_control_id' => 'user-cc-id',
                'client_state' => base64_encode(json_encode([
                    'action' => 'click_to_call',
                    'call_log_id' => $callLog->id,
                    'target_phones' => ['+12245550101', '+12245550102'],
                ])),
            ],
        ],
    ]);

    $response->assertSuccessful();

    Http::assertSent(function ($request) {
        if (! str_ends_with($request->url(), '/v2/calls') || $request->method() !== 'POST') {
            return false;
        }

        return ($request->data()['to'] ?? null) === '+12245550101';
    });

    Http::assertSent(function ($request) {
        if (! str_ends_with($request->url(), '/v2/calls') || $request->method() !== 'POST') {
            return false;
        }

        return ($request->data()['to'] ?? null) === '+12245550102';
    });

    $callLog->refresh();
    expect($callLog->metadata['target_call_control_ids'] ?? [])->toBe(['target-cc-1', 'target-cc-2']);
});

it('keeps sibling click-to-call legs ringing so they can join when they answer', function () {
    $callLog = CallLog::factory()->create([
        'status' => CallLog::STATUS_ANSWERED,
        'metadata' => [
            'type' => 'click_to_call_multi',
            'target_call_control_ids' => ['target-cc-1', 'target-cc-2'],
        ],
    ]);

    app()->forgetInstance('Illuminate\\Http\\Client\\Factory');
    Http::swap(new \Illuminate\Http\Client\Factory());
    Http::fake([
        'api.telnyx.com/v2/conferences' => Http::response([
            'data' => [
                'id' => 'conf-123',
            ],
        ], 200),
        'api.telnyx.com/*' => Http::response(['data' => ['result' => 'ok']], 200),
    ]);

    $firstAnswer = $this->postJson('/webhooks/telnyx/voice', [
        'data' => [
            'event_type' => 'call.answered',
            'record_type' => 'event',
            'payload' => [
                'call_control_id' => 'target-cc-1',
                'client_state' => base64_encode(json_encode([
                    'action' => 'click_to_call_target_ring',
                    'call_log_id' => $callLog->id,
                    'user_call_control_id' => 'user-cc-id',
                ])),
            ],
        ],
    ]);

    $firstAnswer->assertSuccessful();

    Http::assertSent(function ($request) {
        return str_ends_with($request->url(), '/v2/conferences')
            && ($request->data()['call_control_id'] ?? null) === 'user-cc-id';
    });

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/v2/conferences/conf-123/actions/join')
            && ($request->data()['call_control_id'] ?? null) === 'target-cc-1';
    });

    Http::assertNotSent(function ($request) {
        return str_contains($request->url(), 'target-cc-2/actions/hangup');
    });

    $secondAnswer = $this->postJson('/webhooks/telnyx/voice', [
        'data' => [
            'event_type' => 'call.answered',
            'record_type' => 'event',
            'payload' => [
                'call_control_id' => 'target-cc-2',
                'client_state' => base64_encode(json_encode([
                    'action' => 'click_to_call_target_ring',
                    'call_log_id' => $callLog->id,
                    'user_call_control_id' => 'user-cc-id',
                ])),
            ],
        ],
    ]);

    $secondAnswer->assertSuccessful();

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/v2/conferences/conf-123/actions/join')
            && ($request->data()['call_control_id'] ?? null) === 'target-cc-2';
    });

    $callLog->refresh();
    expect($callLog->status)->toBe(CallLog::STATUS_TRANSFERRED);
    expect($callLog->metadata['answered_target_call_control_id'] ?? null)->toBe('target-cc-1');
    expect($callLog->metadata['conference_id'] ?? null)->toBe('conf-123');
    expect($callLog->metadata['joined_target_call_control_ids'] ?? [])->toContain('target-cc-1', 'target-cc-2');
});

it('waits for remaining click-to-call targets before playing failure message', function () {
    $callLog = CallLog::factory()->create([
        'status' => CallLog::STATUS_ANSWERED,
        'metadata' => [
            'type' => 'click_to_call_multi',
            'target_call_control_ids' => ['target-cc-1', 'target-cc-2'],
            'failed_target_call_control_ids' => [],
        ],
    ]);

    app()->forgetInstance('Illuminate\\Http\\Client\\Factory');
    Http::swap(new \Illuminate\Http\Client\Factory());
    Http::fake([
        'api.telnyx.com/*' => Http::response(['data' => ['result' => 'ok']], 200),
    ]);

    $response = $this->postJson('/webhooks/telnyx/voice', [
        'data' => [
            'event_type' => 'call.hangup',
            'record_type' => 'event',
            'payload' => [
                'call_control_id' => 'target-cc-1',
                'hangup_cause' => 'timeout',
                'client_state' => base64_encode(json_encode([
                    'action' => 'click_to_call_target_ring',
                    'call_log_id' => $callLog->id,
                    'user_call_control_id' => 'user-cc-id',
                ])),
            ],
        ],
    ]);

    $response->assertSuccessful();

    Http::assertNotSent(function ($request) {
        return str_contains($request->url(), 'user-cc-id/actions/speak');
    });

    $callLog->refresh();
    expect($callLog->status)->toBe(CallLog::STATUS_ANSWERED);
    expect($callLog->metadata['failed_target_call_control_ids'] ?? [])->toContain('target-cc-1');
});

it('plays failure message after final click-to-call target fails', function () {
    $callLog = CallLog::factory()->create([
        'status' => CallLog::STATUS_ANSWERED,
        'metadata' => [
            'type' => 'click_to_call_multi',
            'target_call_control_ids' => ['target-cc-1', 'target-cc-2'],
            'failed_target_call_control_ids' => ['target-cc-1'],
        ],
    ]);

    app()->forgetInstance('Illuminate\\Http\\Client\\Factory');
    Http::swap(new \Illuminate\Http\Client\Factory());
    Http::fake([
        'api.telnyx.com/*' => Http::response(['data' => ['result' => 'ok']], 200),
    ]);

    $response = $this->postJson('/webhooks/telnyx/voice', [
        'data' => [
            'event_type' => 'call.hangup',
            'record_type' => 'event',
            'payload' => [
                'call_control_id' => 'target-cc-2',
                'hangup_cause' => 'timeout',
                'client_state' => base64_encode(json_encode([
                    'action' => 'click_to_call_target_ring',
                    'call_log_id' => $callLog->id,
                    'user_call_control_id' => 'user-cc-id',
                ])),
            ],
        ],
    ]);

    $response->assertSuccessful();

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'user-cc-id/actions/speak');
    });

    $callLog->refresh();
    expect($callLog->status)->toBe(CallLog::STATUS_MISSED);
});

it('keeps click-to-call active when one joined target hangs up but another joined target remains', function () {
    $callLog = CallLog::factory()->create([
        'status' => CallLog::STATUS_TRANSFERRED,
        'answered_at' => now()->subSeconds(20),
        'metadata' => [
            'type' => 'click_to_call_multi',
            'conference_id' => 'conf-123',
            'target_call_control_ids' => ['target-cc-1', 'target-cc-2'],
            'joined_target_call_control_ids' => ['target-cc-1', 'target-cc-2'],
            'answered_target_call_control_id' => 'target-cc-1',
        ],
    ]);

    app()->forgetInstance('Illuminate\\Http\\Client\\Factory');
    Http::swap(new \Illuminate\Http\Client\Factory());
    Http::fake([
        'api.telnyx.com/*' => Http::response(['data' => ['result' => 'ok']], 200),
    ]);

    $response = $this->postJson('/webhooks/telnyx/voice', [
        'data' => [
            'event_type' => 'call.hangup',
            'record_type' => 'event',
            'payload' => [
                'call_control_id' => 'target-cc-1',
                'hangup_cause' => 'normal_clearing',
                'client_state' => base64_encode(json_encode([
                    'action' => 'click_to_call_target_ring',
                    'call_log_id' => $callLog->id,
                    'user_call_control_id' => 'user-cc-id',
                ])),
            ],
        ],
    ]);

    $response->assertSuccessful();

    $callLog->refresh();
    expect($callLog->status)->toBe(CallLog::STATUS_TRANSFERRED);
    expect($callLog->metadata['joined_target_call_control_ids'] ?? [])->toBe(['target-cc-2']);
    expect($callLog->ended_at)->toBeNull();
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

it('first admin answering plays screening prompt then on speak.ended creates conference and joins', function () {
    $admin = User::factory()->create([
        'first_name' => 'Patryk',
        'cell_phone' => '2249993880',
    ]);

    $callLog = CallLog::factory()->create([
        'call_control_id' => 'incoming-cc',
        'status' => CallLog::STATUS_ANSWERED,
        'from_number' => '+18472123894',
        'caller_name' => 'Bob Smith',
        'metadata' => [
            'admin_call_control_ids' => ['admin-cc-1'],
            'tts_complete' => true,
            'joined_admin_ids' => [],
        ],
    ]);

    app()->forgetInstance('Illuminate\Http\Client\Factory');
    Http::swap(new \Illuminate\Http\Client\Factory());
    Http::fake([
        'api.telnyx.com/v2/conferences' => Http::response(['data' => ['id' => 'conf-uuid-123']], 200),
        'api.telnyx.com/*' => Http::response(['data' => ['result' => 'ok']], 200),
    ]);

    // Step 1: admin answers — should send the screening speak prompt, NOT
    // create a conference yet.
    $this->postJson('/webhooks/telnyx/voice', [
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
    ])->assertSuccessful();

    // Screening TTS sent to admin
    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'admin-cc-1/actions/speak') || $request->method() !== 'POST') {
            return false;
        }
        $payload = (string) ($request->data()['payload'] ?? '');
        return str_contains($payload, 'Bob Smith is calling')
            && str_contains($payload, 'voicemail')
            && str_contains($payload, 'remain on the line');
    });

    // No conference yet — wait for speak.ended.
    Http::assertNotSent(function ($request) {
        return str_ends_with($request->url(), '/v2/conferences') && $request->method() === 'POST';
    });

    // Step 2: speak.ended fires → conference is created and admin joins.
    $this->postJson('/webhooks/telnyx/voice', [
        'data' => [
            'event_type' => 'call.speak.ended',
            'record_type' => 'event',
            'payload' => [
                'call_control_id' => 'admin-cc-1',
                'client_state' => base64_encode(json_encode([
                    'action' => 'admin_screen_done',
                    'call_log_id' => $callLog->id,
                    'incoming_call_control_id' => 'incoming-cc',
                    'admin_user_id' => $admin->id,
                    'conference_id' => null,
                ])),
            ],
        ],
    ])->assertSuccessful();

    Http::assertSent(function ($request) use ($callLog) {
        if (! str_ends_with($request->url(), '/v2/conferences') || $request->method() !== 'POST') {
            return false;
        }
        $body = $request->data();
        return $body['call_control_id'] === 'incoming-cc'
            && str_starts_with($body['name'], "call_{$callLog->id}_")
            && ($body['beep_enabled'] ?? null) === 'never'
            && ($body['comfort_noise'] ?? null) === true;
    });

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

it('late admin answering plays screening prompt then on speak.ended joins existing conference', function () {
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
            'conference_name' => 'call_1_abcdef',
        ],
    ]);

    app()->forgetInstance('Illuminate\Http\Client\Factory');
    Http::swap(new \Illuminate\Http\Client\Factory());
    Http::fake([
        'api.telnyx.com/v2/conferences/conf-uuid-existing/actions/join' => Http::response(['data' => ['result' => 'ok']], 200),
        'api.telnyx.com/*' => Http::response(['data' => ['result' => 'ok']], 200),
    ]);

    // Step 1: late admin answers → screening prompt with conference_id present.
    $this->postJson('/webhooks/telnyx/voice', [
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
    ])->assertSuccessful();

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'admin-cc-2/actions/speak') || $request->method() !== 'POST') {
            return false;
        }
        return str_contains((string) ($request->data()['payload'] ?? ''), 'Bob Smith is calling');
    });

    // No join yet.
    Http::assertNotSent(function ($request) {
        return str_contains($request->url(), '/v2/conferences/conf-uuid-existing/actions/join');
    });

    // Step 2: speak.ended → join the existing conference.
    $this->postJson('/webhooks/telnyx/voice', [
        'data' => [
            'event_type' => 'call.speak.ended',
            'record_type' => 'event',
            'payload' => [
                'call_control_id' => 'admin-cc-2',
                'client_state' => base64_encode(json_encode([
                    'action' => 'admin_screen_done',
                    'call_log_id' => $callLog->id,
                    'incoming_call_control_id' => 'incoming-cc',
                    'admin_user_id' => $second->id,
                    'conference_id' => 'conf-uuid-existing',
                ])),
            ],
        ],
    ])->assertSuccessful();

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/v2/conferences/conf-uuid-existing/actions/join')
            && ($request->data()['call_control_id'] ?? null) === 'admin-cc-2';
    });

    // No new conference created.
    Http::assertNotSent(function ($request) {
        return str_ends_with($request->url(), '/v2/conferences') && $request->method() === 'POST';
    });

    $callLog->refresh();
    expect($callLog->metadata['joined_admin_ids'] ?? [])->toContain($second->id);
});

it('keeps other GS recipients ringing after first answer and allows late join', function () {
    $first = User::factory()->create([
        'first_name' => 'Patryk',
        'cell_phone' => '2249993880',
    ]);

    $second = User::factory()->create([
        'first_name' => 'Mary',
        'cell_phone' => '2249991111',
    ]);

    $callLog = CallLog::factory()->create([
        'call_control_id' => 'incoming-cc',
        'status' => CallLog::STATUS_ANSWERED,
        'caller_name' => 'Bob Smith',
        'metadata' => [
            'admin_call_control_ids' => ['admin-cc-1', 'admin-cc-2'],
            'tts_complete' => true,
            'joined_admin_ids' => [],
        ],
    ]);

    app()->forgetInstance('Illuminate\\Http\\Client\\Factory');
    Http::swap(new \Illuminate\Http\Client\Factory());
    Http::fake([
        'api.telnyx.com/v2/conferences' => Http::response(['data' => ['id' => 'conf-uuid-join']], 200),
        'api.telnyx.com/*' => Http::response(['data' => ['result' => 'ok']], 200),
    ]);

    // First admin answers and confirms screening.
    $this->postJson('/webhooks/telnyx/voice', [
        'data' => [
            'event_type' => 'call.speak.ended',
            'record_type' => 'event',
            'payload' => [
                'call_control_id' => 'admin-cc-1',
                'client_state' => base64_encode(json_encode([
                    'action' => 'admin_screen_done',
                    'call_log_id' => $callLog->id,
                    'incoming_call_control_id' => 'incoming-cc',
                    'admin_user_id' => $first->id,
                    'conference_id' => null,
                ])),
            ],
        ],
    ])->assertSuccessful();

    // No sibling hangup should be sent to still-ringing admin-cc-2.
    Http::assertNotSent(function ($request) {
        return str_contains($request->url(), 'admin-cc-2/actions/hangup');
    });

    // Late answerer still gets screening prompt, then joins existing conference.
    $this->postJson('/webhooks/telnyx/voice', [
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
    ])->assertSuccessful();

    $this->postJson('/webhooks/telnyx/voice', [
        'data' => [
            'event_type' => 'call.speak.ended',
            'record_type' => 'event',
            'payload' => [
                'call_control_id' => 'admin-cc-2',
                'client_state' => base64_encode(json_encode([
                    'action' => 'admin_screen_done',
                    'call_log_id' => $callLog->id,
                    'incoming_call_control_id' => 'incoming-cc',
                    'admin_user_id' => $second->id,
                    'conference_id' => 'conf-uuid-join',
                ])),
            ],
        ],
    ])->assertSuccessful();

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/v2/conferences/conf-uuid-join/actions/join')
            && ($request->data()['call_control_id'] ?? null) === 'admin-cc-2';
    });

    $callLog->refresh();
    expect($callLog->metadata['joined_admin_ids'] ?? [])->toContain($first->id, $second->id);
    expect($callLog->status)->toBe(CallLog::STATUS_TRANSFERRED);
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

it('does not play the voicemail menu twice when the caller does not press a digit', function () {
    $admin = User::factory()->create([
        'first_name' => 'Patryk',
        'cell_phone' => '2249993880',
    ]);

    $this->vendor->update(['options' => (object) [
        'short_name' => 'GS',
        'call_recipients' => [$admin->id],
        'voicemail_enabled' => true,
    ]]);

    $callLog = CallLog::factory()->create([
        'call_control_id' => 'incoming-cc',
        'status' => CallLog::STATUS_TRANSFERRED,
        'metadata' => ['admin_call_control_ids' => []],
    ]);

    Http::fake(['api.telnyx.com/*' => Http::response(['data' => ['result' => 'ok']], 200)]);

    // Simulate the IVR menu speak.ended (TTS played fully).
    $this->postJson('/webhooks/telnyx/voice', [
        'data' => [
            'event_type' => 'call.speak.ended',
            'record_type' => 'event',
            'payload' => [
                'call_control_id' => 'incoming-cc',
                'client_state' => base64_encode(json_encode([
                    'action' => 'voicemail_ivr_menu',
                    'call_log_id' => $callLog->id,
                ])),
            ],
        ],
    ])->assertSuccessful();

    // Now gather.ended fires with no digits — should NOT replay menu, should
    // go straight to the voicemail greeting.
    $this->postJson('/webhooks/telnyx/voice', [
        'data' => [
            'event_type' => 'call.gather.ended',
            'record_type' => 'event',
            'payload' => [
                'call_control_id' => 'incoming-cc',
                'digits' => '',
                'client_state' => base64_encode(json_encode([
                    'action' => 'voicemail_ivr_menu',
                    'call_log_id' => $callLog->id,
                    'original_caller' => '+15555550100',
                    'is_known_caller' => false,
                    'retry_count' => 0,
                ])),
            ],
        ],
    ])->assertSuccessful();

    // No second gather_using_speak should have been sent.
    Http::assertNotSent(function ($request) {
        return str_contains($request->url(), '/actions/gather_using_speak');
    });

    // A speak (the voicemail greeting) was sent with action=voicemail_prompt_done.
    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'incoming-cc/actions/speak') || $request->method() !== 'POST') {
            return false;
        }
        $clientState = json_decode(base64_decode($request->data()['client_state'] ?? ''), true);
        return ($clientState['action'] ?? null) === 'voicemail_prompt_done';
    });
});

it('does not replay the voicemail menu when gather_using_speak never emits speak.ended', function () {
    // Production reality (call 1940, 2026-07-31): gather_using_speak does NOT
    // reliably emit its own call.speak.ended, so the old "was the TTS played?"
    // cache flag was never set and EVERY voicemail caller heard the full menu
    // twice. Only gather.ended arrives here — no speak.ended at all.
    $this->vendor->update(['options' => (object) [
        'short_name' => 'GS',
        'voicemail_enabled' => true,
    ]]);

    $callLog = CallLog::factory()->create([
        'call_control_id' => 'incoming-cc',
        'status' => CallLog::STATUS_TRANSFERRED,
        'metadata' => ['admin_call_control_ids' => []],
    ]);

    Http::fake(['api.telnyx.com/*' => Http::response(['data' => ['result' => 'ok']], 200)]);

    // The menu was issued 14s ago — long enough that it must have spoken.
    Cache::put('telnyx_voicemail_gather_started:incoming-cc', microtime(true) - 14.0, now()->addMinutes(5));

    $this->postJson('/webhooks/telnyx/voice', [
        'data' => [
            'event_type' => 'call.gather.ended',
            'record_type' => 'event',
            'payload' => [
                'call_control_id' => 'incoming-cc',
                'digits' => '',
                'status' => 'timeout',
                'client_state' => base64_encode(json_encode([
                    'action' => 'voicemail_ivr_menu',
                    'call_log_id' => $callLog->id,
                    'original_caller' => '+15555550100',
                    'is_known_caller' => false,
                    'retry_count' => 0,
                ])),
            ],
        ],
    ])->assertSuccessful();

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/actions/gather_using_speak'));

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'incoming-cc/actions/speak') || $request->method() !== 'POST') {
            return false;
        }
        $clientState = json_decode(base64_decode($request->data()['client_state'] ?? ''), true);
        return ($clientState['action'] ?? null) === 'voicemail_prompt_done';
    });
});

it('retries the voicemail menu only when the gather died before speaking', function () {
    $this->vendor->update(['options' => (object) [
        'short_name' => 'GS',
        'voicemail_enabled' => true,
    ]]);

    $callLog = CallLog::factory()->create([
        'call_control_id' => 'incoming-cc',
        'status' => CallLog::STATUS_TRANSFERRED,
        'metadata' => ['admin_call_control_ids' => []],
    ]);

    Http::fake(['api.telnyx.com/*' => Http::response(['data' => ['result' => 'ok']], 200)]);

    // Ended after ~1s — the ~14s prompt cannot have played.
    Cache::put('telnyx_voicemail_gather_started:incoming-cc', microtime(true) - 1.0, now()->addMinutes(5));

    $this->postJson('/webhooks/telnyx/voice', [
        'data' => [
            'event_type' => 'call.gather.ended',
            'record_type' => 'event',
            'payload' => [
                'call_control_id' => 'incoming-cc',
                'digits' => '',
                'client_state' => base64_encode(json_encode([
                    'action' => 'voicemail_ivr_menu',
                    'call_log_id' => $callLog->id,
                    'original_caller' => '+15555550100',
                    'is_known_caller' => false,
                    'retry_count' => 0,
                ])),
            ],
        ],
    ])->assertSuccessful();

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/actions/gather_using_speak')) {
            return false;
        }
        $clientState = json_decode(base64_decode($request->data()['client_state'] ?? ''), true);
        return ($clientState['retry_count'] ?? null) === 1;
    });
});

it('marks the caller bridged before stopping ringback so it cannot restart over the conference', function () {
    // Production (call 1949, 2026-07-31 21:54:41): playback_stop fired, the
    // resulting call.playback.ended arrived while createConference was still
    // in flight, and the re-loop guard restarted ringback TWICE — it then
    // played over the live conference and the caller heard ring tone instead
    // of the admin. The flag must be set before playback_stop.
    $admin = User::factory()->create(['first_name' => 'Patryk', 'cell_phone' => '2249993880']);

    $this->vendor->update(['options' => (object) [
        'short_name' => 'GS',
        'call_recipients' => [$admin->id],
        'admin_screening_enabled' => false,
    ]]);

    $callLog = CallLog::factory()->create([
        'call_control_id' => 'incoming-cc',
        'status' => CallLog::STATUS_ANSWERED,
        'metadata' => ['tts_complete' => true, 'joined_admin_ids' => []],
    ]);

    app()->forgetInstance('Illuminate\Http\Client\Factory');
    Http::swap(new \Illuminate\Http\Client\Factory());

    // Reproduce the race deterministically: while the conference-create call
    // is in flight, Telnyx delivers the caller's call.playback.ended.
    $test = $this;
    Http::fake([
        'api.telnyx.com/v2/conferences' => function () use ($test, $callLog) {
            $test->postJson('/webhooks/telnyx/voice', [
                'data' => [
                    'event_type' => 'call.playback.ended',
                    'record_type' => 'event',
                    'payload' => [
                        'call_control_id' => 'incoming-cc',
                        'client_state' => base64_encode(json_encode([
                            'action' => 'caller_waiting',
                            'call_log_id' => $callLog->id,
                        ])),
                    ],
                ],
            ]);

            return Http::response(['data' => ['id' => 'conf-uuid-123']], 200);
        },
        'api.telnyx.com/*' => Http::response(['data' => ['result' => 'ok']], 200),
    ]);

    $this->postJson('/webhooks/telnyx/voice', [
        'data' => [
            'event_type' => 'call.speak.ended',
            'record_type' => 'event',
            'payload' => [
                'call_control_id' => 'admin-cc-1',
                'client_state' => base64_encode(json_encode([
                    'action' => 'admin_screen_done',
                    'call_log_id' => $callLog->id,
                    'incoming_call_control_id' => 'incoming-cc',
                    'admin_user_id' => $admin->id,
                    'conference_id' => null,
                ])),
            ],
        ],
    ])->assertSuccessful();

    // The ringback must NOT have been restarted on the caller's leg.
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'incoming-cc/actions/playback_start'));
});

it('drops conference bookkeeping noise without logging or dedup work', function () {
    // conference.floor.changed alone was 412 of 616 voice webhooks on
    // 2026-07-31. Each must cost one 200 and nothing else — no dedup cache
    // entry, no outbound command.
    Http::fake(['api.telnyx.com/*' => Http::response(['data' => ['result' => 'ok']], 200)]);

    foreach (['conference.floor.changed', 'conference.participant.joined', 'call.playback.started'] as $eventType) {
        $this->postJson('/webhooks/telnyx/voice', [
            'data' => [
                'id' => 'evt-noise-'.$eventType,
                'event_type' => $eventType,
                'record_type' => 'event',
                'payload' => ['call_control_id' => 'incoming-cc'],
            ],
        ])->assertSuccessful();

        // Returned before the dedup cache write — the real proof the event was
        // dropped early rather than falling through to the unknown-event path.
        expect(Cache::has('telnyx_event:evt-noise-'.$eventType))->toBeFalse();
    }

    Http::assertNothingSent();
});

it('hangs up the target instead of bridging when the click-to-call already ended', function () {
    // Production call 1945: the user gave up during the disclosure, then this
    // event fired and bridgeCalls was attempted against a dead leg.
    $callLog = CallLog::factory()->create([
        'call_control_id' => 'user-cc',
        'status' => CallLog::STATUS_COMPLETED,
    ]);

    Http::fake(['api.telnyx.com/*' => Http::response(['data' => ['result' => 'ok']], 200)]);

    $this->postJson('/webhooks/telnyx/voice', [
        'data' => [
            'event_type' => 'call.speak.ended',
            'record_type' => 'event',
            'payload' => [
                'call_control_id' => 'target-cc',
                'client_state' => base64_encode(json_encode([
                    'action' => 'click_to_call_target_intro_done',
                    'call_log_id' => $callLog->id,
                    'user_call_control_id' => 'user-cc',
                ])),
            ],
        ],
    ])->assertSuccessful();

    // Target leg dropped; no bridge attempted against the dead user leg.
    Http::assertSent(fn ($request) => str_contains($request->url(), 'target-cc/actions/hangup'));
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/actions/bridge'));
});

it('does not request answering machine detection on admin ring legs', function () {
    // handleAmdEnded discards every admin_ring AMD result, so requesting it
    // billed premium detection on every inbound call for nothing.
    // Inside business hours — outside them the call routes straight to
    // voicemail and no admin is dialed at all.
    $this->travelTo(\Carbon\Carbon::parse('2026-08-05 10:00:00', 'America/Chicago'));

    $admin = User::factory()->create(['first_name' => 'Patryk', 'cell_phone' => '2249993880']);

    $this->vendor->update(['options' => (object) [
        'short_name' => 'GS',
        'call_recipients' => [$admin->id],
    ]]);

    Http::fake([
        'api.telnyx.com/v2/calls' => Http::response(['data' => ['call_control_id' => 'admin-cc-1']], 200),
        'api.telnyx.com/*' => Http::response(['data' => ['result' => 'ok']], 200),
    ]);

    $callLog = CallLog::factory()->create([
        'call_control_id' => 'incoming-cc',
        'status' => CallLog::STATUS_ANSWERED,
        'caller_name' => 'Bob Smith',
    ]);

    $this->postJson('/webhooks/telnyx/voice', [
        'data' => [
            'event_type' => 'call.answered',
            'record_type' => 'event',
            'payload' => [
                'call_control_id' => 'incoming-cc',
                'client_state' => base64_encode(json_encode([
                    'action' => 'welcome_or_ring',
                    'call_log_id' => $callLog->id,
                    'original_caller' => '+15555550100',
                ])),
            ],
        ],
    ])->assertSuccessful();

    $adminDials = collect(Http::recorded())
        ->map(fn ($pair) => $pair[0])
        ->filter(fn ($request) => str_ends_with($request->url(), '/v2/calls') && $request->method() === 'POST')
        ->filter(function ($request) {
            $clientState = json_decode(base64_decode($request->data()['client_state'] ?? ''), true);

            return ($clientState['action'] ?? null) === 'admin_ring';
        });

    expect($adminDials)->not->toBeEmpty();

    foreach ($adminDials as $request) {
        expect($request->data())
            ->not->toHaveKey('answering_machine_detection')
            ->not->toHaveKey('machine_detection');
    }
});

it('triggerVoicemail is idempotent — admin hangup race does not produce two menus', function () {
    $admin = User::factory()->create([
        'first_name' => 'Patryk',
        'cell_phone' => '2249993880',
    ]);

    $this->vendor->update(['options' => (object) [
        'short_name' => 'GS',
        'call_recipients' => [$admin->id],
        'voicemail_enabled' => true,
    ]]);

    $callLog = CallLog::factory()->create([
        'call_control_id' => 'incoming-cc',
        'status' => CallLog::STATUS_TRANSFERRED,
        'metadata' => ['admin_call_control_ids' => ['admin-cc-1']],
    ]);

    Http::fake(['api.telnyx.com/*' => Http::response(['data' => ['result' => 'ok']], 200)]);

    // Admin hangs up — handleAdminRingHangup triggers voicemail (1st time).
    $this->postJson('/webhooks/telnyx/voice', [
        'data' => [
            'event_type' => 'call.hangup',
            'record_type' => 'event',
            'payload' => [
                'call_control_id' => 'admin-cc-1',
                'hangup_cause' => 'normal_clearing',
                'client_state' => base64_encode(json_encode([
                    'action' => 'admin_ring',
                    'call_log_id' => $callLog->id,
                    'incoming_call_control_id' => 'incoming-cc',
                    'admin_user_id' => $admin->id,
                ])),
            ],
        ],
    ])->assertSuccessful();

    // Then the racing speak.ended for the screening prompt arrives — must NOT
    // trigger a second voicemail gather.
    $this->postJson('/webhooks/telnyx/voice', [
        'data' => [
            'event_type' => 'call.speak.ended',
            'record_type' => 'event',
            'payload' => [
                'call_control_id' => 'admin-cc-1',
                'client_state' => base64_encode(json_encode([
                    'action' => 'admin_screen_done',
                    'call_log_id' => $callLog->id,
                    'incoming_call_control_id' => 'incoming-cc',
                    'admin_user_id' => $admin->id,
                    'conference_id' => null,
                ])),
            ],
        ],
    ])->assertSuccessful();

    // Exactly ONE gather_using_speak (the voicemail menu) was sent to the caller.
    $gatherCount = 0;
    foreach (Http::recorded() as [$request]) {
        if (str_contains($request->url(), 'incoming-cc/actions/gather_using_speak')) {
            $gatherCount++;
        }
    }
    expect($gatherCount)->toBe(1);
});

it('voicemail gather uses a 2s timeout to minimize dead air', function () {
    $admin = User::factory()->create(['first_name' => 'Patryk', 'cell_phone' => '2249993880']);

    $this->vendor->update(['options' => (object) [
        'short_name' => 'GS',
        'call_recipients' => [$admin->id],
        'voicemail_enabled' => true,
    ]]);

    $callLog = CallLog::factory()->create([
        'call_control_id' => 'incoming-cc',
        'status' => CallLog::STATUS_TRANSFERRED,
        'metadata' => ['admin_call_control_ids' => ['admin-cc-1']],
    ]);

    Http::fake(['api.telnyx.com/*' => Http::response(['data' => ['result' => 'ok']], 200)]);

    $this->postJson('/webhooks/telnyx/voice', [
        'data' => [
            'event_type' => 'call.hangup',
            'record_type' => 'event',
            'payload' => [
                'call_control_id' => 'admin-cc-1',
                'hangup_cause' => 'timeout',
                'client_state' => base64_encode(json_encode([
                    'action' => 'admin_ring',
                    'call_log_id' => $callLog->id,
                    'incoming_call_control_id' => 'incoming-cc',
                    'admin_user_id' => $admin->id,
                ])),
            ],
        ],
    ])->assertSuccessful();

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'incoming-cc/actions/gather_using_speak')) {
            return false;
        }
        return ($request->data()['timeout_millis'] ?? null) === 2000;
    });
});

it('routes the caller to voicemail when the only admin hangs up before joining the conference', function () {
    $admin = User::factory()->create([
        'first_name' => 'Patryk',
        'cell_phone' => '2249993880',
    ]);

    $this->vendor->update(['options' => (object) [
        'short_name' => 'GS Construction',
        'call_recipients' => [$admin->id],
        'voicemail_enabled' => true,
    ]]);

    $callLog = CallLog::factory()->create([
        'call_control_id' => 'incoming-cc',
        'status' => CallLog::STATUS_ANSWERED,
        'caller_name' => 'Bob Smith',
        'metadata' => [
            'admin_call_control_ids' => ['admin-cc-1'],
            'tts_complete' => true,
        ],
    ]);

    // Conference creation succeeds, but the admin's join fails because the
    // admin leg ended (rejected screening prompt by hanging up).
    app()->forgetInstance('Illuminate\Http\Client\Factory');
    Http::swap(new \Illuminate\Http\Client\Factory());
    Http::fake([
        'api.telnyx.com/v2/conferences/*/actions/join' => Http::response([
            'errors' => [['code' => '90018', 'title' => 'Call has already ended']],
        ], 422),
        'api.telnyx.com/v2/conferences' => Http::response(['data' => ['id' => 'conf-uuid-123']], 200),
        'api.telnyx.com/*' => Http::response(['data' => ['result' => 'ok']], 200),
    ]);

    $this->postJson('/webhooks/telnyx/voice', [
        'data' => [
            'event_type' => 'call.speak.ended',
            'record_type' => 'event',
            'payload' => [
                'call_control_id' => 'admin-cc-1',
                'client_state' => base64_encode(json_encode([
                    'action' => 'admin_screen_done',
                    'call_log_id' => $callLog->id,
                    'incoming_call_control_id' => 'incoming-cc',
                    'admin_user_id' => $admin->id,
                    'conference_id' => null,
                ])),
            ],
        ],
    ])->assertSuccessful();

    // Voicemail IVR must be started on the caller leg so they aren't stranded
    // alone in the empty conference.
    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'incoming-cc/actions/gather_using_speak')
            && $request->method() === 'POST';
    });

    // Voicemail must have been marked as triggered (idempotency) so the
    // subsequent admin call.hangup event doesn't trigger a duplicate menu.
    expect(\Illuminate\Support\Facades\Cache::has("telnyx_voicemail_triggered:{$callLog->id}"))->toBeTrue();
});

it('keeps caller on ringback when first admin hangs up but other admins are still ringing', function () {
    $admin1 = User::factory()->create([
        'first_name' => 'Patryk',
        'cell_phone' => '2249993880',
    ]);

    $admin2 = User::factory()->create([
        'first_name' => 'Alex',
        'cell_phone' => '2249993881',
    ]);

    $this->vendor->update(['options' => (object) [
        'short_name' => 'GS Construction',
        'call_recipients' => [$admin1->id, $admin2->id],
        'voicemail_enabled' => true,
    ]]);

    $callLog = CallLog::factory()->create([
        'call_control_id' => 'incoming-cc',
        'status' => CallLog::STATUS_ANSWERED,
        'caller_name' => 'Bob Smith',
        'metadata' => [
            'admin_call_control_ids' => ['admin-cc-1', 'admin-cc-2'],
            'tts_complete' => true,
        ],
    ]);

    app()->forgetInstance('Illuminate\\Http\\Client\\Factory');
    Http::swap(new \Illuminate\Http\Client\Factory());
    Http::fake([
        'api.telnyx.com/v2/conferences/*/actions/join' => Http::response([
            'errors' => [['code' => '90018', 'title' => 'Call has already ended']],
        ], 422),
        'api.telnyx.com/v2/conferences' => Http::response(['data' => ['id' => 'conf-uuid-123']], 200),
        'api.telnyx.com/*' => Http::response(['data' => ['result' => 'ok']], 200),
    ]);

    $this->postJson('/webhooks/telnyx/voice', [
        'data' => [
            'event_type' => 'call.speak.ended',
            'record_type' => 'event',
            'payload' => [
                'call_control_id' => 'admin-cc-1',
                'client_state' => base64_encode(json_encode([
                    'action' => 'admin_screen_done',
                    'call_log_id' => $callLog->id,
                    'incoming_call_control_id' => 'incoming-cc',
                    'admin_user_id' => $admin1->id,
                    'conference_id' => null,
                ])),
            ],
        ],
    ])->assertSuccessful();

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'incoming-cc/actions/playback_start')) {
            return false;
        }

        $state = json_decode(base64_decode($request->data()['client_state'] ?? ''), true);

        return ($state['action'] ?? null) === 'caller_waiting';
    });

    Http::assertNotSent(function ($request) {
        return str_contains($request->url(), 'incoming-cc/actions/gather_using_speak');
    });
});

it('routes the caller to voicemail when the only joined admin hangs up after entering the conference', function () {
    $admin = User::factory()->create([
        'first_name' => 'Patryk',
        'cell_phone' => '2249993880',
    ]);

    $this->vendor->update(['options' => (object) [
        'short_name' => 'GS Construction',
        'call_recipients' => [$admin->id],
        'voicemail_enabled' => true,
    ]]);

    // Caller is in conference, admin already joined (the production case
    // where the admin briefly joins then immediately hangs up, leaving the
    // caller alone in an empty conference with no audio).
    $callLog = CallLog::factory()->create([
        'call_control_id' => 'incoming-cc',
        'status' => CallLog::STATUS_TRANSFERRED,
        'caller_name' => 'Bob Smith',
        'metadata' => [
            'admin_call_control_ids' => ['admin-cc-1'],
            'joined_admin_ids' => [$admin->id],
            'conference_id' => 'conf-uuid-123',
            'conference_name' => 'call_999_test',
            'tts_complete' => true,
        ],
    ]);

    app()->forgetInstance('Illuminate\\Http\\Client\\Factory');
    Http::swap(new \Illuminate\Http\Client\Factory());
    Http::fake(['api.telnyx.com/*' => Http::response(['data' => ['result' => 'ok']], 200)]);

    $this->postJson('/webhooks/telnyx/voice', [
        'data' => [
            'event_type' => 'call.hangup',
            'record_type' => 'event',
            'payload' => [
                'call_control_id' => 'admin-cc-1',
                'hangup_cause' => 'normal_clearing',
                'client_state' => base64_encode(json_encode([
                    'action' => 'admin_screen',
                    'call_log_id' => $callLog->id,
                    'incoming_call_control_id' => 'incoming-cc',
                    'admin_user_id' => $admin->id,
                ])),
            ],
        ],
    ])->assertSuccessful();

    // The caller must be routed to the voicemail IVR rather than left
    // alone with silence in the empty conference.
    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'incoming-cc/actions/gather_using_speak');
    });

    // The admin must have been removed from joined_admin_ids so subsequent
    // status calculations correctly classify the call as missed/voicemail.
    $callLog->refresh();
    expect($callLog->metadata['joined_admin_ids'] ?? [])->toBeEmpty();
    expect($callLog->metadata['admin_call_control_ids'] ?? [])->toBeEmpty();
});

it('does not route to voicemail when a joined admin hangs up but another recipient is still ringing', function () {
    $joinedAdmin = User::factory()->create([
        'first_name' => 'Patryk',
        'cell_phone' => '2249993880',
    ]);

    $ringingAdmin = User::factory()->create([
        'first_name' => 'Alex',
        'cell_phone' => '2249993881',
    ]);

    $callLog = CallLog::factory()->create([
        'call_control_id' => 'incoming-cc',
        'status' => CallLog::STATUS_TRANSFERRED,
        'caller_name' => 'Bob Smith',
        'metadata' => [
            'admin_call_control_ids' => ['admin-cc-1', 'admin-cc-2'],
            'joined_admin_ids' => [$joinedAdmin->id],
            'conference_id' => 'conf-uuid-123',
            'conference_name' => 'call_999_test',
            'tts_complete' => true,
        ],
    ]);

    app()->forgetInstance('Illuminate\\Http\\Client\\Factory');
    Http::swap(new \Illuminate\Http\Client\Factory());
    Http::fake(['api.telnyx.com/*' => Http::response(['data' => ['result' => 'ok']], 200)]);

    $this->postJson('/webhooks/telnyx/voice', [
        'data' => [
            'event_type' => 'call.hangup',
            'record_type' => 'event',
            'payload' => [
                'call_control_id' => 'admin-cc-1',
                'hangup_cause' => 'normal_clearing',
                'client_state' => base64_encode(json_encode([
                    'action' => 'admin_screen',
                    'call_log_id' => $callLog->id,
                    'incoming_call_control_id' => 'incoming-cc',
                    'admin_user_id' => $joinedAdmin->id,
                ])),
            ],
        ],
    ])->assertSuccessful();

    // Caller should NOT be moved to voicemail while another recipient is still ringing.
    Http::assertNotSent(function ($request) {
        return str_contains($request->url(), 'incoming-cc/actions/gather_using_speak');
    });

    $callLog->refresh();
    expect($callLog->status)->toBe(CallLog::STATUS_TRANSFERRED);
    expect($callLog->metadata['joined_admin_ids'] ?? [])->toBeEmpty();
    expect($callLog->metadata['admin_call_control_ids'] ?? [])->toBe(['admin-cc-2']);
});

it('sends Azure TTS as SSML with friendly style and trailing break to avoid clipping', function () {
    config([
        'services.telnyx.tts_voice' => 'Azure.en-US-AvaMultilingualNeural',
        'services.telnyx.tts_voice_type' => 'azure',
    ]);

    // Pin to Wednesday 11:00 vendor-local time so we're inside default business hours
    \Carbon\Carbon::setTestNow(\Carbon\Carbon::create(2025, 1, 8, 11, 0, 0, 'America/Chicago'));

    $admin = User::factory()->create([
        'first_name' => 'Patryk',
        'cell_phone' => '2249993880',
    ]);

    $this->vendor->update(['options' => (object) [
        'short_name' => 'GS Construction',
        'call_recipients' => [$admin->id],
        'voicemail_enabled' => true,
    ]]);

    $callLog = CallLog::factory()->create([
        'call_control_id' => 'incoming-cc',
        'from_number' => '+12245551234',
    ]);

    Http::fake(['api.telnyx.com/*' => Http::response(['data' => ['result' => 'ok']], 200)]);

    $this->postJson('/webhooks/telnyx/voice', [
        'data' => [
            'event_type' => 'call.answered',
            'record_type' => 'event',
            'payload' => [
                'call_control_id' => 'incoming-cc',
                'client_state' => base64_encode(json_encode([
                    'action' => 'welcome_or_ring',
                    'call_log_id' => $callLog->id,
                    'caller_name' => null,
                    'original_caller' => '+12245551234',
                ])),
            ],
        ],
    ])->assertSuccessful();

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'incoming-cc/actions/speak')) {
            return false;
        }
        $data = $request->data();
        $payload = (string) ($data['payload'] ?? '');

        // Payload must be SSML-wrapped with leading + trailing breaks to
        // prevent clipping of the first and last syllable.
        return ($data['payload_type'] ?? null) === 'ssml'
            && str_starts_with($payload, '<speak')
            && str_ends_with($payload, '</speak>')
            && str_contains($payload, '<break time="400ms"/>')
            && (substr_count($payload, '<break') >= 2)
            && (($data['voice_settings']['type'] ?? null) === 'azure')
            && (($data['voice_settings']['style'] ?? null) === 'friendly');
    });

    \Carbon\Carbon::setTestNow();
});

it('renderPrompt escapes XML-significant characters when emitting SSML for Azure', function () {
    config(['services.telnyx.tts_voice_type' => 'azure']);

    $controller = app(\App\Http\Controllers\Api\TelnyxWebhookController::class);
    $reflection = new \ReflectionMethod($controller, 'renderPrompt');
    $reflection->setAccessible(true);

    $result = $reflection->invoke($controller, "Hi {name}, you're at Bob & Sons", [
        '{name}' => 'A<B>',
        '{company}' => '',
        '{greeting}' => '',
    ]);

    expect($result)
        ->toStartWith('<speak')
        ->toEndWith('</speak>')
        ->toContain('<break time="400ms"/>')
        ->toContain('A&lt;B&gt;')
        ->toContain('Bob &amp; Sons')
        ->not->toContain('<break time="200ms"/>');
});

it('renderPrompt injects only leading and trailing breaks (Azure handles internal prosody)', function () {
    config(['services.telnyx.tts_voice_type' => 'azure', 'services.telnyx.tts_rate' => '+15%']);

    $controller = app(\App\Http\Controllers\Api\TelnyxWebhookController::class);
    $reflection = new \ReflectionMethod($controller, 'renderPrompt');
    $reflection->setAccessible(true);

    $result = $reflection->invoke($controller, 'Good morning, thanks for calling {company}! One moment while we connect you.', [
        '{name}' => '',
        '{company}' => 'GS Construction',
        '{greeting}' => '',
    ]);

    // Exactly two <break> tags: one head-pad (200ms) + one tail-pad (400ms).
    // Azure Neural TTS handles internal sentence/clause prosody from punctuation.
    expect(substr_count($result, '<break'))->toBe(2);
    expect($result)->not->toContain('<break time="200ms"/>');  // only 400ms breaks now
    expect($result)->toContain('<break time="400ms"/>');
    // Prosody rate wrapper applied
    expect($result)->toContain('<prosody rate="+15%">');
    expect($result)->toContain('</prosody>');
    // No mid-sentence break injection
    expect($result)->not->toContain('morning,<break');
    expect($result)->not->toContain('!<break time="400ms"/>One');
});

it('renderPrompt returns plain text with trailing space for non-Azure voices', function () {
    config(['services.telnyx.tts_voice_type' => 'polly']);

    $controller = app(\App\Http\Controllers\Api\TelnyxWebhookController::class);
    $reflection = new \ReflectionMethod($controller, 'renderPrompt');
    $reflection->setAccessible(true);

    $result = $reflection->invoke($controller, 'Hello {name}', [
        '{name}' => 'Bob',
        '{company}' => '',
        '{greeting}' => '',
    ]);

    expect($result)->toBe('Hello Bob. ');
});

it('voicemail IVR gather uses a short 2-second post-prompt timeout', function () {
    $callLog = CallLog::create([
        'call_control_id' => 'incoming-cc',
        'direction' => 'incoming',
        'from_number' => '+15551112222',
        'status' => CallLog::STATUS_INITIATED,
    ]);

    $controller = app(\App\Http\Controllers\Api\TelnyxWebhookController::class);
    $reflection = new \ReflectionMethod($controller, 'startVoicemailGather');
    $reflection->setAccessible(true);

    $reflection->invoke($controller, 'incoming-cc', [
        'call_log_id' => $callLog->id,
        'ivr_prompt' => 'Press 2 to leave a message',
        'valid_digits' => '2',
        'is_known_caller' => false,
        'original_caller' => '+15551112222',
    ]);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'incoming-cc/actions/gather_using_speak')) {
            return false;
        }
        return ($request->data()['timeout_millis'] ?? null) === 2000;
    });
});

it('dispatches voicemail browser notification job when recording is saved as voicemail', function () {
    \Illuminate\Support\Facades\Bus::fake();

    $callLog = CallLog::create([
        'call_control_id' => 'voicemail-cc',
        'direction' => 'incoming',
        'from_number' => '+15551112222',
        'caller_name' => 'John Doe',
        'status' => CallLog::STATUS_INITIATED,
        'duration_seconds' => 12,
    ]);

    $clientState = base64_encode(json_encode([
        'action' => 'voicemail_recording',
        'call_log_id' => $callLog->id,
    ]));

    $this->postJson('/webhooks/telnyx/voice', [
        'data' => [
            'event_type' => 'call.recording.saved',
            'record_type' => 'event',
            'payload' => [
                'call_control_id' => 'voicemail-cc',
                'recording_urls' => ['mp3' => 'https://example.com/rec.mp3'],
                'client_state' => $clientState,
            ],
        ],
    ])->assertSuccessful();

    \Illuminate\Support\Facades\Bus::assertDispatched(\App\Jobs\SendVoicemailBrowserNotifications::class);

    expect($callLog->fresh()->has_voicemail)->toBeTrue();
});

it('does not dispatch voicemail notification job for non-voicemail recordings', function () {
    \Illuminate\Support\Facades\Bus::fake();

    $callLog = CallLog::create([
        'call_control_id' => 'normal-cc',
        'direction' => 'incoming',
        'from_number' => '+15551112222',
        'status' => CallLog::STATUS_ANSWERED,
    ]);

    // No client_state action = not a voicemail
    $this->postJson('/webhooks/telnyx/voice', [
        'data' => [
            'event_type' => 'call.recording.saved',
            'record_type' => 'event',
            'payload' => [
                'call_control_id' => 'normal-cc',
                'recording_urls' => ['mp3' => 'https://example.com/rec.mp3'],
            ],
        ],
    ])->assertSuccessful();

    \Illuminate\Support\Facades\Bus::assertNotDispatched(\App\Jobs\SendVoicemailBrowserNotifications::class);
});

it('discards the recording for a missed call that left no voicemail and never bridged', function () {
    \Illuminate\Support\Facades\Bus::fake();

    // Auto-recording starts at the welcome and keeps rolling through ringback
    // while admins are dialed. This caller hung up before any admin bridged and
    // without leaving a voicemail (status missed, has_voicemail false), so the
    // audio is just TTS + hold music — it must not be saved, transcribed, or
    // AI-analyzed.
    $callLog = CallLog::create([
        'call_control_id' => 'ringing-only-cc',
        'direction' => 'incoming',
        'from_number' => '+15551112222',
        'status' => CallLog::STATUS_MISSED,
        'duration_seconds' => 30,
    ]);

    $clientState = base64_encode(json_encode([
        'action' => 'call_recording',
        'call_log_id' => $callLog->id,
    ]));

    $this->postJson('/webhooks/telnyx/voice', [
        'data' => [
            'event_type' => 'call.recording.saved',
            'record_type' => 'event',
            'payload' => [
                'call_control_id' => 'ringing-only-cc',
                'recording_urls' => ['mp3' => 'https://example.com/rec.mp3'],
                'client_state' => $clientState,
            ],
        ],
    ])->assertSuccessful();

    // No download/transcription pipeline should be kicked off.
    \Illuminate\Support\Facades\Bus::assertNotDispatched(\App\Jobs\StoreCallRecording::class);
    \Illuminate\Support\Facades\Bus::assertNotDispatched(\App\Jobs\SendVoicemailBrowserNotifications::class);

    // The recording fields must be cleared so the transcription/AI command,
    // which selects on recording_path + recording_disk, never picks it up.
    $fresh = $callLog->fresh();
    expect($fresh->recording_url)->toBeNull();
    expect($fresh->recording_path)->toBeNull();
    expect($fresh->recording_disk)->toBeNull();
    expect($fresh->has_voicemail)->toBeFalse();
});

it('keeps the recording for a completed call that had a real conversation', function () {
    \Illuminate\Support\Facades\Bus::fake();

    $callLog = CallLog::create([
        'call_control_id' => 'connected-cc',
        'direction' => 'incoming',
        'from_number' => '+15551112222',
        'status' => CallLog::STATUS_COMPLETED,
        'duration_seconds' => 45,
    ]);

    $clientState = base64_encode(json_encode([
        'action' => 'call_recording',
        'call_log_id' => $callLog->id,
    ]));

    $this->postJson('/webhooks/telnyx/voice', [
        'data' => [
            'event_type' => 'call.recording.saved',
            'record_type' => 'event',
            'payload' => [
                'call_control_id' => 'connected-cc',
                'recording_urls' => ['mp3' => 'https://example.com/rec.mp3'],
                'client_state' => $clientState,
            ],
        ],
    ])->assertSuccessful();

    \Illuminate\Support\Facades\Bus::assertDispatched(\App\Jobs\StoreCallRecording::class);

    $fresh = $callLog->fresh();
    expect($fresh->recording_url)->toBe('https://example.com/rec.mp3');
    expect($fresh->has_voicemail)->toBeFalse();
});

it('CallLogObserver creates AppNotifications for admin recipients on missed status', function () {
    $admin1 = User::factory()->create(['cell_phone' => '5551001001']);
    $admin2 = User::factory()->create(['cell_phone' => '5551001002']);

    $this->vendor->update(['options' => (object) array_merge((array) $this->vendor->options, [
        'call_recipients' => [$admin1->id, $admin2->id],
    ])]);

    $callLog = CallLog::create([
        'call_control_id' => 'missed-cc',
        'direction' => 'incoming',
        'from_number' => '+15551112222',
        'caller_name' => 'Jane Caller',
        'status' => CallLog::STATUS_INITIATED,
    ]);

    $callLog->update(['status' => CallLog::STATUS_MISSED]);

    $notifications = \App\Models\AppNotification::query()
        ->where('type', 'missed_call')
        ->get();

    expect($notifications)->toHaveCount(2);
    expect($notifications->pluck('user_id')->sort()->values()->all())
        ->toEqual(collect([$admin1->id, $admin2->id])->sort()->values()->all());
    expect($notifications->first()->title)->toContain('Jane Caller');
});

// =========================================================================
// Business hours: after-hours calls skip welcome and go straight to voicemail
// =========================================================================

it('skips welcome and routes straight to voicemail when call answered outside business hours', function () {
    $admin = User::factory()->create([
        'first_name' => 'Patryk',
        'cell_phone' => '2249993880',
    ]);

    // Configure Mon–Fri 09:00–17:00 in vendor TZ
    $this->vendor->update(['options' => (object) [
        'short_name' => 'GS Construction',
        'call_recipients' => [$admin->id],
        'voicemail_enabled' => true,
        'business_hours_start' => '09:00',
        'business_hours_end' => '17:00',
        'business_hours_days' => [1, 2, 3, 4, 5],
    ]]);

    // Pin "now" to a Saturday at 10:00 vendor-local time → outside hours
    \Carbon\Carbon::setTestNow(\Carbon\Carbon::create(2025, 1, 4, 10, 0, 0, 'America/Chicago'));

    $callLog = CallLog::factory()->create([
        'call_control_id' => 'after-hours-cc',
        'status' => CallLog::STATUS_INITIATED,
        'from_number' => '+18005550000',
    ]);

    $this->postJson('/webhooks/telnyx/voice', [
        'data' => [
            'event_type' => 'call.answered',
            'record_type' => 'event',
            'payload' => [
                'call_control_id' => 'after-hours-cc',
                'client_state' => base64_encode(json_encode([
                    'action' => 'welcome_or_ring',
                    'call_log_id' => $callLog->id,
                    'original_caller' => '+18005550000',
                    'caller_name' => null,
                    'caller_user_id' => null,
                ])),
            ],
        ],
    ])->assertSuccessful();

    // No welcome speak should have fired on the inbound leg
    Http::assertNotSent(function ($request) {
        return str_contains($request->url(), 'after-hours-cc/actions/speak') && $request->method() === 'POST';
    });

    // No admin dial should have fired
    Http::assertNotSent(function ($request) {
        return str_ends_with($request->url(), '/v2/calls') && $request->method() === 'POST';
    });

    // Voicemail menu (gather_using_speak) should have been invoked
    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'after-hours-cc/actions/gather_using_speak') && $request->method() === 'POST';
    });

    // The after-hours greeting should be spoken first, prepended to the menu
    // prompt inside a single SSML envelope (one <speak> block, greeting before
    // the menu copy). The apostrophe is XML-escaped by the SSML renderer.
    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'after-hours-cc/actions/gather_using_speak') || $request->method() !== 'POST') {
            return false;
        }

        $payload = (string) ($request['payload'] ?? '');
        $greetingPos = strpos($payload, 'reached GS Construction after hours.');
        $menuPos = strpos($payload, 'is not available');

        return $greetingPos !== false
            && $menuPos !== false
            && $greetingPos < $menuPos
            && substr_count($payload, '<speak') === 1;
    });

    \Carbon\Carbon::setTestNow();
});

it('plays the welcome message normally when call answered within business hours', function () {
    $admin = User::factory()->create([
        'first_name' => 'Patryk',
        'cell_phone' => '2249993880',
    ]);

    $this->vendor->update(['options' => (object) [
        'short_name' => 'GS Construction',
        'call_recipients' => [$admin->id],
        'voicemail_enabled' => true,
        'business_hours_start' => '09:00',
        'business_hours_end' => '17:00',
        'business_hours_days' => [1, 2, 3, 4, 5],
    ]]);

    // Wednesday 11:00 vendor-local — inside hours
    \Carbon\Carbon::setTestNow(\Carbon\Carbon::create(2025, 1, 8, 11, 0, 0, 'America/Chicago'));

    $callLog = CallLog::factory()->create([
        'call_control_id' => 'in-hours-cc',
        'status' => CallLog::STATUS_INITIATED,
        'from_number' => '+18005550000',
    ]);

    $this->postJson('/webhooks/telnyx/voice', [
        'data' => [
            'event_type' => 'call.answered',
            'record_type' => 'event',
            'payload' => [
                'call_control_id' => 'in-hours-cc',
                'client_state' => base64_encode(json_encode([
                    'action' => 'welcome_or_ring',
                    'call_log_id' => $callLog->id,
                    'original_caller' => '+18005550000',
                    'caller_name' => null,
                    'caller_user_id' => null,
                ])),
            ],
        ],
    ])->assertSuccessful();

    // Welcome speak should have fired
    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'in-hours-cc/actions/speak') && $request->method() === 'POST';
    });

    // Voicemail menu should NOT have fired
    Http::assertNotSent(function ($request) {
        return str_contains($request->url(), 'in-hours-cc/actions/gather_using_speak') && $request->method() === 'POST';
    });

    \Carbon\Carbon::setTestNow();
});

// =========================================================================
// Spam call → answer + voicemail prompt (instead of hangup)
// =========================================================================

it('answers spam-flagged calls and routes them through the voicemail IVR instead of hanging up', function () {
    // Flag the caller as blocked so the spam filter trips.
    \App\Models\BlockedCaller::create([
        'phone_number' => '+19998887777',
        'vendor_id' => null,
        'reason' => 'manual',
        'auto_blocked' => false,
    ]);

    $this->vendor->update(['options' => (object) [
        'short_name' => 'GS Construction',
        'call_recipients' => [],
        'voicemail_enabled' => true,
        'spam_message' => 'Your call has been identified as spam. Press 2 to send a text or stay on the line for voicemail.',
    ]]);

    // call.initiated → should answer with action=spam_voicemail (NOT hangup)
    $this->postJson('/webhooks/telnyx/voice', [
        'data' => [
            'event_type' => 'call.initiated',
            'record_type' => 'event',
            'payload' => [
                'call_control_id' => 'spam-cc-id',
                'direction' => 'incoming',
                'from' => '+19998887777',
                'to' => '+12247354200',
                'call_session_id' => 'spam-session',
                'call_leg_id' => 'spam-leg',
                'connection_id' => 'spam-conn',
            ],
        ],
    ])->assertSuccessful();

    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'spam-cc-id/actions/hangup'));
    Http::assertSent(function ($r) {
        if (! str_contains($r->url(), 'spam-cc-id/actions/answer')) {
            return false;
        }
        $cs = json_decode(base64_decode($r['client_state']), true);
        return ($cs['action'] ?? null) === 'spam_voicemail';
    });

    expect(CallLog::where('call_control_id', 'spam-cc-id')->value('status'))->toBe(CallLog::STATUS_BLOCKED);

    $callLog = CallLog::where('call_control_id', 'spam-cc-id')->first();

    // call.answered → should fire gather_using_speak with the spam prompt and valid_digits='2'
    $this->postJson('/webhooks/telnyx/voice', [
        'data' => [
            'event_type' => 'call.answered',
            'record_type' => 'event',
            'payload' => [
                'call_control_id' => 'spam-cc-id',
                'client_state' => base64_encode(json_encode([
                    'action' => 'spam_voicemail',
                    'call_log_id' => $callLog->id,
                    'original_caller' => '+19998887777',
                ])),
            ],
        ],
    ])->assertSuccessful();

    Http::assertSent(function ($r) {
        if (! str_contains($r->url(), 'spam-cc-id/actions/gather_using_speak')) {
            return false;
        }
        return ($r['valid_digits'] ?? null) === '2'
            && str_contains((string) ($r['payload'] ?? ''), 'identified as spam');
    });
});
