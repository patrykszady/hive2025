<?php

use App\Livewire\Transactions\MatchVendor;
use App\Models\Expense;
use App\Models\ExpenseReceipts;
use App\Models\Vendor;
use App\Models\VendorTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../Support/processor-fixtures.php';

uses(RefreshDatabase::class);

/*
 * Match Vendor saves the cards that were filled in and leaves the rest
 * (Patryk, 2026-11-12: "when match as and vendor are both empty we should be
 * able to pass through validation and NOT consider that vendor / card for
 * match / creation"). Before, every card that had ever held a value was
 * required in full — and since the processor prefill (2026-11-11) puts a
 * "Match As" into every card billed through Paddle, PayPal, Square…, one
 * such card left alone blocked the whole save.
 *
 * The Livewire component is driven directly, like ProcessorMatchRuleGuardTest:
 * Livewire::test() cannot round-trip it, and Flux::toast() at the end of a
 * save throws outside a Livewire request, after everything under test has run.
 */
function skipCardsActor(): array
{
    $fixture = processorFixtureCompany();
    $user = $fixture['user'];
    Gate::before(fn ($actor, $ability) => $actor->is($user) ? true : null);
    test()->actingAs($user);

    return $fixture;
}

/** @return array<string, array<int, string>> the validation errors a save raised; [] when it went through */
function skipCardsSave(MatchVendor $component, string $method = 'store'): array
{
    try {
        $component->{$method}();
    } catch (ValidationException $e) {
        return $e->errors();
    } catch (\Throwable $e) {
        if (! str_contains(strtolower($e->getFile()), '/flux/')) {
            throw $e;
        }
    }

    return [];
}

/** Three cards, sorted by descriptor: "DEBIT PURCHASE … DATAFORSE 26248" (0), "E0100KW0UY" (1), "PADDLE.NET* DATAFORSEO" (2). */
function skipCardsThreeCharges(): array
{
    ['account' => $account] = skipCardsActor();

    return [
        'truncated' => processorFixtureTransaction($account, plaidDataforseoTruncated()),
        'microsoft' => processorFixtureTransaction($account, plaidSelfBilledMicrosoft()),
        'full' => processorFixtureTransaction($account, plaidDataforseoFull()),
    ];
}

it('saves the filled-in card and leaves the cards with nothing chosen for later', function (array $untouched) {
    $charges = skipCardsThreeCharges();
    $microsoft = processorFixtureRetailVendor('Microsoft');
    $vendorsBefore = Vendor::withoutGlobalScopes()->count();

    $component = new MatchVendor();
    $component->match_merchant_names = [1 => ['match_desc' => 'E0100KW0UY', 'vendor_id' => (string) $microsoft->id]] + $untouched;

    expect(skipCardsSave($component))->toBe([])
        ->and(VendorTransaction::query()->pluck('desc', 'vendor_id')->all())->toBe([$microsoft->id => 'E0100KW0UY'])
        ->and($charges['microsoft']->refresh()->vendor_id)->toBe($microsoft->id)
        ->and($charges['truncated']->refresh()->vendor_id)->toBeNull()
        ->and($charges['full']->refresh()->vendor_id)->toBeNull()
        ->and(Vendor::withoutGlobalScopes()->count())->toBe($vendorsBefore, 'no vendor is made for a skipped card');
})->with([
    'both boxes emptied' => [[0 => ['match_desc' => '', 'vendor_id' => ''], 2 => ['match_desc' => '   ', 'vendor_id' => null]]],
    'the prefilled Match As left as it came, no vendor' => [[0 => ['match_desc' => 'DATAFORSE'], 2 => ['match_desc' => 'DATAFORSE', 'vendor_id' => '']]],
    'Match As emptied, the vendor never touched' => [[0 => ['match_desc' => ''], 2 => ['match_desc' => '']]],
]);

it('saves nothing and raises nothing when every card was left alone', function () {
    $charges = skipCardsThreeCharges();

    $component = new MatchVendor();
    $component->match_merchant_names = [0 => ['match_desc' => '', 'vendor_id' => ''], 2 => ['match_desc' => 'DATAFORSE']];

    expect(skipCardsSave($component))->toBe([])
        ->and(VendorTransaction::query()->count())->toBe(0)
        ->and($charges['truncated']->refresh()->vendor_id)->toBeNull()
        ->and($charges['full']->refresh()->vendor_id)->toBeNull();
});

it('still says what is missing on a card that was half filled in, and saves none of them', function () {
    $charges = skipCardsThreeCharges();
    $microsoft = processorFixtureRetailVendor('Microsoft');

    $component = new MatchVendor();
    $component->match_merchant_names = [
        // Typed over the prefill, no vendor picked.
        0 => ['match_desc' => 'DataForSEO LLC', 'vendor_id' => ''],
        // Fully filled in — held back with the rest until the others are fixed.
        1 => ['match_desc' => 'E0100KW0UY', 'vendor_id' => (string) $microsoft->id],
        // A vendor picked, the text to match on emptied.
        2 => ['match_desc' => '', 'vendor_id' => 'NEW'],
    ];

    expect(skipCardsSave($component))->toBe([
        'match_merchant_names.2.match_desc' => ['Type what to match on, or clear the vendor to leave this card for later.'],
        'match_merchant_names.0.vendor_id' => ['Pick a vendor, or clear Match As to leave this card for later.'],
    ])
        ->and(VendorTransaction::query()->count())->toBe(0)
        ->and($charges['microsoft']->refresh()->vendor_id)->toBeNull()
        // The rows stay as typed for the person to finish.
        ->and($component->match_merchant_names[0]['match_desc'])->toBe('DataForSEO LLC')
        ->and($component->match_merchant_names[2]['vendor_id'])->toBe('NEW');
});

