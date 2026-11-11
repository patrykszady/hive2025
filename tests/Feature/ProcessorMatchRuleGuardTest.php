<?php

use App\Livewire\Transactions\MatchVendor;
use App\Models\Expense;
use App\Models\ExpenseReceipts;
use App\Models\Transaction;
use App\Models\Vendor;
use App\Models\VendorTransaction;
use App\Services\Transactions\TransactionVendorMatcher;
use App\Services\VendorSuggestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;

require_once __DIR__.'/../Support/processor-fixtures.php';

uses(RefreshDatabase::class);

/**
 * Prevention at the source for the Laravel Daily / DataForSEO mix-up
 * (2026-11-10): no path that writes a vendor_transactions rule — the Match
 * Vendor page, the app API, an accepted AI suggestion — writes one that
 * names only a payment processor ("PADDLE.NET", "PAYPAL *"), and every
 * place that proposes a match text proposes the merchant part instead
 * ("DATAFORSE", cut to the bank's 21-character window so it claims the
 * truncated "DATAFORSE 26248" form too).
 *
 * The Livewire component is driven directly: Livewire::test() cannot
 * round-trip it (see MatchVendorApplySuggestionTest). Flux::toast() at the
 * end of a store throws outside a Livewire request, after everything under
 * test has run.
 */
function guardActor(?int $companyId = null): array
{
    $fixture = processorFixtureCompany($companyId);
    $user = $fixture['user'];
    Gate::before(fn ($actor, $ability) => $actor->is($user) ? true : null);
    test()->actingAs($user);

    return $fixture;
}

function guardStore(MatchVendor $component, string $method = 'store'): void
{
    try {
        $component->{$method}();
    } catch (\Throwable $e) {
        if (! str_contains(strtolower($e->getFile()), '/flux/')) {
            throw $e;
        }
    }
}

/** @return array<string, array<int, string>> the validation errors a store attempt raised */
function guardErrors(MatchVendor $component, string $method = 'store'): array
{
    try {
        guardStore($component, $method);
    } catch (ValidationException $e) {
        return $e->errors();
    }

    return [];
}

it('refuses a Match Vendor rule that names only the processor', function (string $matchDesc, string $message) {
    ['account' => $account] = guardActor();
    $laravelDaily = processorFixtureRetailVendor('Laravel Daily');
    $charge = processorFixtureTransaction($account, plaidDataforseoFull());

    $component = new MatchVendor();
    $component->match_merchant_names = [0 => ['match_desc' => $matchDesc, 'vendor_id' => (string) $laravelDaily->id]];

    expect(guardErrors($component))->toBe(['match_merchant_names.0.match_desc' => [$message]])
        ->and(VendorTransaction::query()->count())->toBe(0)
        ->and($charge->refresh()->vendor_id)->toBeNull();
})->with([
    'PADDLE.NET' => ['PADDLE.NET', 'PADDLE.NET is a payment processor many companies bill through. Match on the company name after it instead, like DATAFORSE.'],
    'PAYPAL *' => ['PAYPAL *', 'PAYPAL * is a payment processor many companies bill through. Match on the company name after it instead, like DATAFORSE.'],
]);

it('accepts a merchant-specific match text and claims both DataForSEO forms with it', function () {
    ['account' => $account] = guardActor();
    $laravelDaily = processorFixtureRetailVendor('Laravel Daily');
    $dataforseo = processorFixtureRetailVendor('DataForSEO');
    paddleRule280($laravelDaily);

    // Cards sort by descriptor: "DEBIT PURCHASE …" first, "PADDLE.NET* DATAFORSEO" second.
    $truncated = processorFixtureTransaction($account, plaidDataforseoTruncated());
    $full = processorFixtureTransaction($account, plaidDataforseoFull());

    $component = new MatchVendor();
    $component->match_merchant_names = [1 => ['match_desc' => 'DATAFORSE', 'vendor_id' => (string) $dataforseo->id]];
    guardStore($component);

    expect(VendorTransaction::query()->where('vendor_id', $dataforseo->id)->value('desc'))->toBe('DATAFORSE')
        ->and($full->refresh()->vendor_id)->toBe($dataforseo->id)
        ->and($truncated->refresh()->vendor_id)->toBe($dataforseo->id);
});

