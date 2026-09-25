<?php

use App\Support\PlaidWebhookVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/** @return array{0: \OpenSSLAsymmetricKey, 1: string, 2: string} [privateKey, rawX, rawY] */
function sec5GenerateEcKeyPair(): array
{
    $keyPair = openssl_pkey_new([
        'curve_name' => 'prime256v1',
        'private_key_type' => OPENSSL_KEYTYPE_EC,
    ]);

    $details = openssl_pkey_get_details($keyPair);

    return [$keyPair, $details['ec']['x'], $details['ec']['y']];
}

function sec5B64Url(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/** Converts an openssl_sign() DER ECDSA signature to raw JOSE r||s (64 bytes for P-256). */
function sec5DerSignatureToJose(string $der): string
{
    $offset = 1; // skip SEQUENCE tag
    $offset++; // skip sequence length byte (P-256 sigs are always < 128 bytes)

    $offset++; // skip INTEGER tag for r
    $rLen = ord($der[$offset]);
    $offset++;
    $r = substr($der, $offset, $rLen);
    $offset += $rLen;

    $offset++; // skip INTEGER tag for s
    $sLen = ord($der[$offset]);
    $offset++;
    $s = substr($der, $offset, $sLen);

    $r = str_pad(ltrim($r, "\x00"), 32, "\x00", STR_PAD_LEFT);
    $s = str_pad(ltrim($s, "\x00"), 32, "\x00", STR_PAD_LEFT);

    return $r.$s;
}

/**
 * @param \OpenSSLAsymmetricKey $privateKey
 * @param array<string, mixed> $payloadOverrides
 */
function sec5SignPlaidJwt($privateKey, string $kid, array $payloadOverrides = []): string
{
    $header = ['alg' => 'ES256', 'kid' => $kid, 'typ' => 'JWT'];
    $payload = array_merge([
        'iat' => time(),
        'request_body_sha256' => hash('sha256', ''),
    ], $payloadOverrides);

    $signingInput = sec5B64Url(json_encode($header)).'.'.sec5B64Url(json_encode($payload));

    openssl_sign($signingInput, $derSignature, $privateKey, OPENSSL_ALGO_SHA256);

    return $signingInput.'.'.sec5B64Url(sec5DerSignatureToJose($derSignature));
}

function sec5FakePlaidKeyEndpoint(string $kid, string $x, string $y): void
{
    Http::fake([
        'https://sandbox.plaid.com/webhook_verification_key/get' => Http::response([
            'key' => [
                'alg' => 'ES256',
                'crv' => 'P-256',
                'kid' => $kid,
                'kty' => 'EC',
                'use' => 'sig',
                'x' => sec5B64Url($x),
                'y' => sec5B64Url($y),
            ],
            'request_id' => 'req-1',
        ], 200),
    ]);
}

function sec5PlaidVerifier(): PlaidWebhookVerifier
{
    return new PlaidWebhookVerifier('test-client-id', 'test-secret', 'https://sandbox.plaid.com');
}

it('verifies a correctly signed JWT against the fetched key', function () {
    [$privateKey, $x, $y] = sec5GenerateEcKeyPair();
    $kid = 'good-key-'.uniqid();
    sec5FakePlaidKeyEndpoint($kid, $x, $y);

    $body = '{"webhook_type":"ITEM"}';
    $jwt = sec5SignPlaidJwt($privateKey, $kid, ['request_body_sha256' => hash('sha256', $body)]);

    expect(sec5PlaidVerifier()->verify($jwt, $body))->toBeTrue();
});

it('rejects a JWT signed by a different key than the one Plaid serves for that kid', function () {
    [, $x, $y] = sec5GenerateEcKeyPair();
    [$otherPrivateKey] = sec5GenerateEcKeyPair();
    $kid = 'mismatched-key-'.uniqid();
    sec5FakePlaidKeyEndpoint($kid, $x, $y);

    $body = '{"webhook_type":"ITEM"}';
    $jwt = sec5SignPlaidJwt($otherPrivateKey, $kid, ['request_body_sha256' => hash('sha256', $body)]);

    expect(sec5PlaidVerifier()->verify($jwt, $body))->toBeFalse();
});

it('rejects a JWT whose iat is stale', function () {
    [$privateKey, $x, $y] = sec5GenerateEcKeyPair();
    $kid = 'stale-key-'.uniqid();
    sec5FakePlaidKeyEndpoint($kid, $x, $y);

    $body = '{"webhook_type":"ITEM"}';
    $jwt = sec5SignPlaidJwt($privateKey, $kid, [
        'iat' => time() - 3600,
        'request_body_sha256' => hash('sha256', $body),
    ]);

    expect(sec5PlaidVerifier()->verify($jwt, $body))->toBeFalse();
});

it('rejects a JWT whose request_body_sha256 does not match the actual body', function () {
    [$privateKey, $x, $y] = sec5GenerateEcKeyPair();
    $kid = 'body-mismatch-key-'.uniqid();
    sec5FakePlaidKeyEndpoint($kid, $x, $y);

    $jwt = sec5SignPlaidJwt($privateKey, $kid, ['request_body_sha256' => hash('sha256', 'this is not the body')]);

    expect(sec5PlaidVerifier()->verify($jwt, '{"webhook_type":"ITEM"}'))->toBeFalse();
});

it('rejects a JWT with a non-ES256 alg', function () {
    $header = sec5B64Url(json_encode(['alg' => 'HS256', 'kid' => 'whatever']));
    $payload = sec5B64Url(json_encode(['iat' => time(), 'request_body_sha256' => hash('sha256', '')]));

    expect(sec5PlaidVerifier()->verify($header.'.'.$payload.'.'.sec5B64Url('fake-signature'), ''))->toBeFalse();
});

it('rejects a malformed JWT', function () {
    expect(sec5PlaidVerifier()->verify('not-a-jwt', '{}'))->toBeFalse();
});

it('rejects the plaid webhook controller request when the signature is invalid', function () {
    config([
        'services.plaid.env' => 'sandbox',
        'services.plaid.client_id' => 'test-client-id',
        'services.plaid.secret' => 'test-secret',
        'services.plaid.force_webhook_verification' => true,
    ]);

    $this->postJson('/webhooks/plaid', [
        'webhook_type' => 'ITEM',
        'webhook_code' => 'ERROR',
        'item_id' => 'item-123',
    ], ['Plaid-Verification' => 'not-a-real-jwt'])
        ->assertStatus(401);
});

it('rejects the plaid webhook controller request when there is no Plaid-Verification header', function () {
    config([
        'services.plaid.env' => 'sandbox',
        'services.plaid.client_id' => 'test-client-id',
        'services.plaid.secret' => 'test-secret',
        'services.plaid.force_webhook_verification' => true,
    ]);

    $this->postJson('/webhooks/plaid', [
        'webhook_type' => 'ITEM',
        'webhook_code' => 'ERROR',
        'item_id' => 'item-123',
    ])->assertStatus(401);
});

it('accepts the plaid webhook controller request when the JWT verifies', function () {
    [$privateKey, $x, $y] = sec5GenerateEcKeyPair();
    $kid = 'controller-good-key-'.uniqid();

    config([
        'services.plaid.env' => 'sandbox',
        'services.plaid.client_id' => 'test-client-id',
        'services.plaid.secret' => 'test-secret',
        'services.plaid.force_webhook_verification' => true,
    ]);

    sec5FakePlaidKeyEndpoint($kid, $x, $y);

    $body = ['webhook_type' => 'ITEM', 'webhook_code' => 'ERROR', 'item_id' => 'item-does-not-exist'];
    $rawBody = json_encode($body);
    $jwt = sec5SignPlaidJwt($privateKey, $kid, ['request_body_sha256' => hash('sha256', $rawBody)]);

    $this->postJson('/webhooks/plaid', $body, ['Plaid-Verification' => $jwt])
        ->assertOk()
        ->assertJson(['status' => 'ignored', 'reason' => 'unknown item_id']);
});

it('skips verification in testing unless force_webhook_verification is set', function () {
    config([
        'services.plaid.env' => 'sandbox',
        'services.plaid.client_id' => 'test-client-id',
        'services.plaid.secret' => 'test-secret',
        'services.plaid.force_webhook_verification' => false,
    ]);

    $this->postJson('/webhooks/plaid', [
        'webhook_type' => 'ITEM',
        'webhook_code' => 'ERROR',
        'item_id' => 'item-123',
    ])->assertOk();
});
