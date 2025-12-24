<?php

use App\Models\EmailTracking;
use App\Services\EmailTrackingService;

it('returns a transparent gif pixel for valid tracking request', function () {
    $trackingService = app(EmailTrackingService::class);

    $url = $trackingService->generateTrackingUrl(
        messageId: 'test-message-' . uniqid(),
        recipientEmail: 'test@example.com',
        projectId: null,
    );

    $response = $this->get($url);

    $response->assertStatus(200);
    $response->assertHeader('Content-Type', 'image/gif');
});

it('records an open event with recipient email', function () {
    $trackingService = app(EmailTrackingService::class);

    $messageId = 'test-message-' . uniqid();
    $recipientEmail = 'recipient@example.com';

    $url = $trackingService->generateTrackingUrl(
        messageId: $messageId,
        recipientEmail: $recipientEmail,
        projectId: null,
    );

    $this->get($url);

    $tracking = EmailTracking::where('message_id', $messageId)
        ->where('event_type', 'opened')
        ->first();

    expect($tracking)->not->toBeNull();
    expect($tracking->recipient_emails)->toContain($recipientEmail);
    expect($tracking->metadata['source'])->toBe('tracking_pixel');
});

it('records open event with project_id when provided', function () {
    $trackingService = app(EmailTrackingService::class);

    $messageId = 'test-message-' . uniqid();
    $recipientEmail = 'recipient@example.com';
    $projectId = 999;  // Use a static ID, no factory needed

    $url = $trackingService->generateTrackingUrl(
        messageId: $messageId,
        recipientEmail: $recipientEmail,
        projectId: $projectId,
    );

    $this->get($url);

    $tracking = EmailTracking::where('message_id', $messageId)
        ->where('event_type', 'opened')
        ->first();

    expect($tracking)->not->toBeNull();
    expect($tracking->project_id)->toBe($projectId);
});

it('records open event with email template name', function () {
    $trackingService = app(EmailTrackingService::class);

    $messageId = 'test-message-' . uniqid();
    $recipientEmail = 'recipient@example.com';
    $templateName = 'Review Email';

    $url = $trackingService->generateTrackingUrl(
        messageId: $messageId,
        recipientEmail: $recipientEmail,
        projectId: null,
        threadId: null,
        emailTemplateName: $templateName,
    );

    $this->get($url);

    $tracking = EmailTracking::where('message_id', $messageId)->first();

    expect($tracking->email_template_name)->toBe($templateName);
});

it('suppresses duplicate opens within 5 minutes', function () {
    $trackingService = app(EmailTrackingService::class);

    $messageId = 'test-message-' . uniqid();
    $recipientEmail = 'recipient@example.com';

    $url = $trackingService->generateTrackingUrl(
        messageId: $messageId,
        recipientEmail: $recipientEmail,
    );

    // First request should create a record
    $this->get($url);
    
    // Second request should be suppressed
    $this->get($url);

    $count = EmailTracking::where('message_id', $messageId)
        ->where('event_type', 'opened')
        ->count();

    expect($count)->toBe(1);
});

it('returns pixel for invalid token without error', function () {
    $response = $this->get('/t/o?t=invalid-token');

    $response->assertStatus(200);
    $response->assertHeader('Content-Type', 'image/gif');
});

it('returns pixel when no token provided', function () {
    $response = $this->get('/t/o');

    $response->assertStatus(200);
    $response->assertHeader('Content-Type', 'image/gif');
});

it('rejects tokens with invalid signatures', function () {
    // Create a valid token structure but with wrong signature
    $data = [
        'mid' => 'test-message-invalid-sig-' . uniqid(),
        'r' => 'test@example.com',
        'ts' => time(),
        'sig' => 'invalid-signature',
    ];
    $token = base64_encode(json_encode($data));

    $this->get('/t/o?t=' . $token);

    // Should not create any tracking record due to invalid signature
    $count = EmailTracking::where('message_id', $data['mid'])->count();

    expect($count)->toBe(0);
});
