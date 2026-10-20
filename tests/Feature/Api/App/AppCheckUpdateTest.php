<?php

use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\Check;
use App\Models\Expense;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['scout.driver' => null]);
});

/** @return array{vendor: Vendor, admin: User, member: User} */
function appCheckUpdateTeam(): array
{
    $vendor = Vendor::query()->find(1) ?? Vendor::factory()->create(['id' => 1, 'business_name' => 'GS Construction']);

    $admin = User::factory()->create(['primary_vendor_id' => $vendor->id]);
    $vendor->users()->attach($admin->id, ['role_id' => 1]);

    $member = User::factory()->create(['primary_vendor_id' => $vendor->id]);
    $vendor->users()->attach($member->id, ['role_id' => 2]);

    return compact('vendor', 'admin', 'member');
}

/** @return array{account: BankAccount, sub: Vendor, check: Check} */
function appCheckUpdateFixture(Vendor $gc, User $admin): array
{
    $bank = Bank::create(['name' => 'Citibank', 'vendor_id' => $gc->id, 'plaid_ins_id' => 'ins_'.uniqid()]);
    $account = BankAccount::create(['vendor_id' => $gc->id, 'bank_id' => $bank->id, 'account_number' => '4903', 'plaid_account_id' => 'acc_'.uniqid(), 'type' => 'Checking']);
    $sub = Vendor::factory()->create(['business_type' => 'Sub', 'business_name' => 'RG Tile, Inc']);
    $gc->vendors()->attach($sub->id);
    $check = Check::create(['check_type' => 'Check', 'check_number' => '2638', 'date' => '2026-08-17', 'amount' => 5060, 'bank_account_id' => $account->id, 'vendor_id' => $sub->id, 'belongs_to_vendor_id' => $gc->id, 'created_by_user_id' => $admin->id]);

    return compact('account', 'sub', 'check');
}

it('needs a token to update a check', function () {
    $this->putJson('/api/app/v1/checks/1', [])->assertUnauthorized();
});

it('edits a check date and moves the expenses dated by the check, not a reimbursed purchase — same as the web', function () {
    ['vendor' => $gc, 'admin' => $admin] = appCheckUpdateTeam();
    Sanctum::actingAs($admin, ['app']);
    ['account' => $account, 'sub' => $sub, 'check' => $check] = appCheckUpdateFixture($gc, $admin);

    $mk = fn (string $date, float $amount) => Expense::withoutSyncingToSearch(fn () => Expense::create(['date' => $date, 'amount' => $amount, 'vendor_id' => $sub->id, 'check_id' => $check->id, 'belongs_to_vendor_id' => $gc->id, 'created_by_user_id' => $admin->id]));
    $a = $mk('2026-08-17', 3000);
    $own = $mk('2026-08-02', 60);

    $response = $this->putJson("/api/app/v1/checks/{$check->id}", [
        'bank_account_id' => $account->id,
        'check_type' => 'Check',
        'check_number' => $check->check_number,
        'vendor_id' => $sub->id,
        'date' => '2026-07-28',
    ])->assertOk();

    expect($check->fresh()->date->toDateString())->toBe('2026-07-28')
        ->and($a->fresh()->date->toDateString())->toBe('2026-07-28')
        ->and($own->fresh()->date->toDateString())->toBe('2026-08-02')
        ->and($response->json('data.date'))->toBe('2026-07-28');
});

it('will not date a check after the day the bank cleared it, same as the web', function () {
    ['vendor' => $gc, 'admin' => $admin] = appCheckUpdateTeam();
    Sanctum::actingAs($admin, ['app']);
    ['account' => $account, 'sub' => $sub, 'check' => $check] = appCheckUpdateFixture($gc, $admin);

    Transaction::withoutSyncingToSearch(fn () => Transaction::create(['transaction_date' => '2026-07-30', 'posted_date' => '2026-07-30', 'amount' => 5060, 'bank_account_id' => $account->id, 'check_number' => '2638', 'check_id' => $check->id, 'plaid_merchant_description' => 'CHECK 2638', 'plaid_transaction_id' => uniqid()]));

    $this->putJson("/api/app/v1/checks/{$check->id}", [
        'bank_account_id' => $account->id,
        'check_type' => 'Check',
        'check_number' => $check->check_number,
        'vendor_id' => $sub->id,
        'date' => '2026-08-17',
    ])->assertStatus(422)->assertJsonValidationErrors('date');

    expect($check->fresh()->date->toDateString())->toBe('2026-08-17');
});

