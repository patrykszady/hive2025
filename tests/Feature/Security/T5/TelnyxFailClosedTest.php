<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::flush();
    Http::fake();
    config(['services.telnyx.api_key' => 'test-key']);
});

/**
 * VerifyTelnyxSignature used to call $next($request) — accept, unverified —
 * whenever TELNYX_PUBLIC_KEY was unset, in every environment. These
 * endpoints start calls, send SMS and mutate call logs, so that was a live
 * hole on any deploy where the key was missing. It now fails closed (403)
 * outside local/testing; local/testing keep working exactly as before (see
 * the existing TelnyxWebhookReliabilityTest coverage, unchanged).
 */
it('fails closed when no public key is configured outside local/testing', function () {
    config(['services.telnyx.public_key' => null]);
    $this->app['env'] = 'production';

    $this->postJson('/webhooks/telnyx/voice', [
        'data' => ['event_type' => 'call.unknown.event', 'record_type' => 'event'],
    ])->assertForbidden();

    $this->postJson('/webhooks/telnyx/messaging', [
        'data' => ['event_type' => 'unknown.message.event', 'payload' => []],
    ])->assertForbidden();
});

it('still skips verification with no key configured in local/testing', function () {
    config(['services.telnyx.public_key' => null]);
    $this->app['env'] = 'local';

    $this->postJson('/webhooks/telnyx/voice', [
        'data' => ['event_type' => 'call.unknown.event', 'record_type' => 'event'],
    ])->assertSuccessful();
});
