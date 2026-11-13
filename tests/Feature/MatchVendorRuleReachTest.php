<?php

use App\Livewire\Transactions\MatchVendor;
use App\Models\Vendor;
use App\Models\VendorTransaction;
use App\Support\Transactions\MatchTextReach;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;

require_once __DIR__.'/../Support/processor-fixtures.php';

uses(RefreshDatabase::class);

/*
 * "Match As" works on the bank's own text of a charge and on Plaid's clean
 * name for it (Patryk, 2026-11-13: "i have been [typing] 'match as' as the
 * full / clean plaid desc. 'Frontier Communications' and that should work
 * in 'Match As' etc. as well as the bank text"). The card "DEBIT PURCHASE
 * Nov 06 4849 FRONTIERMYSB3L 26310", which Plaid names "Frontier
 * Communications", was matched twice that way and stayed on the page: rules
 * were only ever tried on the bank text. A rule that reaches neither text on
 * its own card is refused when it is saved, with words to use instead.
 */
function reachFrontierCharge(\App\Models\BankAccount $account): \App\Models\Transaction
{
    return processorFixtureTransaction($account, [
        'date' => '2026-11-06', 'name' => 'DEBIT PURCHASE Nov 06 4849 FRONTIERMYSB3L 26310', 'amount' => 312.40,
        'merchant_name' => 'Frontier Communications', 'counterparties' => [], 'payment_meta' => [],
    ]);
}

/** @return array<string, array<int, string>> the validation errors the save raised; [] when it went through */
function reachSave(MatchVendor $component): array
{
    try {
        $component->store();
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
    // Company id 1, as the app API's tests use.
    $fixture = processorFixtureCompany(1);
    $user = $fixture['user'];
    Gate::before(fn ($actor, $ability) => $actor->is($user) ? true : null);
    $this->actingAs($user);
    $this->fixture = $fixture;
    $this->airline = processorFixtureRetailVendor('Frontier Airlines');
});

it('matches a card on Plaid\'s clean name, and the card leaves the page and stays matched', function () {
    $charge = reachFrontierCharge($this->fixture['account']);

    $component = new MatchVendor();
    $component->match_merchant_names = [0 => ['match_desc' => 'Frontier Communications', 'vendor_id' => (string) $this->airline->id]];

    expect(reachSave($component))->toBe([])
        ->and(VendorTransaction::query()->sole()->desc)->toBe('Frontier Communications')
        ->and($charge->fresh()->vendor_id)->toBe($this->airline->id)
        ->and((new MatchVendor())->merchantCards->keys()->all())->not->toContain('DEBIT PURCHASE Nov 06 4849 FRONTIERMYSB3L 26310');

    // The next runs of the matcher keep it: the re-check sees the rule that
    // put it there, on the name, instead of clearing a vendor whose name
    // ("Frontier Airlines") is not Plaid's ("Frontier Communications").
    app(\App\Http\Controllers\TransactionController::class)->add_vendor_to_transactions();
    app(\App\Http\Controllers\TransactionController::class)->add_vendor_to_transactions();

    expect($charge->fresh()->vendor_id)->toBe($this->airline->id);
});

it('still matches on the bank text, which wins over a rule on the name', function () {
    $charge = reachFrontierCharge($this->fixture['account']);
    $isp = processorFixtureRetailVendor('Frontier Internet');
    VendorTransaction::create(['vendor_id' => $isp->id, 'desc' => 'Frontier Communications', 'options' => json_encode('/i')]);
    VendorTransaction::create(['vendor_id' => $this->airline->id, 'desc' => 'FRONTIERMYSB3L', 'options' => json_encode('/i')]);

    app(\App\Http\Controllers\TransactionController::class)->add_vendor_to_transactions();

    expect($charge->fresh()->vendor_id)->toBe($this->airline->id);
});

it('refuses a rule that is in neither the bank text nor Plaid\'s name, and says what to use instead', function () {
    $charge = reachFrontierCharge($this->fixture['account']);

    $component = new MatchVendor();
    $component->match_merchant_names = [0 => ['match_desc' => 'Frontier Airlines', 'vendor_id' => (string) $this->airline->id]];

    expect(reachSave($component))->toBe(['match_merchant_names.0.match_desc' => [
        '"Frontier Airlines" is not in this charge\'s bank text ("DEBIT PURCHASE Nov 06 4849 FRONTIERMYSB3L 26310") or in Plaid\'s name for it ("Frontier Communications"), so it would match nothing and the card would stay. Use words from either instead, like "FRONTIER".',
    ]])
        ->and(VendorTransaction::query()->count())->toBe(0)
        ->and($charge->fresh()->vendor_id)->toBeNull();
});

