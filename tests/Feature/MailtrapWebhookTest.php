<?php

use App\Models\EmailTracking;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

beforeEach(function () {
    if (Schema::hasTable('email_tracking')) {
        return;
    }

    Schema::create('email_tracking', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('belongs_to_vendor_id')->nullable();
        $table->unsignedBigInteger('project_id')->nullable();
        $table->string('message_id')->nullable();
        $table->string('thread_id')->nullable();
        $table->string('email_template_name')->nullable();
        $table->string('event_type');
        $table->json('recipient_emails')->nullable();
        $table->text('link_url')->nullable();
        $table->string('ip_address')->nullable();
        $table->text('user_agent')->nullable();
        $table->json('metadata')->nullable();
        $table->dateTime('event_at')->nullable();
        $table->timestamps();
    });

    if (! Schema::hasTable('vendors')) {
        Schema::create('vendors', function (Blueprint $table): void {
            $table->id();
            $table->string('business_name')->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('zip_code')->nullable();
            $table->string('business_type')->nullable();
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

    if (! Schema::hasTable('user_vendor')) {
        Schema::create('user_vendor', function (Blueprint $table): void {
            $table->id();
            $table->integer('user_id')->unsigned();
            $table->integer('vendor_id')->unsigned();
            $table->integer('role_id')->default(1);
            $table->integer('via_vendor_id')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->integer('is_employed')->default(1);
            $table->decimal('hourly_rate')->default(0);
            $table->timestamps();
        });
    }
});

it('ignores opened events for the sender email', function () {
    config(['email_tracking.mailtrap_webhook_token' => 'test-token']);
    config(['email_tracking.mailtrap_filter_sender_opens' => true]);

    $trackingId = (string) Str::uuid();

    EmailTracking::create([
        'message_id' => $trackingId,
        'event_type' => 'sent',
        'recipient_emails' => ['customer@example.com'],
        'metadata' => [
            'sender_email' => 'sender@example.com',
            'tracking_id' => $trackingId,
        ],
        'event_at' => now()->subMinute(),
    ]);

    $payload = [
        'events' => [[
            'event_type' => 'opened',
            'recipient_email' => 'sender@example.com',
            'tracking_id' => $trackingId,
            'message_id' => 'provider-msg-1',
            'event_id' => 'provider-evt-1',
            'timestamp' => now()->toIso8601String(),
            'user_agent' => 'Mozilla/5.0',
        ]],
    ];

    $this->postJson('/webhooks/mailtrap/test-token', $payload)->assertSuccessful();

    expect(EmailTracking::query()->where('message_id', $trackingId)->where('event_type', 'opened')->count())
        ->toBe(0);
});

it('persists opened events for real recipients', function () {
    config(['email_tracking.mailtrap_webhook_token' => 'test-token']);
    config(['email_tracking.mailtrap_filter_sender_opens' => true]);

    $trackingId = (string) Str::uuid();

    EmailTracking::create([
        'message_id' => $trackingId,
        'event_type' => 'sent',
        'recipient_emails' => ['customer@example.com'],
        'metadata' => [
            'sender_email' => 'sender@example.com',
            'tracking_id' => $trackingId,
        ],
        'event_at' => now()->subMinute(),
    ]);

    $payload = [
        'events' => [[
            'event_type' => 'opened',
            'recipient_email' => 'customer@example.com',
            'tracking_id' => $trackingId,
            'message_id' => 'provider-msg-1',
            'event_id' => 'provider-evt-2',
            'timestamp' => now()->toIso8601String(),
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
        ]],
    ];

    $this->postJson('/webhooks/mailtrap/test-token', $payload)->assertSuccessful();

    $tracking = EmailTracking::query()
        ->where('message_id', $trackingId)
        ->where('event_type', 'opened')
        ->first();

    expect($tracking)->not->toBeNull();
    expect($tracking->recipient_emails)->toContain('customer@example.com');
    expect(($tracking->metadata ?? [])['source'] ?? null)->toBe('mailtrap_webhook');
    expect(($tracking->metadata ?? [])['mailtrap_event_id'] ?? null)->toBe('provider-evt-2');
});

it('ignores opened events for the sent from email', function () {
    config(['email_tracking.mailtrap_webhook_token' => 'test-token']);
    config(['email_tracking.mailtrap_filter_sender_opens' => true]);

    $trackingId = (string) Str::uuid();
    $fromEmail = 'no-reply@example.com';

    EmailTracking::create([
        'message_id' => $trackingId,
        'event_type' => 'sent',
        'recipient_emails' => ['customer@example.com'],
        'metadata' => [
            'sender_email' => 'sender@example.com',
            'from_email' => $fromEmail,
            'tracking_id' => $trackingId,
        ],
        'event_at' => now()->subMinute(),
    ]);

    $payload = [
        'events' => [[
            'event_type' => 'opened',
            'recipient_email' => $fromEmail,
            'tracking_id' => $trackingId,
            'message_id' => 'provider-msg-1',
            'event_id' => 'provider-evt-3',
            'timestamp' => now()->toIso8601String(),
            'user_agent' => 'Mozilla/5.0',
        ]],
    ];

    $this->postJson('/webhooks/mailtrap/test-token', $payload)->assertSuccessful();

    expect(EmailTracking::query()->where('message_id', $trackingId)->where('event_type', 'opened')->count())
        ->toBe(0);
});

it('ignores opened events from internal domain email addresses', function () {
    config(['email_tracking.mailtrap_webhook_token' => 'test-token']);
    config(['email_tracking.mailtrap_filter_sender_opens' => true]);
    config(['email_tracking.internal_domains' => ['mycompany.com', 'staff.example.com']]);

    $trackingId = (string) Str::uuid();

    EmailTracking::create([
        'message_id' => $trackingId,
        'event_type' => 'sent',
        'recipient_emails' => ['vendor@external.com', 'alice@mycompany.com'],
        'metadata' => [
            'sender_email' => 'bob@mycompany.com',
            'from_email' => 'no-reply@mycompany.com',
            'tracking_id' => $trackingId,
        ],
        'event_at' => now()->subMinute(),
    ]);

    // Open from internal domain should be ignored
    $payload = [
        'events' => [[
            'event_type' => 'opened',
            'recipient_email' => 'alice@mycompany.com',
            'tracking_id' => $trackingId,
            'message_id' => 'provider-msg-1',
            'event_id' => 'provider-evt-internal',
            'timestamp' => now()->toIso8601String(),
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
        ]],
    ];

    $this->postJson('/webhooks/mailtrap/test-token', $payload)->assertSuccessful();

    expect(EmailTracking::query()->where('message_id', $trackingId)->where('event_type', 'opened')->count())
        ->toBe(0, 'Open from internal domain should be ignored');

    // Open from external domain should be tracked
    $payloadExternal = [
        'events' => [[
            'event_type' => 'opened',
            'recipient_email' => 'vendor@external.com',
            'tracking_id' => $trackingId,
            'message_id' => 'provider-msg-1',
            'event_id' => 'provider-evt-external',
            'timestamp' => now()->addSeconds(10)->toIso8601String(),
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
        ]],
    ];

    $this->postJson('/webhooks/mailtrap/test-token', $payloadExternal)->assertSuccessful();

    expect(EmailTracking::query()->where('message_id', $trackingId)->where('event_type', 'opened')->count())
        ->toBe(1, 'Open from external domain should be tracked');
});

it('ignores opened events for emails not in the original recipient list', function () {
    config(['email_tracking.mailtrap_webhook_token' => 'test-token']);
    config(['email_tracking.mailtrap_filter_sender_opens' => true]);

    $trackingId = (string) Str::uuid();

    EmailTracking::create([
        'message_id' => $trackingId,
        'event_type' => 'sent',
        'recipient_emails' => ['customer@example.com'],
        'metadata' => [
            'from_email' => 'no-reply@example.com',
            'sender_email' => 'sender@example.com',
            'tracking_id' => $trackingId,
        ],
        'event_at' => now()->subMinute(),
    ]);

    $payload = [
        'events' => [[
            'event_type' => 'opened',
            'recipient_email' => 'someone-else@example.com',
            'tracking_id' => $trackingId,
            'message_id' => 'provider-msg-1',
            'event_id' => 'provider-evt-4',
            'timestamp' => now()->toIso8601String(),
            'user_agent' => 'Mozilla/5.0',
        ]],
    ];

    $this->postJson('/webhooks/mailtrap/test-token', $payload)->assertSuccessful();

    expect(EmailTracking::query()->where('message_id', $trackingId)->where('event_type', 'opened')->count())
        ->toBe(0);
});

it('ignores webhook events that are not linked to an app tracked sent record', function () {
    config(['email_tracking.mailtrap_webhook_token' => 'test-token']);

    $trackingId = (string) Str::uuid();

    $payload = [
        'events' => [[
            'event_type' => 'delivered',
            'recipient_email' => 'someone@example.com',
            'tracking_id' => $trackingId,
            'message_id' => 'provider-msg-orphan',
            'event_id' => 'provider-evt-orphan-1',
            'timestamp' => now()->toIso8601String(),
            'user_agent' => 'Mailtrap/1.0 (Webhook; +https://help.mailtrap.io/article/102-webhooks)',
        ], [
            'event_type' => 'opened',
            'recipient_email' => 'someone@example.com',
            'tracking_id' => $trackingId,
            'message_id' => 'provider-msg-orphan',
            'event_id' => 'provider-evt-orphan-2',
            'timestamp' => now()->addSecond()->toIso8601String(),
            'user_agent' => 'Mozilla/5.0',
        ]],
    ];

    $this->postJson('/webhooks/mailtrap/test-token', $payload)->assertSuccessful();

    expect(EmailTracking::query()->where('message_id', $trackingId)->count())
        ->toBe(0);
});

it('ignores opened events from vendor team members', function () {
    config(['email_tracking.mailtrap_webhook_token' => 'test-token']);
    config(['email_tracking.mailtrap_filter_sender_opens' => true]);

    $vendorId = \Illuminate\Support\Facades\DB::table('vendors')->insertGetId([
        'business_name' => 'Test Vendor',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $teamMemberId = \Illuminate\Support\Facades\DB::table('users')->insertGetId([
        'first_name' => 'Greg',
        'last_name' => 'Test',
        'email' => 'greg@gs.construction',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    \Illuminate\Support\Facades\DB::table('user_vendor')->insert([
        'user_id' => $teamMemberId,
        'vendor_id' => $vendorId,
    ]);

    $trackingId = (string) Str::uuid();

    EmailTracking::create([
        'belongs_to_vendor_id' => $vendorId,
        'message_id' => $trackingId,
        'event_type' => 'sent',
        'recipient_emails' => ['client@example.com', 'greg@gs.construction'],
        'metadata' => [
            'sender_email' => 'no-reply@app.com',
            'tracking_id' => $trackingId,
        ],
        'event_at' => now()->subMinute(),
    ]);

    // Open from team member should be ignored
    $payload = [
        'events' => [[
            'event_type' => 'opened',
            'recipient_email' => 'greg@gs.construction',
            'tracking_id' => $trackingId,
            'message_id' => 'provider-msg-team',
            'event_id' => 'provider-evt-team-1',
            'timestamp' => now()->toIso8601String(),
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
        ]],
    ];

    $this->postJson('/webhooks/mailtrap/test-token', $payload)->assertSuccessful();

    expect(EmailTracking::query()->where('message_id', $trackingId)->where('event_type', 'opened')->count())
        ->toBe(0, 'Open from vendor team member should be ignored');

    // Open from client should be tracked
    $clientPayload = [
        'events' => [[
            'event_type' => 'opened',
            'recipient_email' => 'client@example.com',
            'tracking_id' => $trackingId,
            'message_id' => 'provider-msg-client',
            'event_id' => 'provider-evt-client-1',
            'timestamp' => now()->addSeconds(10)->toIso8601String(),
            'user_agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X)',
        ]],
    ];

    $this->postJson('/webhooks/mailtrap/test-token', $clientPayload)->assertSuccessful();

    expect(EmailTracking::query()->where('message_id', $trackingId)->where('event_type', 'opened')->count())
        ->toBe(1, 'Open from client should be tracked');
});

it('allows delivered events from vendor team members but ignores their opens', function () {
    config(['email_tracking.mailtrap_webhook_token' => 'test-token']);
    config(['email_tracking.mailtrap_filter_sender_opens' => true]);

    $vendorId = \Illuminate\Support\Facades\DB::table('vendors')->insertGetId([
        'business_name' => 'Test Vendor',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $teamMemberId = \Illuminate\Support\Facades\DB::table('users')->insertGetId([
        'first_name' => 'Patryk',
        'last_name' => 'Test',
        'email' => 'patryk@gs.construction',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    \Illuminate\Support\Facades\DB::table('user_vendor')->insert([
        'user_id' => $teamMemberId,
        'vendor_id' => $vendorId,
    ]);

    $trackingId = (string) Str::uuid();

    EmailTracking::create([
        'belongs_to_vendor_id' => $vendorId,
        'message_id' => $trackingId,
        'event_type' => 'sent',
        'recipient_emails' => ['patryk@gs.construction', 'client@example.com'],
        'metadata' => [
            'sender_email' => 'no-reply@app.com',
            'tracking_id' => $trackingId,
        ],
        'event_at' => now()->subMinute(),
    ]);

    // Delivered event from team member should still be tracked
    $deliveredPayload = [
        'events' => [[
            'event_type' => 'delivered',
            'recipient_email' => 'patryk@gs.construction',
            'tracking_id' => $trackingId,
            'message_id' => 'provider-msg-d1',
            'event_id' => 'provider-evt-d1',
            'timestamp' => now()->toIso8601String(),
        ]],
    ];

    $this->postJson('/webhooks/mailtrap/test-token', $deliveredPayload)->assertSuccessful();

    expect(EmailTracking::query()->where('message_id', $trackingId)->where('event_type', 'delivered')->count())
        ->toBe(1, 'Delivered events from team members should still be tracked');

    // Opened event from same team member should be ignored
    $openedPayload = [
        'events' => [[
            'event_type' => 'opened',
            'recipient_email' => 'patryk@gs.construction',
            'tracking_id' => $trackingId,
            'message_id' => 'provider-msg-o1',
            'event_id' => 'provider-evt-o1',
            'timestamp' => now()->addSeconds(60)->toIso8601String(),
            'user_agent' => 'Mozilla/5.0',
        ]],
    ];

    $this->postJson('/webhooks/mailtrap/test-token', $openedPayload)->assertSuccessful();

    expect(EmailTracking::query()->where('message_id', $trackingId)->where('event_type', 'opened')->count())
        ->toBe(0, 'Opened events from team members should be ignored');
});