it('names a NEW vendor after the merchant and writes the merchant rule for it', function (string $typed, string $expectedName) {
    ['account' => $account] = guardActor();
    $truncated = processorFixtureTransaction($account, plaidDataforseoTruncated());
    $full = processorFixtureTransaction($account, plaidDataforseoFull());

    $component = new MatchVendor();
    $component->match_merchant_names = [1 => ['match_desc' => $typed, 'vendor_id' => 'NEW']];
    guardStore($component);

    $vendor = Vendor::withoutGlobalScopes()->where('business_type', 'Retail')->latest('id')->first();

    expect($vendor->business_name)->toBe($expectedName)
        ->and(VendorTransaction::query()->where('vendor_id', $vendor->id)->value('desc'))->toBe(str_replace('*', '\*', $typed))
        ->and($full->refresh()->vendor_id)->toBe($vendor->id);

    // The prefilled prefix is what makes the truncated "DATAFORSE 26248" form
    // follow (its rule claims it in PART 3). Whether the full-text rule's
    // vendor also picks it up by fuzzy match is incidental — and
    // fuzzyMatchVendor()'s per-process static alias cache makes that answer
    // depend on which tests ran before in the same worker.
    if ($typed === 'DATAFORSE') {
        expect($truncated->refresh()->vendor_id)->toBe($vendor->id);
    }
})->with([
    'the prefilled prefix becomes the whole merchant' => ['DATAFORSE', 'Dataforseo'],
    'the processor prefix is dropped from the name' => ['PADDLE.NET* DATAFORSEO', 'Dataforseo'],
]);

it('lets a bare processor charge be matched on the processor, the only text it has', function () {
    ['account' => $account] = guardActor();
    $laravelDaily = processorFixtureRetailVendor('Laravel Daily');
    $bare = processorFixtureTransaction($account, plaidLaravelDailyBare());

    $component = new MatchVendor();
    $component->match_merchant_names = [0 => ['match_desc' => 'PADDLE.NET', 'vendor_id' => (string) $laravelDaily->id]];
    guardStore($component);

    expect(VendorTransaction::query()->where('vendor_id', $laravelDaily->id)->value('desc'))->toBe('PADDLE.NET')
        ->and($bare->refresh()->vendor_id)->toBe($laravelDaily->id);
});

it('refuses a receipt-expense rule that names only the processor', function () {
    ['company' => $company, 'user' => $user] = guardActor();
    $vendor = processorFixtureRetailVendor('Metwood');

    $expense = Expense::withoutGlobalScopes()->create([
        'amount' => -75.00, 'date' => '2026-10-01', 'vendor_id' => 0,
        'belongs_to_vendor_id' => $company->id, 'created_by_user_id' => $user->id,
    ]);
    ExpenseReceipts::create([
        'expense_id' => $expense->id, 'receipt_filename' => 'pp.pdf', 'receipt_html' => '<div></div>',
        'receipt_items' => ['items' => [], 'total' => '75.00', 'merchant_name' => 'PAYPAL *METWOOD INC'],
    ]);

    $component = new MatchVendor();
    $component->match_expense_merchant_names = [0 => ['match_desc' => 'PAYPAL *', 'vendor_id' => (string) $vendor->id]];

    expect(guardErrors($component, 'store_expense_vendors'))
        ->toBe(['match_expense_merchant_names.0.match_desc' => ['PAYPAL * is a payment processor many companies bill through. Match on the company name after it instead.']])
        ->and(VendorTransaction::query()->count())->toBe(0);
});

