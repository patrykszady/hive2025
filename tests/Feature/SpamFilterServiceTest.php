<?php

use App\Services\SpamFilterService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->service = Mockery::mock(SpamFilterService::class)
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();
    // Default: phone not blocked, auto-block is a no-op
    $this->service->shouldReceive('isPhoneBlocked')->andReturn(false)->byDefault();
    $this->service->shouldReceive('autoBlock')->byDefault();
});

function makeContext(array $overrides = []): array
{
    return array_merge([
        'phone_number' => '+15551234567',
        'vendor_id' => 1,
        'is_known_caller' => false,
        'stir_shaken_attestation' => null,
        'spam_sensitivity' => 'medium',
    ], $overrides);
}

// ── Blocked callers list ────────────────────────────────────

it('blocks a phone number on the blocked callers list', function () {
    $this->service->shouldReceive('isPhoneBlocked')
        ->with('+15551234567', 1)
        ->andReturn(true);

    $result = $this->service->evaluate(makeContext());

    expect($result['blocked'])->toBeTrue()
        ->and($result['reason'])->toBe('blocked_list');
});

// ── Known callers ───────────────────────────────────────────

it('always allows known callers', function () {
    $result = $this->service->evaluate(makeContext([
        'is_known_caller' => true,
        'stir_shaken_attestation' => null,
    ]));

    expect($result['blocked'])->toBeFalse();
});

// ── STIR/SHAKEN attestation ────────────────────────────────

it('allows calls with A attestation', function () {
    Http::fake(); // Ensure no IPQS calls

    $result = $this->service->evaluate(makeContext([
        'stir_shaken_attestation' => 'A',
    ]));

    expect($result['blocked'])->toBeFalse();
    Http::assertNothingSent();
});

it('blocks calls with no attestation on medium sensitivity', function () {
    $this->service->shouldReceive('autoBlock')->once()
        ->with('+15551234567', 1, Mockery::type('string'));

    $result = $this->service->evaluate(makeContext([
        'stir_shaken_attestation' => null,
        'spam_sensitivity' => 'medium',
    ]));

    expect($result['blocked'])->toBeTrue()
        ->and($result['reason'])->toBe('low_attestation');
});

it('blocks C attestation on high sensitivity', function () {
    $this->service->shouldReceive('autoBlock')->once()
        ->with('+15551234567', 1, Mockery::type('string'));

    $result = $this->service->evaluate(makeContext([
        'stir_shaken_attestation' => 'C',
        'spam_sensitivity' => 'high',
    ]));

    expect($result['blocked'])->toBeTrue()
        ->and($result['reason'])->toBe('low_attestation');
});

it('does not block C attestation on medium sensitivity', function () {
    Http::fake([
        'ipqualityscore.com/*' => Http::response([
            'success' => true,
            'fraud_score' => 20,
            'active' => true,
            'valid' => true,
            'risky' => false,
            'VOIP' => false,
        ]),
    ]);

    $result = $this->service->evaluate(makeContext([
        'stir_shaken_attestation' => 'C',
        'spam_sensitivity' => 'medium',
    ]));

    expect($result['blocked'])->toBeFalse();
});

it('does not block by attestation on low sensitivity', function () {
    Http::fake([
        'ipqualityscore.com/*' => Http::response([
            'success' => true,
            'fraud_score' => 10,
            'active' => true,
            'valid' => true,
            'risky' => false,
            'VOIP' => false,
        ]),
    ]);

    $result = $this->service->evaluate(makeContext([
        'stir_shaken_attestation' => null,
        'spam_sensitivity' => 'low',
    ]));

    expect($result['blocked'])->toBeFalse();
});

// ── ipqualityscore spam database ────────────────────────────

it('blocks calls with high spam score', function () {
    Http::fake([
        'ipqualityscore.com/*' => Http::response([
            'success' => true,
            'fraud_score' => 90,
            'active' => true,
            'valid' => true,
            'risky' => true,
            'VOIP' => true,
        ]),
    ]);

    config(['services.ipqualityscore.api_key' => 'test-key']);
    Cache::flush();

    $this->service->shouldReceive('autoBlock')->once();

    $result = $this->service->evaluate(makeContext([
        'stir_shaken_attestation' => 'B',
        'spam_sensitivity' => 'medium',
    ]));

    expect($result['blocked'])->toBeTrue()
        ->and($result['reason'])->toBe('spam_score');
});