it('reassigns the vendor on expenses this check paid when the payee changes, same as the web', function () {
    ['vendor' => $gc, 'admin' => $admin] = appCheckUpdateTeam();
    Sanctum::actingAs($admin, ['app']);
    ['account' => $account, 'sub' => $sub, 'check' => $check] = appCheckUpdateFixture($gc, $admin);

    $newPayee = Vendor::factory()->create(['business_type' => 'Sub', 'business_name' => 'New Payee LLC']);
    $gc->vendors()->attach($newPayee->id);
    $expense = Expense::withoutSyncingToSearch(fn () => Expense::create(['date' => '2026-08-17', 'amount' => 5060, 'vendor_id' => $sub->id, 'check_id' => $check->id, 'belongs_to_vendor_id' => $gc->id, 'created_by_user_id' => $admin->id]));

    $this->putJson("/api/app/v1/checks/{$check->id}", [
        'bank_account_id' => $account->id,
        'check_type' => 'Check',
        'check_number' => $check->check_number,
        'vendor_id' => $newPayee->id,
        'date' => '2026-08-17',
    ])->assertOk();

    expect($check->fresh()->vendor_id)->toBe($newPayee->id)
        ->and($expense->fresh()->vendor_id)->toBe($newPayee->id);
});

it('refuses a check number already used on the same bank account', function () {
    ['vendor' => $gc, 'admin' => $admin] = appCheckUpdateTeam();
    Sanctum::actingAs($admin, ['app']);
    ['account' => $account, 'sub' => $sub, 'check' => $check] = appCheckUpdateFixture($gc, $admin);

    Check::create(['check_type' => 'Check', 'check_number' => '9999', 'date' => '2026-08-01', 'amount' => 100, 'bank_account_id' => $account->id, 'vendor_id' => $sub->id, 'belongs_to_vendor_id' => $gc->id, 'created_by_user_id' => $admin->id]);

    $this->putJson("/api/app/v1/checks/{$check->id}", [
        'bank_account_id' => $account->id,
        'check_type' => 'Check',
        'check_number' => '9999',
        'vendor_id' => $sub->id,
        'date' => '2026-08-17',
    ])->assertStatus(422)->assertJsonValidationErrors('check_number');
});

it('refuses a Member editing or deleting a check, like the web (payer-admin only)', function () {
    ['vendor' => $gc, 'admin' => $admin, 'member' => $member] = appCheckUpdateTeam();
    ['account' => $account, 'sub' => $sub, 'check' => $check] = appCheckUpdateFixture($gc, $admin);

    Sanctum::actingAs($member, ['app']);

    $this->putJson("/api/app/v1/checks/{$check->id}", [
        'bank_account_id' => $account->id,
        'check_type' => 'Check',
        'check_number' => $check->check_number,
        'vendor_id' => $sub->id,
        'date' => '2026-08-17',
    ])->assertForbidden();

    $this->deleteJson("/api/app/v1/checks/{$check->id}")->assertForbidden();

    expect(Check::withoutGlobalScopes()->find($check->id)->trashed())->toBeFalse();
});

it('deletes a check, like the web\'s Remove button', function () {
    ['vendor' => $gc, 'admin' => $admin] = appCheckUpdateTeam();
    Sanctum::actingAs($admin, ['app']);
    ['check' => $check] = appCheckUpdateFixture($gc, $admin);

    $this->deleteJson("/api/app/v1/checks/{$check->id}")->assertOk();

    expect(Check::withoutGlobalScopes()->find($check->id)->trashed())->toBeTrue();
});

it('lists checking accounts with a live Plaid connection for the edit form\'s bank select', function () {
    ['vendor' => $gc, 'admin' => $admin] = appCheckUpdateTeam();
    Sanctum::actingAs($admin, ['app']);
    ['account' => $account] = appCheckUpdateFixture($gc, $admin);
    $account->bank->update(['plaid_access_token' => 'access-'.uniqid()]);

    // A savings account, and a checking account with no live Plaid
    // connection — both excluded, same as CheckCreate::mount().
    Bank::create(['name' => 'No Plaid Bank', 'vendor_id' => $gc->id, 'plaid_access_token' => null, 'plaid_ins_id' => 'ins_'.uniqid()])
        ->accounts()->create(['vendor_id' => $gc->id, 'account_number' => '1111', 'plaid_account_id' => 'acc_'.uniqid(), 'type' => 'Checking']);

    $response = $this->getJson('/api/app/v1/checks/bank-accounts')->assertOk();

    expect(collect($response->json('data'))->pluck('id')->all())->toBe([$account->id]);
});

it('shows the editable fields and payee_editable flag on the check detail', function () {
    ['vendor' => $gc, 'admin' => $admin] = appCheckUpdateTeam();
    Sanctum::actingAs($admin, ['app']);
    ['account' => $account, 'sub' => $sub, 'check' => $check] = appCheckUpdateFixture($gc, $admin);

    $response = $this->getJson("/api/app/v1/checks/{$check->id}")->assertOk();

    expect($response->json('data.editable.bank_account_id'))->toBe($account->id)
        ->and($response->json('data.editable.vendor_id'))->toBe($sub->id)
        ->and($response->json('data.editable.payee_editable'))->toBeTrue()
        ->and($response->json('data.editable.can_delete'))->toBeTrue();
});