it('prefills "Match As" with the merchant part and explains the processor under it', function () {
    ['user' => $user, 'account' => $account] = guardActor();
    // Cards sort by descriptor: "DEBIT PURCHASE …" (0), "E0100KW0UY" (1), "PADDLE.NET* DATAFORSEO" (2).
    processorFixtureTransaction($account, plaidDataforseoTruncated());
    processorFixtureTransaction($account, plaidSelfBilledMicrosoft());
    processorFixtureTransaction($account, plaidDataforseoFull());

    $component = new MatchVendor();
    $component->match_merchant_names = [2 => ['match_desc' => 'DataForSEO LLC', 'vendor_id' => '']];

    $cards = $component->processorCards();

    expect($cards)->toHaveKeys([0, 2])
        ->and($cards)->not->toHaveKey(1)
        ->and($cards[0])->toBe(['note' => 'Billed through Paddle, a payment processor many companies bill through — the merchant is DATAFORSE.', 'match_desc' => 'DATAFORSE'])
        ->and($cards[2]['match_desc'])->toBe('DATAFORSE')
        ->and($component->match_merchant_names[0]['match_desc'])->toBe('DATAFORSE')
        // A typed value is never overwritten.
        ->and($component->match_merchant_names[2]['match_desc'])->toBe('DataForSEO LLC');

    // The rendered page carries the note under the box and the prefill in its state.
    $rendered = Livewire::actingAs($user)->test(MatchVendor::class);

    expect($rendered->html())->toContain('the merchant is Dataforseo.')
        ->and($rendered->get('match_merchant_names')[2]['match_desc'] ?? null)->toBe('DATAFORSE')
        ->and($rendered->get('match_merchant_names')[1]['match_desc'] ?? null)->toBeNull();
});

it('refuses a processor-only match through the app API and accepts the merchant text', function () {
    ['user' => $user, 'account' => $account] = guardActor(1);
    $laravelDaily = processorFixtureRetailVendor('Laravel Daily');
    $dataforseo = processorFixtureRetailVendor('DataForSEO');
    paddleRule280($laravelDaily);
    $full = processorFixtureTransaction($account, plaidDataforseoFull());
    $truncated = processorFixtureTransaction($account, plaidDataforseoTruncated());

    Sanctum::actingAs($user, ['app']);

    $this->postJson('/api/app/v1/transactions/match', [
        'descriptor' => 'PADDLE.NET* DATAFORSEO', 'match_desc' => 'PADDLE.NET', 'vendor_id' => (string) $dataforseo->id,
    ])->assertStatus(422)
        ->assertJsonPath('errors.match_desc.0', 'PADDLE.NET is a payment processor many companies bill through. Match on the company name after it instead, like DATAFORSE.');

    expect(VendorTransaction::query()->where('vendor_id', $dataforseo->id)->exists())->toBeFalse();

    $this->postJson('/api/app/v1/transactions/match', [
        'descriptor' => 'PADDLE.NET* DATAFORSEO', 'match_desc' => 'DATAFORSE', 'vendor_id' => (string) $dataforseo->id,
    ])->assertOk()->assertJsonPath('data.vendor.id', $dataforseo->id);

    expect($full->refresh()->vendor_id)->toBe($dataforseo->id)
        ->and($truncated->refresh()->vendor_id)->toBe($dataforseo->id);
});

it('refuses, through the app API, a rule naming a processor only Plaid declares', function () {
    ['user' => $user, 'account' => $account] = guardActor(1);
    $acme = processorFixtureRetailVendor('Acme Tools');
    $charge = processorFixtureTransaction($account, plaidBraintreeAcme());

    Sanctum::actingAs($user, ['app']);

    $this->postJson('/api/app/v1/transactions/match', [
        'descriptor' => 'BRAINTREE* ACME TOOLS 1234', 'match_desc' => 'BRAINTREE', 'vendor_id' => (string) $acme->id,
    ])->assertStatus(422)
        ->assertJsonPath('errors.match_desc.0', 'BRAINTREE is a payment processor many companies bill through. Match on the company name after it instead.');

    $this->postJson('/api/app/v1/transactions/match', [
        'descriptor' => 'BRAINTREE* ACME TOOLS 1234', 'match_desc' => 'ACME TOOLS', 'vendor_id' => (string) $acme->id,
    ])->assertOk();

    expect($charge->refresh()->vendor_id)->toBe($acme->id);
});

