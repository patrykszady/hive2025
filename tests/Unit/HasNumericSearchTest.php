<?php

use App\Traits\HasNumericSearch;

/**
 * Test fixture exposing the protected static numeric-search helper so the
 * pure parsing logic can be asserted without a Meilisearch round trip.
 */
class NumericSearchFixture
{
    use HasNumericSearch;

    /**
     * @param  array<int, string>  $filterConditions
     * @return array{0: string, 1: array<int, string>}
     */
    public static function run(string $searchQuery, array $filterConditions = []): array
    {
        return self::processNumericSearch($searchQuery, $filterConditions);
    }
}

it('builds an exact amount filter for two-decimal amounts', function (string $input, float $value): void {
    [$actualQuery, $filters] = NumericSearchFixture::run($input);

    expect($actualQuery)->toBe('')
        ->and($filters)->toBe(["(amount = {$value} OR amount = -{$value})"]);
})->with([
    'trailing zero cents' => ['6.30', 6.3],
    'standard cents' => ['95.57', 95.57],
    'whole dollars two decimals' => ['6.00', 6.0],
]);

it('builds an exact amount filter for a single trailing-zero decimal', function (): void {
    [$actualQuery, $filters] = NumericSearchFixture::run('6.0');

    expect($actualQuery)->toBe('')
        ->and($filters)->toBe(['(amount = 6 OR amount = -6)']);
});

it('falls back to text search for partial decimals', function (string $input): void {
    [$actualQuery, $filters] = NumericSearchFixture::run($input);

    expect($actualQuery)->toBe($input)
        ->and($filters)->toBe([]);
})->with([
    'single non-zero decimal' => ['6.5'],
    'one decimal place' => ['62.1'],
    'bare integer' => ['69'],
]);

it('builds a range filter for amounts ending in a decimal point', function (): void {
    [$actualQuery, $filters] = NumericSearchFixture::run('62.');

    expect($actualQuery)->toBe('')
        ->and($filters)->toBe(['((amount >= 62 AND amount < 63) OR (amount > -63 AND amount <= -62))']);
});
