<?php

use App\Livewire\Checks\CheckCreate;
use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\Check;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Regression guard for App\Services\Checks\CheckUpdateService::update():
 * editing a payroll check's own fields (date, bank, etc.) through the web's
 * CheckCreate/CheckForm must still keep its user_id — CheckForm::setCheck()/
 * update() always round-trips the SAME value explicitly, so the service's
 * "only write user_id when the request contains the key" guard (added for
 * the app, which used to omit it) must never affect the web's own path.
 */
it('keeps a payroll check\'s user_id when its date is edited from the web', function () {
    $gc = Vendor::factory()->create(['business_type' => 'GC']);
    $admin = User::query()->create(['first_name' => 'Check', 'last_name' => 'Admin', 'email' => 'check-admin-'.uniqid().'@example.test', 'cell_phone' => '224'.rand(1000000, 9999999), 'primary_vendor_id' => $gc->id]);
    $admin->vendors()->attach($gc->id, ['role_id' => 1, 'is_employed' => 1]);
    test()->actingAs($admin);

    $employee = User::query()->create(['first_name' => 'Payroll', 'last_name' => 'Employee', 'email' => 'check-employee-'.uniqid().'@example.test', 'cell_phone' => '224'.rand(1000000, 9999999), 'primary_vendor_id' => $gc->id]);
    $employee->vendors()->attach($gc->id, ['role_id' => 2, 'is_employed' => 1]);

    $bank = Bank::create(['name' => 'Citibank', 'vendor_id' => $gc->id, 'plaid_ins_id' => 'ins_'.uniqid()]);
    $account = BankAccount::create(['vendor_id' => $gc->id, 'bank_id' => $bank->id, 'account_number' => '4903', 'plaid_account_id' => 'acc_'.uniqid(), 'type' => 'Checking']);

    $check = Check::create([
        'check_type' => 'Check', 'check_number' => '7001', 'date' => '2026-08-17', 'amount' => 300,
        'bank_account_id' => $account->id, 'vendor_id' => null, 'user_id' => $employee->id,
        'belongs_to_vendor_id' => $gc->id, 'created_by_user_id' => $admin->id,
    ]);

    Livewire::test(CheckCreate::class)
        ->call('editCheck', $check->id)
        ->assertSet('form.user_id', $employee->id)
        ->set('form.date', '2026-07-28')
        ->call('edit')
        ->assertHasNoErrors();

    expect($check->fresh())
        ->date->toDateString()->toBe('2026-07-28')
        ->user_id->toBe($employee->id);
});
