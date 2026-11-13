<?php

use App\Livewire\Transactions\MatchVendor;
use App\Livewire\Transactions\VendorTransactionsPanel;
use App\Models\Expense;
use App\Models\ExpenseReceipts;
use App\Models\Vendor;
use App\Models\VendorTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;

require_once __DIR__.'/../Support/processor-fixtures.php';

uses(RefreshDatabase::class);

/*
 * One Match Vendor rule per text (Patryk, 2026-11-13: "review the match
 * vendor database .. it might have duplicates (should be prevented to create
 * a duplicate via livewire validator when submitting the form)"). Production
 * held nine rules saved more than once ("Dsp Motorsports" four times,
 * "Frontier Communications" twice in one night).
 */
function dupFrontierCharge(\App\Models\BankAccount $account, array $overrides = []): \App\Models\Transaction
{
    return processorFixtureTransaction($account, array_merge([
        'date' => '2026-11-06', 'name' => 'DEBIT PURCHASE Nov 06 4849 FRONTIERMYSB3L 26310', 'amount' => 15.41,
        'merchant_name' => 'Frontier Communications', 'counterparties' => [], 'payment_meta' => [],
    ], $overrides));
}

/** @return array<string, array<int, string>> */
function dupSave(MatchVendor $component, string $method = 'store'): array
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

beforeEach(function (): void {
    $fixture = processorFixtureCompany(1);
    $user = $fixture['user'];
    Gate::before(fn ($actor, $ability) => $actor->is($user) ? true : null);
    $this->actingAs($user);
    $this->fixture = $fixture;
    $this->airline = processorFixtureRetailVendor('Frontier Airlines');
});

it('refuses a rule already on file for the same vendor, read as a person reads it', function (string $typed) {
    dupFrontierCharge($this->fixture['account']);
    $existing = VendorTransaction::create(['vendor_id' => $this->airline->id, 'desc' => 'Frontier Communications', 'options' => json_encode('/i')]);

    $component = new MatchVendor();
    $component->match_merchant_names = [0 => ['match_desc' => $typed, 'vendor_id' => (string) $this->airline->id]];

    expect(dupSave($component))->toBe(['match_merchant_names.0.match_desc' => [
        'There is already a rule for "'.trim($typed).'" → Frontier Airlines (#'.$existing->id.'). Saving it again would only add a duplicate.',
    ]])->and(VendorTransaction::query()->count())->toBe(1);
})->with(['same text' => 'Frontier Communications', 'other case, spaces around' => '  frontier COMMUNICATIONS ']);

it('refuses giving a text that is already a rule to a different vendor', function () {
    dupFrontierCharge($this->fixture['account']);
    $isp = processorFixtureRetailVendor('Frontier Internet');
    $existing = VendorTransaction::create(['vendor_id' => $isp->id, 'desc' => 'Frontier Communications', 'options' => json_encode('/i')]);

    $component = new MatchVendor();
    $component->match_merchant_names = [0 => ['match_desc' => 'Frontier Communications', 'vendor_id' => (string) $this->airline->id]];

    expect(dupSave($component))->toBe(['match_merchant_names.0.match_desc' => [
        '"Frontier Communications" is already matched to Frontier Internet by rule #'.$existing->id.'. Edit or delete that rule instead of adding a second one.',
    ]])->and(VendorTransaction::query()->count())->toBe(1);
});

it('allows the same text for another sign of amount', function () {
    dupFrontierCharge($this->fixture['account']);
    VendorTransaction::create(['vendor_id' => $this->airline->id, 'desc' => 'Frontier Communications', 'amount_sign' => 2, 'options' => json_encode('/i')]);

    $component = new MatchVendor();
    $component->match_merchant_names = [0 => ['match_desc' => 'Frontier Communications', 'vendor_id' => (string) $this->airline->id, 'amount_sign' => '1']];

    expect(dupSave($component))->toBe([])
        ->and(VendorTransaction::query()->count())->toBe(2);
});

it('refuses the same text on two cards of one save', function () {
    dupFrontierCharge($this->fixture['account']);
    dupFrontierCharge($this->fixture['account'], ['name' => 'DEBIT PURCHASE Nov 08 4849 FRONTIERQX7P2L 26312']);

    $component = new MatchVendor();
    $component->match_merchant_names = [
        0 => ['match_desc' => 'Frontier Communications', 'vendor_id' => (string) $this->airline->id],
        1 => ['match_desc' => 'Frontier Communications', 'vendor_id' => (string) $this->airline->id],
    ];

    expect(dupSave($component))->toBe(['match_merchant_names.1.match_desc' => ['Another card in this save already matches "Frontier Communications". Keep it on one card.']])
        ->and(VendorTransaction::query()->count())->toBe(0);
});

it('refuses a NEW vendor whose name a vendor already has', function () {
    processorFixtureTransaction($this->fixture['account'], ['date' => '2026-11-06', 'name' => 'FRONTIER AIRLINES 4221', 'amount' => 99.0, 'merchant_name' => null]);
    $vendors = Vendor::withoutGlobalScopes()->count();

    $component = new MatchVendor();
    $component->match_merchant_names = [0 => ['match_desc' => 'frontier airlines', 'vendor_id' => 'NEW']];

    expect(dupSave($component))->toBe(['match_merchant_names.0.vendor_id' => [
        'A vendor named "Frontier Airlines" already exists (#'.$this->airline->id.'). Pick it in Vendor instead of NEW Retail Vendor.',
    ]])->and(Vendor::withoutGlobalScopes()->count())->toBe($vendors);
});