it('allows calls with low spam score', function () {
    Http::fake([
        'ipqualityscore.com/*' => Http::response([
            'success' => true,
            'fraud_score' => 30,
            'active' => true,
            'valid' => true,
            'risky' => false,
            'VOIP' => false,
        ]),
    ]);

    config(['services.ipqualityscore.api_key' => 'test-key']);
    Cache::flush();

    $result = $this->service->evaluate(makeContext([
        'stir_shaken_attestation' => 'B',
        'spam_sensitivity' => 'medium',
    ]));

    expect($result['blocked'])->toBeFalse();
});

it('skips ipqualityscore when API key is not configured', function () {
    Http::fake();
    config(['services.ipqualityscore.api_key' => null]);

    $result = $this->service->evaluate(makeContext([
        'stir_shaken_attestation' => 'B',
    ]));

    expect($result['blocked'])->toBeFalse();
    Http::assertNothingSent();
});

it('caches ipqualityscore results', function () {
    Http::fake([
        'ipqualityscore.com/*' => Http::response([
            'success' => true,
            'fraud_score' => 30,
            'active' => true,
            'valid' => true,
            'risky' => false,
            'VOIP' => false,
        ]),
    ]);

    config(['services.ipqualityscore.api_key' => 'test-key']);
    Cache::flush();

    // First call — hits the API
    $this->service->evaluate(makeContext(['stir_shaken_attestation' => 'B']));

    // Second call — should use cache
    $this->service->evaluate(makeContext(['stir_shaken_attestation' => 'B']));

    Http::assertSentCount(1);
});

// ── Sensitivity thresholds ──────────────────────────────────

it('uses correct spam score thresholds per sensitivity for non-VOIP', function (string $sensitivity, int $score, bool $expectBlocked) {
    Http::fake([
        'ipqualityscore.com/*' => Http::response([
            'success' => true,
            'fraud_score' => $score,
            'active' => true,
            'valid' => true,
            'risky' => false,
            'VOIP' => false,
            'spammer' => false,
        ]),
    ]);

    config(['services.ipqualityscore.api_key' => 'test-key']);
    Cache::flush();

    $result = $this->service->evaluate(makeContext([
        'stir_shaken_attestation' => 'B',
        'spam_sensitivity' => $sensitivity,
    ]));

    expect($result['blocked'])->toBe($expectBlocked);
})->with([
    'low sensitivity, score 94 — allows' => ['low', 94, false],
    'low sensitivity, score 95 — blocks' => ['low', 95, true],
    'medium sensitivity, score 84 — allows' => ['medium', 84, false],
    'medium sensitivity, score 85 — blocks' => ['medium', 85, true],
    'high sensitivity, score 74 — allows' => ['high', 74, false],
    'high sensitivity, score 75 — blocks' => ['high', 75, true],
]);

it('uses stricter thresholds for VOIP numbers', function (string $sensitivity, int $score, bool $expectBlocked) {
    Http::fake([
        'ipqualityscore.com/*' => Http::response([
            'success' => true,
            'fraud_score' => $score,
            'active' => true,
            'valid' => true,
            'risky' => true,
            'VOIP' => true,
            'spammer' => false,
        ]),
    ]);

    config(['services.ipqualityscore.api_key' => 'test-key']);
    Cache::flush();

    $result = $this->service->evaluate(makeContext([
        'stir_shaken_attestation' => 'B',
        'spam_sensitivity' => $sensitivity,
    ]));

    expect($result['blocked'])->toBe($expectBlocked);
})->with([
    'low sensitivity, VOIP score 74 — allows' => ['low', 74, false],
    'low sensitivity, VOIP score 75 — blocks' => ['low', 75, true],
    'medium sensitivity, VOIP score 64 — allows' => ['medium', 64, false],
    'medium sensitivity, VOIP score 65 — blocks' => ['medium', 65, true],
    'high sensitivity, VOIP score 49 — allows' => ['high', 49, false],
    'high sensitivity, VOIP score 50 — blocks' => ['high', 50, true],
]);

it('blocks calls flagged as spammer regardless of score', function () {
    Http::fake([
        'ipqualityscore.com/*' => Http::response([
            'success' => true,
            'fraud_score' => 10,
            'active' => true,
            'valid' => true,
            'risky' => false,
            'VOIP' => false,
            'spammer' => true,
        ]),
    ]);

    config(['services.ipqualityscore.api_key' => 'test-key']);
    Cache::flush();

    $this->service->shouldReceive('autoBlock')->once();

    $result = $this->service->evaluate(makeContext([
        'stir_shaken_attestation' => 'B',
        'spam_sensitivity' => 'low',
    ]));

    expect($result['blocked'])->toBeTrue()
        ->and($result['reason'])->toBe('spam_score');
});