it('holds the check, deposit, transfer and cash codes to the bank text too', function () {
    reachFrontierCharge($this->fixture['account']);

    $code = new MatchVendor();
    $code->match_merchant_names = [0 => ['match_desc' => 'ZELLE', 'vendor_id' => 'TRANSFER']];
    expect(array_keys(reachSave($code)))->toBe(['match_merchant_names.0.match_desc']);
});

it('gives a NEW vendor its rule from Match As, so its card leaves the page — and holds it to the same texts', function () {
    // "Bloomberg Businessweek" for "BLOOMBERG* ***********" (Plaid: "Bloomberg") was
    // saved as a NEW vendor with no rule, left to be found by its name, and stayed.
    $charge = processorFixtureTransaction($this->fixture['account'], [
        'date' => '2026-08-25', 'name' => 'BLOOMBERG* ***********', 'amount' => 40.00,
        'merchant_name' => 'Bloomberg', 'counterparties' => [], 'payment_meta' => [],
    ]);

    $refused = new MatchVendor();
    $refused->match_merchant_names = [0 => ['match_desc' => 'Bloomberg Businessweek', 'vendor_id' => 'NEW']];
    expect(array_keys(reachSave($refused)))->toBe(['match_merchant_names.0.match_desc'])
        ->and(Vendor::withoutGlobalScopes()->where('business_name', 'Bloomberg Businessweek')->exists())->toBeFalse();

    $saved = new MatchVendor();
    $saved->match_merchant_names = [0 => ['match_desc' => ' BLOOMBERG ', 'vendor_id' => 'NEW']];
    expect(reachSave($saved))->toBe([]);

    // The vendor model writes names in title case: "Bloomberg".
    $vendor = Vendor::withoutGlobalScopes()->whereRaw('LOWER(business_name) = ?', ['bloomberg'])->sole();
    expect(VendorTransaction::query()->sole())->vendor_id->toBe($vendor->id)->desc->toBe('BLOOMBERG')
        ->and($charge->fresh()->vendor_id)->toBe($vendor->id);
});

it('tries the rule exactly as the matcher does, the Options box included', function () {
    $descriptor = 'DEBIT PURCHASE Nov 06 4849 FRONTIERMYSB3L 26310';

    expect(MatchTextReach::reaches($descriptor, 'frontier'))->toBeTrue('case does not matter')
        ->and(MatchTextReach::reaches($descriptor, 'FRONTIER', 'MYSB\\w+'))->toBeTrue()
        ->and(MatchTextReach::reaches($descriptor, 'FRONTIER', ' AIRLINES'))->toBeFalse()
        ->and(MatchTextReach::reaches('PADDLE.NET* DATAFORSEO', 'PADDLE.NET* DATAFORSE'))->toBeTrue('a typed * is a literal *')
        ->and(MatchTextReach::refusal($descriptor, 'Southwest'))->toEndWith('Use words from the bank text instead.');
});

it('takes Plaid\'s name and refuses what is in neither text from the app too', function () {
    $charge = reachFrontierCharge($this->fixture['account']);
    Sanctum::actingAs($this->fixture['user'], ['app']);

    $this->postJson('/api/app/v1/transactions/match', [
        'descriptor' => 'DEBIT PURCHASE Nov 06 4849 FRONTIERMYSB3L 26310', 'match_desc' => 'Southwest Airlines', 'vendor_id' => (string) $this->airline->id,
    ])->assertStatus(422)->assertJsonPath('errors.match_desc.0', fn (string $message): bool => str_contains($message, 'or in Plaid\'s name for it ("Frontier Communications")'));
    expect(VendorTransaction::query()->count())->toBe(0);

    $this->postJson('/api/app/v1/transactions/match', [
        'descriptor' => 'DEBIT PURCHASE Nov 06 4849 FRONTIERMYSB3L 26310', 'match_desc' => 'Frontier Communications', 'vendor_id' => (string) $this->airline->id,
    ])->assertOk();
    expect($charge->fresh()->vendor_id)->toBe($this->airline->id);
});