it('tells the app which cards are billed through a processor and what to prefill', function () {
    ['user' => $user, 'account' => $account] = guardActor(1);
    processorFixtureTransaction($account, plaidDataforseoFull());
    processorFixtureTransaction($account, plaidSelfBilledMicrosoft());

    Sanctum::actingAs($user, ['app']);
    $cards = collect($this->getJson('/api/app/v1/transactions/unmatched')->assertOk()->json('data.merchant_cards'))->keyBy('descriptor');

    expect($cards['PADDLE.NET* DATAFORSEO'])->toMatchArray([
        'processors' => ['Paddle'],
        'merchant' => 'Dataforseo',
        'suggested_match_desc' => 'DATAFORSE',
        'processor_note' => 'Billed through Paddle, a payment processor many companies bill through — the merchant is Dataforseo.',
    ])->and($cards['E0100KW0UY'])->toMatchArray([
        'processors' => [],
        'merchant' => null,
        'suggested_match_desc' => null,
        'processor_note' => null,
    ]);
});

it('writes the merchant match text when a suggestion is applied to a processor charge', function (?string $aiMatchDesc) {
    ['user' => $user, 'account' => $account] = guardActor();
    $dataforseo = processorFixtureRetailVendor('DataForSEO');
    $full = processorFixtureTransaction($account, plaidDataforseoFull());
    $truncated = processorFixtureTransaction($account, plaidDataforseoTruncated());

    $result = app(TransactionVendorMatcher::class)->applySuggestion($user, 'PADDLE.NET* DATAFORSEO', [
        'vendor_name' => 'DataForSEO', 'existing_vendor_id' => $dataforseo->id, 'match_desc' => $aiMatchDesc,
    ], null);

    expect($result['match_desc'])->toBe('DATAFORSE')
        ->and(VendorTransaction::query()->where('vendor_id', $dataforseo->id)->value('desc'))->toBe('DATAFORSE')
        ->and($full->refresh()->vendor_id)->toBe($dataforseo->id)
        ->and($truncated->refresh()->vendor_id)->toBe($dataforseo->id);
})->with([
    'the AI answered with the processor' => 'PADDLE.NET',
    'the AI answered with the processor and the merchant' => 'PADDLE.NET* DATAFORSEO',
    'the AI proposed nothing, so the descriptor stands in' => null,
]);

it('refuses a typed processor-only match text when a suggestion is applied', function () {
    ['user' => $user, 'account' => $account] = guardActor();
    $dataforseo = processorFixtureRetailVendor('DataForSEO');
    processorFixtureTransaction($account, plaidDataforseoFull());

    expect(fn () => app(TransactionVendorMatcher::class)->applySuggestion($user, 'PADDLE.NET* DATAFORSEO', [
        'vendor_name' => 'DataForSEO', 'existing_vendor_id' => $dataforseo->id, 'match_desc' => 'DATAFORSE',
    ], 'PADDLE.NET'))->toThrow(ValidationException::class);

    expect(VendorTransaction::query()->count())->toBe(0);
});

it('repairs a cached AI suggestion that proposed the processor as the match text', function () {
    ['account' => $account] = guardActor();
    $vendors = Vendor::withoutGlobalScopes()->select(['id', 'business_name', 'city', 'state'])->get();
    processorFixtureTransaction($account, plaidDataforseoFull());
    $transactions = Transaction::transactionsSinVendor()->where('plaid_merchant_description', 'PADDLE.NET* DATAFORSEO')->get();

    Cache::put('vendor-suggest:v4:'.md5('PADDLE.NET* DATAFORSEO'), [
        'vendor_name' => 'DataForSEO', 'existing_vendor_id' => null, 'website' => 'https://dataforseo.com',
        'city' => null, 'state' => null, 'match_desc' => 'PADDLE.NET', 'confidence' => 'high', 'reasoning' => 'SEO data API.',
    ], 3600);

    $suggestion = app(VendorSuggestionService::class)->suggest('PADDLE.NET* DATAFORSEO', $transactions, $vendors);

    expect($suggestion['vendor_name'])->toBe('DataForSEO')
        ->and($suggestion['match_desc'])->toBe('DATAFORSE');
});
