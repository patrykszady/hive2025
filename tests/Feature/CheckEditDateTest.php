<?php

use App\Livewire\Checks\CheckCreate;
use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\Check;
use App\Models\Expense;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function checkDateFixture(): array
{
    $gc = Vendor::factory()->create(['business_type' => 'GC']);
    $user = User::query()->create(['first_name' => 'Check', 'last_name' => 'Admin', 'email' => 'check-'.uniqid().'@example.test', 'cell_phone' => '224'.rand(1000000, 9999999), 'primary_vendor_id' => $gc->id]);
    $user->vendors()->attach($gc->id, ['role_id' => 1]);
    test()->actingAs($user);
    $bank = Bank::create(['name' => 'Citibank', 'vendor_id' => $gc->id, 'plaid_ins_id' => 'ins_5']);
    $account = BankAccount::create(['vendor_id' => $gc->id, 'bank_id' => $bank->id, 'account_number' => '4903', 'plaid_account_id' => 'acc_'.uniqid(), 'type' => 'Checking']);
    $sub = Vendor::factory()->create(['business_type' => 'Sub', 'business_name' => 'RG Tile, Inc']);
    $gc->vendors()->attach($sub->id);
    $check = Check::create(['check_type' => 'Check', 'check_number' => '2638', 'date' => '2026-08-17', 'amount' => 5060, 'bank_account_id' => $account->id, 'vendor_id' => $sub->id, 'belongs_to_vendor_id' => $gc->id, 'created_by_user_id' => $user->id]);

    return compact('gc', 'user', 'account', 'sub', 'check');
}

it('edits a check date and moves the expenses dated by the check, not a reimbursed purchase', function () {
    $f = checkDateFixture();
    $mk = fn (string $date, float $amount) => Expense::withoutSyncingToSearch(fn () => Expense::create(['date' => $date, 'amount' => $amount, 'vendor_id' => $f['sub']->id, 'check_id' => $f['check']->id, 'belongs_to_vendor_id' => $f['gc']->id, 'created_by_user_id' => $f['user']->id]));
    $a = $mk('2026-08-17', 3000);
    $b = $mk('2026-08-17', 2000);
    $own = $mk('2026-08-02', 60);

    Livewire::test(CheckCreate::class)
        ->call('editCheck', $f['check']->id)
        ->assertSet('form.date', '2026-08-17')
        ->set('form.date', '2026-07-28')
        ->call('edit')
        ->assertHasNoErrors();

    expect($f['check']->fresh()->date->toDateString())->toBe('2026-07-28')
        ->and($a->fresh()->date->toDateString())->toBe('2026-07-28')
        ->and($b->fresh()->date->toDateString())->toBe('2026-07-28')
        ->and($own->fresh()->date->toDateString())->toBe('2026-08-02');
});

it('will not date a check after the day the bank cleared it', function () {
    $f = checkDateFixture();
    Transaction::withoutSyncingToSearch(fn () => Transaction::create(['transaction_date' => '2026-07-30', 'posted_date' => '2026-07-30', 'amount' => 5060, 'bank_account_id' => $f['account']->id, 'check_number' => '2638', 'check_id' => $f['check']->id, 'plaid_merchant_description' => 'CHECK 2638', 'plaid_transaction_id' => uniqid()]));

    Livewire::test(CheckCreate::class)
        ->call('editCheck', $f['check']->id)
        ->set('form.date', '2026-08-17')
        ->call('edit')
        ->assertHasErrors(['form.date' => 'before_or_equal']);

    Livewire::test(CheckCreate::class)
        ->call('editCheck', $f['check']->id)
        ->set('form.date', '2026-07-30')
        ->call('edit')
        ->assertHasNoErrors();
    expect($f['check']->fresh()->date->toDateString())->toBe('2026-07-30');
});

it('matches a paper check to its charge by number and amount despite the dates, and takes the date it cleared', function () {
    $f = checkDateFixture();
    $payment = Expense::withoutSyncingToSearch(fn () => Expense::create(['date' => '2026-08-17', 'amount' => 5060, 'vendor_id' => $f['sub']->id, 'check_id' => $f['check']->id, 'belongs_to_vendor_id' => $f['gc']->id, 'created_by_user_id' => $f['user']->id]));
    $charge = Transaction::withoutSyncingToSearch(fn () => Transaction::create(['transaction_date' => '2026-07-30', 'posted_date' => '2026-07-30', 'amount' => 5060, 'bank_account_id' => $f['account']->id, 'check_number' => '2638', 'plaid_merchant_description' => 'CHECK 2638', 'plaid_transaction_id' => uniqid()]));
    // Same amount, another number: never this check.
    $other = Transaction::withoutSyncingToSearch(fn () => Transaction::create(['transaction_date' => '2026-08-17', 'posted_date' => '2026-08-17', 'amount' => 5060, 'bank_account_id' => $f['account']->id, 'check_number' => '2639', 'plaid_merchant_description' => 'CHECK 2639', 'plaid_transaction_id' => uniqid()]));

    auth()->logout();
    Transaction::withoutSyncingToSearch(fn () => app(\App\Http\Controllers\TransactionController::class)->add_check_id_to_transactions());

    expect($charge->fresh()->check_id)->toBe($f['check']->id)
        ->and($other->fresh()->check_id)->toBeNull()
        ->and($f['check']->fresh()->date->toDateString())->toBe('2026-07-30')
        ->and($payment->fresh()->date->toDateString())->toBe('2026-07-30');
});
