<?php

use App\Livewire\Expenses\ExpenseIndex;

/**
 * The Expenses card's amount search is a Meilisearch filter over the
 * expense total and its split amounts.
 *
 * Typed digits are where the amount begins (Patryk, 2026-10-13: "579.61
 * shouldn't show for 570 but for 57 yes"). A decimal point fixes the
 * dollars, and two decimals are an exact amount.
 *
 * split_amounts is a list, and two comparisons joined by AND may each be met
 * by a different split: "570" found a $1,036.97 expense split 600 + 120
 * (600 >= 570, 120 < 571) and 39 more like it. Every range is one TO
 * condition, which a single split has to satisfy.
 */
function expenseAmountFilter(string $amount): ?string
{
    $component = new ExpenseIndex;
    $component->amount = $amount;

    return (new ReflectionMethod($component, 'buildAmountFilter'))->invoke($component);
}

/** Would an expense whose $field holds $value pass the filter? */
function expenseAmountFilterMatches(?string $filter, string $field, float $value): bool
{
    preg_match_all('/\b'.$field.' (-?[\d.]+) TO (-?[\d.]+)/', (string) $filter, $ranges, PREG_SET_ORDER);
    foreach ($ranges as [, $min, $max]) {
        if ($value >= (float) $min && $value <= (float) $max) {
            return true;
        }
    }
    preg_match_all('/\b'.$field.' = (-?[\d.]+)/', (string) $filter, $exact);

    return in_array($value, array_map('floatval', $exact[1]), true);
}

it('finds the amounts that begin with the digits typed', function (string $typed, array $found, array $notFound): void {
    $filter = expenseAmountFilter($typed);

    foreach (['amount', 'split_amounts'] as $field) {
        foreach ($found as $value) {
            expect(expenseAmountFilterMatches($filter, $field, $value))->toBeTrue("{$typed} should find {$field} {$value}");
        }
        foreach ($notFound as $value) {
            expect(expenseAmountFilterMatches($filter, $field, $value))->toBeFalse("{$typed} should not find {$field} {$value}");
        }
    }
})->with([
    '57' => ['57', [57.00, 57.99, 570.41, 579.61, 5712.30, -579.61], [58.00, 56.99, 157.00, 0.57, 580.00]],
    '570' => ['570', [570.00, 570.41, 570.99, 5705.10, -570.41], [579.61, 571.00, 57.10, 1570.00]],
    'a decimal point fixes the dollars' => ['57.', [57.00, 57.12, 57.99], [579.61, 570.41, 58.00]],
    'one decimal is ten cents' => ['570.4', [570.40, 570.41, 570.49], [570.39, 570.50, 579.61]],
    'two decimals are exact' => ['579.61', [579.61, -579.61], [579.6, 579.62, 57.96]],
]);

it('never joins two comparisons with AND, which different splits could each satisfy', function (): void {
    $filter = expenseAmountFilter('570');

    expect($filter)->not->toContain(' AND ')
        ->and($filter)->not->toContain('>=')
        ->and($filter)->toContain('split_amounts 570 TO 570.99')
        ->and($filter)->toContain('split_amounts 5700 TO 5709.99');
});

it('ignores the sign: refunds are covered either way', function (): void {
    expect(expenseAmountFilter('-57'))->toBe(expenseAmountFilter('57'));
});

it('adds no amount filter for an empty or non-numeric box', function (string $amount): void {
    expect(expenseAmountFilter($amount))->toBeNull();
})->with(['empty' => '', 'text' => 'abc']);
