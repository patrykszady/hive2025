<?php

use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\Transaction;
use App\Models\Vendor;
use App\Services\VendorSuggestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * AI Identify decides where a merchant is from the evidence, not from where
 * the company sits (2026-10-02): a Capital One "GIAMPIETRO PIZZERIA" with no
 * location was the pizzeria in Breckenridge, CO — Plaid named it with HIGH
 * confidence and the same card took Summit Express and Frontier Airlines
 * that day — but the prompt said "most charges happen in Chicagoland".
 */
function vsl_account(): BankAccount
{
    $company = Vendor::factory()->create(['business_name' => 'GS Test Co']);
    $bank = Bank::create(['name' => 'Capital One', 'vendor_id' => $company->id, 'plaid_ins_id' => 'ins_vsl_'.uniqid()]);

    return BankAccount::create(['vendor_id' => $company->id, 'bank_id' => $bank->id, 'account_number' => '4060', 'plaid_account_id' => 'acc_vsl_'.uniqid(), 'type' => 'credit']);
}

function vsl_charge(BankAccount $account, string $date, string $description, array $details = [], ?string $merchant = null): Transaction
{
    return Transaction::withoutGlobalScopes()->create([
        'bank_account_id' => $account->id,
        'transaction_date' => $date,
        'amount' => 20,
        'plaid_merchant_description' => $description,
        'plaid_merchant_name' => $merchant,
        'details' => array_merge(['payment_channel' => 'in store', 'location' => ['city' => null, 'region' => null]], $details),
    ]);
}

function vsl_prompt(string $descriptor, BankAccount $account): string
{
    $transactions = Transaction::withoutGlobalScopes()->where('plaid_merchant_description', $descriptor)->with('bank_account.bank')->get();
    $service = app(VendorSuggestionService::class);

    return (fn () => $this->buildPrompt($descriptor, $transactions, collect()))->call($service);
}

it('puts the charge\'s own location first and Chicagoland last', function () {
    $account = vsl_account();
    vsl_charge($account, '2026-09-21', 'GIAMPIETRO PIZZERIA', ['location' => ['city' => 'Breckenridge', 'region' => 'CO', 'address' => '100 N Main St']]);

    $prompt = vsl_prompt('GIAMPIETRO PIZZERIA', $account);

    expect($prompt)->toContain('LOCATION: 100 N Main St, Breckenridge, CO')
        ->and($prompt)->toContain('1. A LOCATION on one of the transactions below: the merchant is there.')
        ->and($prompt)->toContain('3. Only when neither points elsewhere, assume the Chicagoland area.')
        ->and($prompt)->not->toContain('Most in-store charges happen in the Chicagoland area');
});

it('passes Plaid\'s own merchant match and its confidence', function () {
    $account = vsl_account();
    vsl_charge($account, '2026-09-21', 'GIAMPIETRO PIZZERIA', ['counterparties' => [['name' => 'Giampietro Pasta & Pizzeria', 'type' => 'merchant', 'confidence_level' => 'HIGH']]], 'Giampietro Pasta & Pizzeria');

    expect(vsl_prompt('GIAMPIETRO PIZZERIA', $account))->toContain('Plaid\'s merchant match: "Giampietro Pasta & Pizzeria" (Plaid confidence: HIGH)');
});

it('shows where the same card was the day before to the day after, travel included', function () {
    $account = vsl_account();
    vsl_charge($account, '2026-09-21', 'GIAMPIETRO PIZZERIA');
    vsl_charge($account, '2026-09-21', 'Summit Express', merchant: 'Summit Express');
    vsl_charge($account, '2026-09-22', 'FRONTIER AI LCZNMY', merchant: 'Frontier Airlines');
    vsl_charge($account, '2026-09-20', 'BRECK GROCERY', ['location' => ['city' => 'Breckenridge', 'region' => 'CO']], 'City Market');
    vsl_charge($account, '2026-09-21', 'AMAZON MKTPL*5R6', ['payment_channel' => 'online', 'location' => ['city' => 'Seattle', 'region' => 'WA']], 'Amazon');
    vsl_charge($account, '2026-09-21', 'CORNER STORE', merchant: 'Corner Store');
    vsl_charge($account, '2026-09-25', 'DENVER HOTEL', ['location' => ['city' => 'Denver', 'region' => 'CO']], 'Hotel Denver');
    vsl_charge(vsl_account(), '2026-09-21', 'OTHER CARD HOTEL', ['location' => ['city' => 'Miami', 'region' => 'FL']], 'Miami Hotel');

    $prompt = vsl_prompt('GIAMPIETRO PIZZERIA', $account);

    expect($prompt)->toContain('- 2026-09-21 | Summit Express | travel')
        ->and($prompt)->toContain('- 2026-09-22 | Frontier Airlines | travel')
        ->and($prompt)->toContain('- 2026-09-20 | City Market | Breckenridge, CO')
        ->and($prompt)->not->toContain('Seattle')
        ->and($prompt)->not->toContain('Corner Store')
        ->and($prompt)->not->toContain('Denver')
        ->and($prompt)->not->toContain('Miami');
});