it('keeps every box as it was when a save is refused, the left-out cards included', function () {
    skipCardsThreeCharges();
    $submitted = [
        // The prefill, untouched: left out of the save, and never an error.
        0 => ['match_desc' => 'DATAFORSE'],
        // Something typed, no vendor: the one thing to fix.
        1 => ['match_desc' => 'E0100KW0UY', 'vendor_id' => ''],
    ];

    $component = new MatchVendor();
    $component->match_merchant_names = $submitted;

    expect(array_keys(skipCardsSave($component)))->toBe(['match_merchant_names.1.vendor_id'])
        ->and($component->match_merchant_names)->toBe($submitted);
});

it('does not flag a box the moment it is emptied, only once its other box holds something', function (string $field, string $otherBox) {
    skipCardsThreeCharges();

    $errorsAfterEditing = function (array $row) use ($field): array {
        $component = new MatchVendor();
        $component->match_merchant_names = [2 => $row];

        try {
            $component->updated($field);
        } catch (ValidationException $e) {
            return array_keys($e->errors());
        }

        return [];
    };

    expect($errorsAfterEditing(['match_desc' => '', 'vendor_id' => '']))->toBe([])
        ->and($errorsAfterEditing(['match_desc' => '', 'vendor_id' => '', $otherBox => 'NEW']))->toBe([$field]);
})->with([
    ['match_merchant_names.2.match_desc', 'vendor_id'],
    ['match_merchant_names.2.vendor_id', 'match_desc'],
]);

it('leaves a receipt card with nothing chosen out of the receipt save too', function () {
    ['company' => $company, 'user' => $user] = skipCardsActor();
    $metwood = processorFixtureRetailVendor('Metwood');

    // Receipt cards sort by merchant name: "ACE HARDWARE" (0), "METWOOD INC" (1).
    $expenses = collect(['ACE HARDWARE', 'METWOOD INC'])->map(function (string $merchant) use ($company, $user) {
        $expense = Expense::withoutGlobalScopes()->create([
            'amount' => -75.00, 'date' => '2026-10-01', 'vendor_id' => 0,
            'belongs_to_vendor_id' => $company->id, 'created_by_user_id' => $user->id,
        ]);
        ExpenseReceipts::create([
            'expense_id' => $expense->id, 'receipt_filename' => 'r.pdf', 'receipt_html' => '<div></div>',
            'receipt_items' => ['items' => [], 'total' => '75.00', 'merchant_name' => $merchant],
        ]);

        return $expense;
    });

    $component = new MatchVendor();
    expect($component->expenseCards->keys()->all())->toBe(['ACE HARDWARE', 'METWOOD INC']);
    $component->match_expense_merchant_names = [
        0 => ['match_desc' => '', 'vendor_id' => ''],
        1 => ['match_desc' => 'METWOOD INC', 'vendor_id' => (string) $metwood->id],
    ];

    expect(skipCardsSave($component, 'store_expense_vendors'))->toBe([])
        ->and(VendorTransaction::query()->pluck('desc', 'vendor_id')->all())->toBe([$metwood->id => 'METWOOD INC'])
        ->and((int) Expense::withoutGlobalScopes()->find($expenses[0]->id)->vendor_id)->toBe(0, 'the skipped receipt keeps no vendor');

    $half = new MatchVendor();
    $half->match_expense_merchant_names = [0 => ['match_desc' => 'ACE HARDWARE', 'vendor_id' => '']];
    expect(skipCardsSave($half, 'store_expense_vendors'))->toBe([
        'match_expense_merchant_names.0.vendor_id' => ['Pick a vendor, or clear Match As to leave this card for later.'],
    ]);
});

it('says there is nothing to save, and runs nothing, when every card was left empty', function (string $form) {
    // A receipt the sweep would otherwise file: the save must not reach it.
    ['account' => $account, 'company' => $company, 'user' => $user] = processorFixtureCompany();
    Gate::before(fn ($actor, $ability) => $actor->is($user) ? true : null);
    test()->actingAs($user);
    $expense = Expense::withoutGlobalScopes()->create(['amount' => -9.99, 'date' => '2026-10-01', 'vendor_id' => 0, 'belongs_to_vendor_id' => $company->id, 'created_by_user_id' => $user->id]);
    ExpenseReceipts::create(['expense_id' => $expense->id, 'receipt_filename' => 'r.pdf', 'receipt_html' => '<div></div>', 'receipt_items' => ['items' => [], 'total' => '9.99', 'merchant_name' => 'Known Shop']]);
    VendorTransaction::create(['vendor_id' => processorFixtureRetailVendor('Known Shop')->id, 'desc' => 'Known Shop', 'options' => json_encode('/i')]);

    $component = new MatchVendor();
    $component->match_merchant_names = [0 => ['match_desc' => '', 'vendor_id' => '']];
    $component->match_expense_merchant_names = [0 => ['match_desc' => '', 'vendor_id' => '']];

    $toast = null;
    try {
        $component->{$form}();
    } catch (\Throwable $e) {
        // Flux::toast() throws outside a Livewire request — after the decision under test.
        $toast = $e;
    }

    expect($toast)->not->toBeNull('the person is told')
        ->and((int) Expense::withoutGlobalScopes()->find($expense->id)->vendor_id)->toBe(0, 'no sweep ran')
        ->and(VendorTransaction::query()->count())->toBe(1);
})->with(['store_expense_vendors', 'store']);
