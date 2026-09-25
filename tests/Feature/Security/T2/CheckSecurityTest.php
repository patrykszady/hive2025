<?php

use App\Livewire\Checks\CheckCreate;
use App\Livewire\Forms\CheckForm;
use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\Check;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/** @return array{0: Vendor, 1: User, 2: BankAccount} A company, its admin, and its own checking account. */
function sec2c_company(): array
{
    $vendor = Vendor::factory()->create(['business_type' => 'LLC']);
    $admin = new User();
    $admin->forceFill([
        'first_name' => 'Admin', 'last_name' => 'User',
        'email' => 'sec2c-'.uniqid().'@example.test',
        'cell_phone' => fake()->unique()->numerify('224555####'),
        'password' => bcrypt('password'), 'primary_vendor_id' => $vendor->id,
    ]);
    $admin->save();
    $vendor->users()->attach($admin->id, ['role_id' => 1]);

    $bank = Bank::create(['name' => 'Bank of '.$vendor->id, 'vendor_id' => $vendor->id, 'plaid_ins_id' => 'ins_'.$vendor->id]);
    $account = BankAccount::create([
        'vendor_id' => $vendor->id, 'bank_id' => $bank->id, 'account_number' => '000'.$vendor->id,
        'plaid_account_id' => 'acc_'.$vendor->id, 'type' => 'Checking',
    ]);

    return [$vendor, $admin, $account];
}

/** A check the payer company wrote to the payee vendor. */
function sec2c_check(Vendor $payer, User $creator, BankAccount $account, Vendor $payee): Check
{
    return Check::forceCreate([
        'check_type' => 'Check',
        'check_number' => random_int(10000, 99999),
        'date' => '2026-06-01',
        'bank_account_id' => $account->id,
        'vendor_id' => $payee->id,
        'belongs_to_vendor_id' => $payer->id,
        'created_by_user_id' => $creator->id,
    ]);
}

// Finding 12: only the company that wrote the check (belongs_to_vendor_id)
// may edit or delete it — CheckScope also shows it to the payee's admin.
it('refuses the payee company from opening the payer\'s check to edit it', function () {
    [$payer, $payerAdmin, $account] = sec2c_company();
    [$payee, $payeeAdmin] = sec2c_company();
    $check = sec2c_check($payer, $payerAdmin, $account, $payee);

    Livewire::actingAs($payeeAdmin)
        ->test(CheckCreate::class)
        ->call('editCheck', $check)
        ->assertForbidden();
});

it('allows the payer company to open and edit its own check', function () {
    [$payer, $payerAdmin, $account] = sec2c_company();
    [$payee] = sec2c_company();
    $check = sec2c_check($payer, $payerAdmin, $account, $payee);

    Livewire::actingAs($payerAdmin)
        ->test(CheckCreate::class)
        ->call('editCheck', $check)
        ->assertSet('form.bank_account_id', $account->id);
});

it('refuses CheckForm::update() directly for a check belonging to another company', function () {
    [$payer, $payerAdmin, $account] = sec2c_company();
    [$payee, $payeeAdmin] = sec2c_company();
    $check = sec2c_check($payer, $payerAdmin, $account, $payee);
    test()->actingAs($payeeAdmin);

    $component = new CheckCreate();
    $form = new CheckForm($component, 'form');
    $form->setCheck($check);

    expect(fn () => $form->update())
        ->toThrow(\Illuminate\Auth\Access\AuthorizationException::class);
});

it('refuses CheckForm::delete() directly for a check belonging to another company', function () {
    [$payer, $payerAdmin, $account] = sec2c_company();
    [$payee, $payeeAdmin] = sec2c_company();
    $check = sec2c_check($payer, $payerAdmin, $account, $payee);
    test()->actingAs($payeeAdmin);

    $component = new CheckCreate();
    $form = new CheckForm($component, 'form');
    $form->setCheck($check);

    expect(fn () => $form->delete())
        ->toThrow(\Illuminate\Auth\Access\AuthorizationException::class);

    expect(Check::withoutGlobalScopes()->whereKey($check->id)->exists())->toBeTrue();
});

// bank_account_id/user_id must be scoped to the signed-in company.
it('rejects a bank account belonging to another company', function () {
    [$payer, $payerAdmin, $ownAccount] = sec2c_company();
    [, , $foreignAccount] = sec2c_company();
    [$payee] = sec2c_company();
    $check = sec2c_check($payer, $payerAdmin, $ownAccount, $payee);

    Livewire::actingAs($payerAdmin)
        ->test(CheckCreate::class)
        ->call('editCheck', $check)
        ->set('form.bank_account_id', $foreignAccount->id)
        ->call('edit')
        ->assertHasErrors('form.bank_account_id');

    expect($check->fresh()->bank_account_id)->toBe($ownAccount->id);
});

it('allows editing a check to a bank account the company owns', function () {
    [$payer, $payerAdmin, $account] = sec2c_company();
    [$payee] = sec2c_company();
    $check = sec2c_check($payer, $payerAdmin, $account, $payee);

    $secondBank = Bank::create(['name' => 'Second Bank', 'vendor_id' => $payer->id, 'plaid_ins_id' => 'ins_second_'.$payer->id]);
    $secondAccount = BankAccount::create([
        'vendor_id' => $payer->id, 'bank_id' => $secondBank->id, 'account_number' => '9999',
        'plaid_account_id' => 'acc_second_'.$payer->id, 'type' => 'Checking',
    ]);

    Livewire::actingAs($payerAdmin)
        ->test(CheckCreate::class)
        ->call('editCheck', $check)
        ->set('form.bank_account_id', $secondAccount->id)
        ->set('form.check_type', 'Cash')
        ->call('edit');

    expect($check->fresh()->bank_account_id)->toBe($secondAccount->id);
});
