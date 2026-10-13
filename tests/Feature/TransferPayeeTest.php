<?php

use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vendor;
use App\Support\Transactions\TransferPayee;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('reads the payee from a Zelle description and assigns the one vendor it names, never a name several people share', function () {
    TransferPayee::flush();
    $gs = Vendor::factory()->create(['business_type' => 'GC', 'business_name' => 'GS Construction']);
    $taxes = Vendor::factory()->create(['business_type' => 'Retail', 'business_name' => 'PRO TAXES AND ACCOUNTING']);
    $morenos = Vendor::factory()->create(['business_type' => 'Sub', 'business_name' => 'Morenos Drywall, Inc']);
    $galus = Vendor::factory()->create(['business_type' => '1099', 'business_name' => 'Grzegorz Galus']);
    $gs->vendors()->attach([$taxes->id, $morenos->id, $galus->id]);
    foreach (['Szady', 'Rydzewski', 'Galus'] as $last) {
        User::query()->create(['first_name' => 'Grzegorz', 'last_name' => $last, 'email' => strtolower($last).'-'.uniqid().'@example.test', 'cell_phone' => '224'.rand(1000000, 9999999)]);
    }

    expect(TransferPayee::nameFrom('ZELLE DEBIT PAY ID CTIKkEz2mk1G ORG ID PNC NAME PRO TAXE'))->toBe('PRO TAXE')
        ->and(TransferPayee::nameFrom('Zelle payment to MORENOS DRYWALL Conf# abc'))->toBe('MORENOS DRYWALL')
        ->and(TransferPayee::nameFrom('CHECK 2470'))->toBeNull()
        ->and(TransferPayee::vendorFor('PRO TAXE', $gs->id)?->id)->toBe($taxes->id)
        ->and(TransferPayee::vendorFor('MORENOS', $gs->id)?->id)->toBe($morenos->id)
        ->and(TransferPayee::vendorFor('GRZEGORZ', $gs->id))->toBeNull()
        ->and(TransferPayee::vendorFor('VIC&SI L', $gs->id))->toBeNull();

    $bank = Bank::create(['name' => 'Citibank', 'vendor_id' => $gs->id, 'plaid_ins_id' => 'ins_5']);
    $account = BankAccount::create(['vendor_id' => $gs->id, 'bank_id' => $bank->id, 'account_number' => '4903', 'plaid_account_id' => 'p-'.uniqid(), 'type' => 'Checking']);
    $mk = fn (string $desc) => Transaction::withoutSyncingToSearch(fn () => Transaction::create([
        'transaction_date' => '2026-04-30', 'posted_date' => '2026-04-30', 'amount' => 400, 'bank_account_id' => $account->id,
        'check_number' => Transaction::TRANSFER_CHECK_NUMBER, 'plaid_merchant_description' => $desc, 'plaid_transaction_id' => uniqid(),
    ]));
    $toTaxes = $mk('ZELLE DEBIT PAY ID CTIKkEz2mk1G ORG ID PNC NAME PRO TAXE');
    $toGrzegorz = $mk('ZELLE DEBIT PAY ID CTIabc ORG ID CTI NAME GRZEGORZ');
    $cardPayment = $mk('OTHER DECREASE CAPITAL ONE MOBILE PMT082126CA07DD3987BD1');

    Transaction::withoutSyncingToSearch(fn () => $this->artisan('transactions:match-transfer-payees')
        ->expectsOutputToContain('"PRO TAXE" → '.$taxes->fresh()->business_name)
        ->expectsOutputToContain('Assigned 1 transfer(s).')
        ->assertExitCode(0));

    expect($toTaxes->fresh()->vendor_id)->toBe($taxes->id)
        ->and($toGrzegorz->fresh()->vendor_id)->toBeNull()
        ->and($cardPayment->fresh()->vendor_id)->toBeNull();
});
