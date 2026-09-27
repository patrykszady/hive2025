<?php

use App\Support\Google\ServiceAccountToken;

/**
 * GSC_CREDENTIALS takes the service-account JSON as a file path, inline,
 * or base64 on one line — the inline forms keep production's key in the
 * private environment instead of a file copied onto the server by hand.
 */
$json = json_encode(['type' => 'service_account', 'client_email' => 'sa@example.iam.gserviceaccount.com', 'private_key' => 'not-a-real-key', 'token_uri' => 'https://oauth2.googleapis.com/token']);

it('is configured by a readable file', function () use ($json) {
    $path = tempnam(sys_get_temp_dir(), 'sa');
    file_put_contents($path, $json);

    expect((new ServiceAccountToken($path, 'scope'))->isConfigured())->toBeTrue();

    unlink($path);
});

it('is configured by the JSON inline', function () use ($json) {
    expect((new ServiceAccountToken($json, 'scope'))->isConfigured())->toBeTrue();
});

it('is configured by the JSON base64-encoded on one line', function () use ($json) {
    expect((new ServiceAccountToken(base64_encode($json), 'scope'))->isConfigured())->toBeTrue();
});

it('is not configured by a missing file, an empty value or unrelated text', function () {
    expect((new ServiceAccountToken('/nowhere/sa.json', 'scope'))->isConfigured())->toBeFalse()
        ->and((new ServiceAccountToken(null, 'scope'))->isConfigured())->toBeFalse()
        ->and((new ServiceAccountToken('hello there', 'scope'))->isConfigured())->toBeFalse();
});