it('does not write a receipt card\'s rule twice, and refuses giving its text to another vendor', function () {
    $company = $this->fixture['company'];
    $expense = Expense::withoutGlobalScopes()->create(['amount' => -12.00, 'date' => '2026-10-01', 'vendor_id' => 0, 'belongs_to_vendor_id' => $company->id, 'created_by_user_id' => $this->fixture['user']->id]);
    ExpenseReceipts::create(['expense_id' => $expense->id, 'receipt_filename' => 'r.pdf', 'receipt_html' => '<div></div>', 'receipt_items' => ['items' => [], 'total' => '12.00', 'merchant_name' => 'FRONTIER AIR']]);
    VendorTransaction::create(['vendor_id' => $this->airline->id, 'desc' => 'FRONTIER AIR', 'options' => json_encode('/i')]);

    $other = processorFixtureRetailVendor('Frontier Internet');
    $refused = new MatchVendor();
    $refused->match_expense_merchant_names = [0 => ['match_desc' => 'FRONTIER AIR', 'vendor_id' => (string) $other->id]];
    expect(array_keys(dupSave($refused, 'store_expense_vendors')))->toBe(['match_expense_merchant_names.0.match_desc']);

    $same = new MatchVendor();
    $same->match_expense_merchant_names = [0 => ['match_desc' => 'FRONTIER AIR', 'vendor_id' => (string) $this->airline->id]];
    expect(dupSave($same, 'store_expense_vendors'))->toBe([])
        ->and(VendorTransaction::query()->count())->toBe(1);
});

it('refuses in the rules panel a rule that differs only in case and spacing, or points the text elsewhere', function () {
    VendorTransaction::create(['vendor_id' => $this->airline->id, 'desc' => 'FRONTIER', 'options' => json_encode('/i')]);
    $isp = processorFixtureRetailVendor('Frontier Internet');

    $panel = new VendorTransactionsPanel();
    $panel->mount();
    $panel->new_vendor_transaction = ['vendor_id' => (string) $isp->id, 'desc' => ' frontier ', 'deposit_check' => '', 'amount_sign' => '', 'plaid_inst_id' => '', 'options' => ''];
    $panel->createVendorTransaction();

    expect($panel->getErrorBag()->has('new_vendor_transaction.desc'))->toBeTrue()
        ->and(VendorTransaction::query()->count())->toBe(1);
});

it('refuses a duplicate from the app', function () {
    dupFrontierCharge($this->fixture['account']);
    VendorTransaction::create(['vendor_id' => $this->airline->id, 'desc' => 'Frontier Communications', 'options' => json_encode('/i')]);
    Sanctum::actingAs($this->fixture['user'], ['app']);

    $this->postJson('/api/app/v1/transactions/match', [
        'descriptor' => 'DEBIT PURCHASE Nov 06 4849 FRONTIERMYSB3L 26310', 'match_desc' => 'Frontier Communications', 'vendor_id' => (string) $this->airline->id,
    ])->assertStatus(422)->assertJsonPath('errors.match_desc.0', fn (string $message): bool => str_starts_with($message, 'There is already a rule for "Frontier Communications"'));

    expect(VendorTransaction::query()->count())->toBe(1);
});

it('deletes the rules saved more than once, keeping the oldest, and lists one text given to two vendors', function () {
    $isp = processorFixtureRetailVendor('Frontier Internet');
    $keep = VendorTransaction::create(['vendor_id' => $this->airline->id, 'desc' => 'Frontier Communications', 'options' => json_encode('/i')]);
    $again = VendorTransaction::create(['vendor_id' => $this->airline->id, 'desc' => 'frontier  communications', 'options' => json_encode('/i')]);
    $otherSign = VendorTransaction::create(['vendor_id' => $this->airline->id, 'desc' => 'Frontier Communications', 'amount_sign' => 2, 'options' => json_encode('/i')]);
    $starred = VendorTransaction::create(['vendor_id' => $isp->id, 'desc' => 'PADDLE.NET\* X', 'options' => json_encode('/i')]);
    $starredAgain = VendorTransaction::create(['vendor_id' => $isp->id, 'desc' => 'PADDLE.NET* X', 'options' => json_encode('/i')]);
    $conflict = VendorTransaction::create(['vendor_id' => $isp->id, 'desc' => 'FRONTIER', 'options' => json_encode('/i')]);
    $conflictOther = VendorTransaction::create(['vendor_id' => $this->airline->id, 'desc' => 'Frontier', 'options' => json_encode('/i')]);

    $this->artisan('vendor-rules:dedupe', ['--dry-run' => true])
        ->expectsOutputToContain('"Frontier Communications" → Frontier Airlines: #'.$keep->id.', #'.$again->id)
        ->expectsOutputToContain('One text, different vendors')
        ->expectsOutputToContain('Dry run: would delete 2 duplicate rule(s).')
        ->assertSuccessful();
    expect(VendorTransaction::query()->count())->toBe(7);

    $this->artisan('vendor-rules:dedupe')->expectsOutputToContain('Deleted 2 duplicate rule(s).')->assertSuccessful();

    expect(VendorTransaction::query()->orderBy('id')->pluck('id')->all())->toBe([$keep->id, $otherSign->id, $starred->id, $conflict->id, $conflictOther->id]);

    $this->artisan('vendor-rules:dedupe')->expectsOutputToContain('Deleted 0 duplicate rule(s).')->assertSuccessful();
});
