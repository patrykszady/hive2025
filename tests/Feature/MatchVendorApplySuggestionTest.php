<?php

use App\Livewire\Transactions\MatchVendor;
use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

/**
 * Regression: "Use this vendor" appeared to do nothing for an EXISTING vendor.
 *
 * applySuggestion() had two branches. For a NEW vendor it created the vendor,
 * wrote a VendorTransaction match rule, attached it and re-ran matching. For an
 * EXISTING one it only assigned vendor_id into the form array and returned — no
 * rule, no attach, no re-match — and because the page never reloaded, the same
 * suggestion card stayed on screen, which read as the click being ignored.
 *
 * Driven by calling the component directly rather than through Livewire::test().
 * The harness cannot round-trip this component at all — even
 * set('ai_suggestions', []) fails with "Invalid Livewire snapshot structure",
 * which reproduces on the untouched component and is caused by the deferred
 * child <livewire:transactions.vendor-transactions-panel> in its view.
 */
function makeComponent(array $suggestion, string $typed, string $descriptor, Vendor $vendor): MatchVendor
{
    // The card list is read from the database now (no seeded collection), so
    // a descriptor the test relies on must exist as a real unmatched transaction
    // — created as a signed-in member of the vendor, which the bank-account
    // scope requires.
    if ($descriptor !== 'ignored') {
        $member = User::query()->create([
            'first_name' => 'Apply', 'last_name' => 'Tester', 'email' => 'apply-'.uniqid().'@example.test',
            'cell_phone' => '5'.random_int(100000000, 999999999), 'password' => bcrypt('password'),
            'primary_vendor_id' => $vendor->id,
        ]);
        $member->vendors()->attach($vendor->id, ['role_id' => 1]);
        test()->actingAs($member);

        $bank = Bank::create(['name' => 'Fixture Bank', 'vendor_id' => $vendor->id, 'plaid_ins_id' => 'ins_fixture']);
        $account = BankAccount::create([
            'vendor_id' => $vendor->id, 'bank_id' => $bank->id, 'account_number' => '0001',
            'plaid_account_id' => 'acc_fixture_'.uniqid(), 'type' => 'credit',
        ]);
        Transaction::create([
            'transaction_date' => now()->subDay(), 'amount' => 12.34, 'bank_account_id' => $account->id,
            'plaid_merchant_name' => $descriptor, 'plaid_merchant_description' => $descriptor,
            'details' => ['category' => ['Shops'], 'payment_channel' => 'in store'],
        ]);
    }

    $component = new MatchVendor();
    $reflection = new ReflectionClass($component);

    $state = [
        'ai_suggestions' => [0 => $suggestion],
        'match_merchant_names' => [0 => ['match_desc' => $typed, 'vendor_id' => '']],
        'match_expense_merchant_names' => [],
    ];

    foreach ($state as $property => $value) {
        $handle = $reflection->getProperty($property);
        $handle->setAccessible(true);
        $handle->setValue($component, $value);
    }

    return $component;
}

/**
 * Run applySuggestion, tolerating only the toast.
 *
 * Flux::toast() resolves the current Livewire component to dispatch on, and
 * outside a Livewire request there is none. It is the LAST statement in the
 * method, so everything under test has already run by the time it throws.
 */
function applyAndIgnoreToast(MatchVendor $component): void
{
    try {
        $component->applySuggestion(0);
    } catch (\Error $e) {
        if (! str_contains($e->getMessage(), 'dispatch()')) {
            throw $e;
        }
    }
}

function suggestionFor(Vendor $vendor, ?string $matchDesc, ?int $vendorId = null): array
{
    return array_filter([
        'vendor_name' => $vendor->business_name,
        'existing_vendor_id' => $vendorId ?? $vendor->id,
        'match_desc' => $matchDesc,
        'confidence' => 'medium',
        'reasoning' => 'test',
    ], fn ($value) => ! is_null($value));
}

beforeEach(function () {
    // The component authorizes viewAny on TransactionBulkMatch, which requires
    // vendor_role === 'Admin'. That attribute is derived from a vendor pivot,
    // so the gate is granted directly rather than building the whole graph.
    $user = User::factory()->create();
    Gate::before(fn ($actor, $ability) => $actor->is($user) ? true : null);
    $this->actingAs($user);
});

it('writes a match rule when an existing vendor is applied', function () {
    $vendor = Vendor::factory()->create(['business_name' => 'City Market']);

    applyAndIgnoreToast(makeComponent(suggestionFor($vendor, 'CITY-MARKET'), '', 'CITY-MARKET', $vendor));

    expect(VendorTransaction::where('vendor_id', $vendor->id)->where('desc', 'CITY-MARKET')->exists())->toBeTrue();
});

it('strips the store number so the rule matches the whole chain', function () {
    $vendor = Vendor::factory()->create(['business_name' => 'City Market']);

    applyAndIgnoreToast(makeComponent(suggestionFor($vendor, 'CITY-MARKET #0430'), '', 'ignored', $vendor));

    expect(VendorTransaction::where('vendor_id', $vendor->id)->value('desc'))->toBe('CITY-MARKET');
});

it('falls back to the raw descriptor, store number stripped', function () {
    $vendor = Vendor::factory()->create(['business_name' => 'City Market']);

    applyAndIgnoreToast(makeComponent(suggestionFor($vendor, null), '', 'CITY-MARKET #0430', $vendor));

    expect(VendorTransaction::where('vendor_id', $vendor->id)->value('desc'))->toBe('CITY-MARKET');
});

it('keeps a hand-typed pattern exactly as typed', function () {
    $vendor = Vendor::factory()->create(['business_name' => 'City Market']);

    applyAndIgnoreToast(makeComponent(suggestionFor($vendor, 'CITY-MARKET'), 'CITY-MARKET #0430', 'ignored', $vendor));

    // Someone chose those characters, store number included.
    expect(VendorTransaction::where('vendor_id', $vendor->id)->value('desc'))->toBe('CITY-MARKET #0430');
});

it('leaves a bare trailing number alone', function () {
    $vendor = Vendor::factory()->create(['business_name' => 'Checks']);

    // Stripping digits here would collapse every cheque onto one rule.
    applyAndIgnoreToast(makeComponent(suggestionFor($vendor, null), '', 'CHECK 1378', $vendor));

    expect(VendorTransaction::where('vendor_id', $vendor->id)->value('desc'))->toBe('CHECK 1378');
});

it('recovers when the suggested vendor id no longer exists', function () {
    $vendor = Vendor::factory()->create(['business_name' => 'City Market']);

    applyAndIgnoreToast(makeComponent(suggestionFor($vendor, 'CITY-MARKET', 999999), '', 'ignored', $vendor));

    // Falls through to the name lookup rather than throwing on a null vendor.
    expect(VendorTransaction::where('vendor_id', $vendor->id)->where('desc', 'CITY-MARKET')->exists())->toBeTrue();
});
