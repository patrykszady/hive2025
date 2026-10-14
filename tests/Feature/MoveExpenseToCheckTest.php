<?php

use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\Check;
use App\Models\Expense;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * expenses:move-to-check moves an expense onto the check that paid it and
 * recalculates both: Las Hardwoods ($772.28) belonged on Grzegorz's check
 * 3492, not on paper check #2529 (2026-10-14).
 */
function moveExpenseFixture(): array
{
    config(['scout.driver' => 'null']);
    $company = Vendor::factory()->create(['business_type' => 'GC', 'business_name' => 'GS Construction']);
    $store = Vendor::factory()->create(['business_type' => 'Retail', 'business_name' => 'Las Hardwoods']);
    $payee = User::query()->create(['first_name' => 'Grzegorz', 'last_name' => 'Szady', 'email' => 'move-exp-'.uniqid().'@example.test', 'cell_phone' => '2245550009', 'password' => null, 'primary_vendor_id' => $company->id]);
    $other = User::query()->create(['first_name' => 'Other', 'last_name' => 'Person', 'email' => 'move-exp-'.uniqid().'@example.test', 'cell_phone' => '2245550010', 'password' => null, 'primary_vendor_id' => $company->id]);
    $bank = Bank::create(['name' => 'Citibank', 'vendor_id' => $company->id, 'plaid_ins_id' => 'ins_5']);
    $checking = BankAccount::create(['vendor_id' => $company->id, 'bank_id' => $bank->id, 'account_number' => '4903', 'plaid_account_id' => 'chk_'.uniqid(), 'type' => 'Checking']);
    $check = fn (string $date, ?int $userId) => Check::create(['check_type' => 'Transfer', 'date' => $date, 'bank_account_id' => $checking->id, 'amount' => 0, 'user_id' => $userId, 'belongs_to_vendor_id' => $company->id, 'created_by_user_id' => 0]);
    $august = $check('2025-08-27', $payee->id);
    $november = $check('2025-11-26', $payee->id);
    $stranger = $check('2025-11-26', $other->id);
    $expense = fn (float $amount, Check $on) => Expense::create(['amount' => $amount, 'date' => '2025-08-26', 'vendor_id' => $store->id, 'check_id' => $on->id, 'paid_by' => $payee->id, 'belongs_to_vendor_id' => $company->id, 'created_by_user_id' => 0]);
    $expense(1665.57, $august);
    $hardwoods = $expense(772.28, $november);
    $august->recalculateAmount();
    $november->recalculateAmount();

    return compact('august', 'november', 'stranger', 'hardwoods');
}

it('moves the expense onto the check that paid it and recalculates both checks', function (): void {
    $f = moveExpenseFixture();

    $this->artisan('expenses:move-to-check', ['expense' => $f['hardwoods']->id, 'check' => $f['august']->id])
        ->expectsOutputToContain('Moved.')
        ->assertSuccessful();

    expect((int) Expense::withoutGlobalScopes()->find($f['hardwoods']->id)->check_id)->toBe($f['august']->id)
        ->and((float) Check::withoutGlobalScopes()->find($f['august']->id)->amount)->toBe(2437.85)
        ->and((float) Check::withoutGlobalScopes()->find($f['november']->id)->amount)->toBe(0.0);
});

it('refuses a check paying someone else than the person who paid the expense', function (): void {
    $f = moveExpenseFixture();

    $this->artisan('expenses:move-to-check', ['expense' => $f['hardwoods']->id, 'check' => $f['stranger']->id])->assertFailed();

    expect((int) Expense::withoutGlobalScopes()->find($f['hardwoods']->id)->check_id)->toBe($f['november']->id);
});

it('changes nothing on a dry run', function (): void {
    $f = moveExpenseFixture();

    $this->artisan('expenses:move-to-check', ['expense' => $f['hardwoods']->id, 'check' => $f['august']->id, '--dry-run' => true])
        ->expectsOutputToContain('Dry run')
        ->assertSuccessful();

    expect((int) Expense::withoutGlobalScopes()->find($f['hardwoods']->id)->check_id)->toBe($f['november']->id)
        ->and((float) Check::withoutGlobalScopes()->find($f['august']->id)->amount)->toBe(1665.57);
});