/**
 * 2026-10-02: "TST* TASTES ON THE FLY-ME" on the same card was placed at
 * Boston ("-ME" read as Maine) though the card took the Denver airport
 * shuttle that day and Giampietro had just been saved as a Breckenridge, CO
 * vendor. Matched vendors' cities now place the trip, a vendor named a
 * shuttle counts as travel, and the prompt has the trip placed first.
 */
it('places the trip from vendors on file and has it worked out before the merchant', function () {
    $account = vsl_account();
    $giampietro = Vendor::factory()->create(['business_name' => 'Giampietro Pasta & Pizzeria', 'city' => 'Breckenridge', 'state' => 'CO']);
    $shuttle = Vendor::factory()->create(['business_name' => 'Summit Express Shuttle', 'city' => null, 'state' => null]);
    $chain = Vendor::factory()->create(['business_name' => 'Shell', 'city' => null, 'state' => 'IL']);
    vsl_charge($account, '2026-09-21', 'TST* TASTES ON THE FLY-ME', merchant: 'Tastes On The Fly');
    vsl_charge($account, '2026-09-21', 'GIAMPIETRO PIZZERIA', merchant: 'Giampietro Pasta & Pizzeria')->update(['vendor_id' => $giampietro->id]);
    vsl_charge($account, '2026-09-21', 'SUMMIT XPRS 0042', merchant: 'Summit Xprs')->update(['vendor_id' => $shuttle->id]);
    vsl_charge($account, '2026-09-21', 'Shell', merchant: 'Shell Oil')->update(['vendor_id' => $chain->id]);

    $prompt = vsl_prompt('TST* TASTES ON THE FLY-ME', $account);

    expect($prompt)->toContain('- 2026-09-21 | Giampietro Pasta & Pizzeria | Breckenridge, CO (on file)')
        ->and($prompt)->toContain('- 2026-09-21 | Summit Xprs | travel')
        ->and($prompt)->not->toContain('Shell Oil')
        ->and($prompt)->toContain('a travel merchant listed without a place must be looked up')
        ->and($prompt)->toContain('(e.g. "-ME") are usually the outlet\'s code, not a state');
});

it('does not reuse an answer cached before the prompt placed the trip', function () {
    $account = vsl_account();
    vsl_charge($account, '2026-09-21', 'GIAMPIETRO PIZZERIA');
    foreach (['vendor-suggest:', 'vendor-suggest:v2:'] as $oldPrefix) {
        Cache::put($oldPrefix.md5('GIAMPIETRO PIZZERIA'), ['vendor_name' => 'Old Guess', 'existing_vendor_id' => null, 'website' => null, 'city' => null, 'state' => null, 'match_desc' => 'GIAMPIETRO', 'confidence' => 'low', 'reasoning' => 'old'], 3600);
    }

    Http::fake(['api.openai.com/v1/responses' => Http::response(['output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode([
        'vendor_name' => 'Giampietro Pasta & Pizzeria', 'existing_vendor_id' => null, 'website' => 'https://www.giampietropizza.com/', 'city' => 'Breckenridge', 'state' => 'CO', 'match_desc' => 'GIAMPIETRO PIZZERIA', 'confidence' => 'high', 'reasoning' => 'Same card took the Breckenridge shuttle that day.',
    ])]]]]])]);

    $transactions = Transaction::withoutGlobalScopes()->where('plaid_merchant_description', 'GIAMPIETRO PIZZERIA')->get();
    $suggestion = app(VendorSuggestionService::class)->suggest('GIAMPIETRO PIZZERIA', $transactions, collect());

    expect($suggestion['vendor_name'])->toBe('Giampietro Pasta & Pizzeria')
        ->and($suggestion['confidence'])->toBe('high');
});
