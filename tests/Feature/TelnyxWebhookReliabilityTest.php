<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::flush();
    Http::fake();
    config(['services.telnyx.api_key' => 'test-key']);
});

/**
 * Build the headers + signed body Telnyx would send for a given payload.
 *
 * @return array{0: string, 1: array<string, string>}
 */
function signedTelnyxRequest(array $payload, string $secretKey): array
{
    $content = json_encode($payload);
    $timestamp = (string) time();
    $signature = base64_encode(sodium_crypto_sign_detached($timestamp . '|' . $content, $secretKey));

    return [$content, [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_TELNYX_SIGNATURE_ED25519' => $signature,
        'HTTP_TELNYX_TIMESTAMP' => $timestamp,
    ]];
}

it('skips signature verification when no public key is configured', function () {
    config(['services.telnyx.public_key' => null]);

    $this->postJson('/webhooks/telnyx/voice', [
        'data' => ['event_type' => 'call.unknown.event', 'record_type' => 'event'],
    ])->assertSuccessful();
});

it('rejects a voice webhook with no signature when a public key is configured', function () {
    $keypair = sodium_crypto_sign_keypair();
    config(['services.telnyx.public_key' => base64_encode(sodium_crypto_sign_publickey($keypair))]);

    $this->postJson('/webhooks/telnyx/voice', [
        'data' => ['event_type' => 'call.unknown.event', 'record_type' => 'event'],
    ])->assertForbidden();
});

it('rejects a voice webhook with an invalid signature', function () {
    $keypair = sodium_crypto_sign_keypair();
    config(['services.telnyx.public_key' => base64_encode(sodium_crypto_sign_publickey($keypair))]);

    $this->call('POST', '/webhooks/telnyx/voice', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_TELNYX_SIGNATURE_ED25519' => base64_encode(str_repeat("\0", SODIUM_CRYPTO_SIGN_BYTES)),
        'HTTP_TELNYX_TIMESTAMP' => (string) time(),
    ], json_encode(['data' => ['event_type' => 'call.unknown.event', 'record_type' => 'event']]))
        ->assertForbidden();
});

it('accepts a voice webhook with a valid signature', function () {
    $keypair = sodium_crypto_sign_keypair();
    config(['services.telnyx.public_key' => base64_encode(sodium_crypto_sign_publickey($keypair))]);

    [$content, $headers] = signedTelnyxRequest([
        'data' => ['event_type' => 'call.unknown.event', 'record_type' => 'event'],
    ], sodium_crypto_sign_secretkey($keypair));

    $this->call('POST', '/webhooks/telnyx/voice', [], [], [], $headers, $content)
        ->assertSuccessful();
});

it('rejects a voice webhook with a stale timestamp', function () {
    $keypair = sodium_crypto_sign_keypair();
    config([
        'services.telnyx.public_key' => base64_encode(sodium_crypto_sign_publickey($keypair)),
        'services.telnyx.webhook_tolerance' => 300,
    ]);

    $content = json_encode(['data' => ['event_type' => 'call.unknown.event', 'record_type' => 'event']]);
    $timestamp = (string) (time() - 600);
    $signature = base64_encode(sodium_crypto_sign_detached($timestamp . '|' . $content, sodium_crypto_sign_secretkey($keypair)));

    $this->call('POST', '/webhooks/telnyx/voice', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_TELNYX_SIGNATURE_ED25519' => $signature,
        'HTTP_TELNYX_TIMESTAMP' => $timestamp,
    ], $content)->assertForbidden();
});

it('ignores a duplicate voice webhook event by id', function () {
    config(['services.telnyx.public_key' => null]);

    $payload = [
        'data' => [
            'id' => 'evt-dedup-1',
            'event_type' => 'call.unknown.event',
            'record_type' => 'event',
        ],
    ];

    $this->postJson('/webhooks/telnyx/voice', $payload)
        ->assertSuccessful()
        ->assertJsonMissing(['message' => 'duplicate event ignored']);

    $this->postJson('/webhooks/telnyx/voice', $payload)
        ->assertSuccessful()
        ->assertJson(['message' => 'duplicate event ignored']);
});

it('does not treat events without an id as duplicates', function () {
    config(['services.telnyx.public_key' => null]);

    $payload = [
        'data' => ['event_type' => 'call.unknown.event', 'record_type' => 'event'],
    ];

    $this->postJson('/webhooks/telnyx/voice', $payload)
        ->assertSuccessful()
        ->assertJsonMissing(['message' => 'duplicate event ignored']);

    $this->postJson('/webhooks/telnyx/voice', $payload)
        ->assertSuccessful()
        ->assertJsonMissing(['message' => 'duplicate event ignored']);
});

it('ignores a duplicate messaging webhook event by id', function () {
    config(['services.telnyx.public_key' => null]);

    $payload = [
        'data' => [
            'id' => 'evt-msg-dedup-1',
            'event_type' => 'unknown.message.event',
            'payload' => [],
        ],
    ];

    $this->postJson('/webhooks/telnyx/messaging', $payload)->assertSuccessful();

    $this->postJson('/webhooks/telnyx/messaging', $payload)
        ->assertSuccessful()
        ->assertJson(['message' => 'duplicate event ignored']);
});
