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
