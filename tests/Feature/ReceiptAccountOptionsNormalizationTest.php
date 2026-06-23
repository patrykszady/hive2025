<?php

use App\Models\ReceiptAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('normalizes double-encoded options JSON', function () {
    $account = ReceiptAccount::create([
        'vendor_id' => 54,
        'belongs_to_vendor_id' => 1,
        'options' => json_encode([
            'refresh_token' => 'refresh-token-1',
            'access_token' => 'access-token-1',
            'expires_in' => now()->addMinutes(10)->toIso8601String(),
        ]),
    ]);

    expect($account->normalizedOptions())->toMatchArray([
        'refresh_token' => 'refresh-token-1',
        'access_token' => 'access-token-1',
    ]);

    expect($account->hasAmazonRefreshToken())->toBeTrue();
    expect($account->amazonRefreshToken())->toBe('refresh-token-1');
    expect($account->amazonAccessToken())->toBe('access-token-1');
    expect($account->amazonExpiresAt())->not->toBeNull();
});

it('returns empty options for malformed data', function () {
    $account = ReceiptAccount::create([
        'vendor_id' => 54,
        'belongs_to_vendor_id' => 1,
        'options' => 'not-json',
    ]);

    expect($account->normalizedOptions())->toBe([]);
    expect($account->hasAmazonRefreshToken())->toBeFalse();
    expect($account->amazonRefreshToken())->toBeNull();
    expect($account->amazonAccessToken())->toBeNull();
    expect($account->amazonExpiresAt())->toBeNull();
});

it('merges normalized options updates', function () {
    $account = ReceiptAccount::create([
        'vendor_id' => 54,
        'belongs_to_vendor_id' => 1,
        'options' => json_encode([
            'refresh_token' => 'refresh-token-1',
            'access_token' => 'access-token-1',
        ]),
    ]);

    $account->mergeOptions([
        'access_token' => 'access-token-2',
        'expires_in' => now()->addMinutes(55)->toIso8601String(),
    ]);

    expect($account->normalizedOptions())->toMatchArray([
        'refresh_token' => 'refresh-token-1',
        'access_token' => 'access-token-2',
    ]);
    expect($account->amazonExpiresAt())->not->toBeNull();
});
