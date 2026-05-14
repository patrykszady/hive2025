<?php

use App\Http\Controllers\TransactionController;

function callPayeeMatch(string $payee, string $vendor): bool
{
    $controller = app(TransactionController::class);
    $reflection = new ReflectionMethod(TransactionController::class, 'payeeNameMatchesVendor');
    $reflection->setAccessible(true);

    return $reflection->invoke($controller, $payee, $vendor);
}

it('matches when payee shares a meaningful token with vendor business name', function () {
    expect(callPayeeMatch('MORENOS', 'Morenos Drywall, Inc'))->toBeTrue();
    expect(callPayeeMatch('MORENOS DRYWALL', 'Morenos Drywall, Inc'))->toBeTrue();
});

it('does not match when payee names refer to different parties', function () {
    expect(callPayeeMatch('GRZEGORZ', 'Morenos Drywall, Inc'))->toBeFalse();
    expect(callPayeeMatch('JOHN DOE', 'Morenos Drywall, Inc'))->toBeFalse();
});

it('ignores common business suffixes when comparing', function () {
    // "Inc" / "Corp" / "LLC" alone should not produce a false positive
    expect(callPayeeMatch('INC', 'Morenos Drywall, Inc'))->toBeFalse();
    expect(callPayeeMatch('LLC', 'Acme LLC'))->toBeFalse();
});

it('handles punctuation and case variations consistently', function () {
    expect(callPayeeMatch('morenos-drywall', 'MORENOS DRYWALL INC'))->toBeTrue();
    expect(callPayeeMatch('Morenos, Drywall', 'morenos drywall'))->toBeTrue();
});

it('returns false when either side has no significant tokens', function () {
    expect(callPayeeMatch('', 'Morenos Drywall, Inc'))->toBeFalse();
    expect(callPayeeMatch('MORENOS', ''))->toBeFalse();
    // Three-letter tokens are filtered out (too short to be meaningful)
    expect(callPayeeMatch('CTI', 'CTI'))->toBeFalse();
});
