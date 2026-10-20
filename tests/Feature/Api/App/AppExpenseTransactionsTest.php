<?php

use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\Check;
use App\Models\CheckImage;
use App\Models\Expense;
use App\Models\Payment;
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
function appExpenseTxTeam(): array
{
    $vendor = Vendor::query()->find(1) ?? Vendor::factory()->create(['id' => 1, 'business_name' => 'GS Construction']);

    $admin = User::factory()->create(['primary_vendor_id' => $vendor->id]);
    $vendor->users()->attach($admin->id, ['role_id' => 1]);

    $member = User::factory()->create(['primary_vendor_id' => $vendor->id]);
    $vendor->users()->attach($member->id, ['role_id' => 2]);

    return compact('vendor', 'admin', 'member');
}

/** @return array{bank: Bank, account: BankAccount} */
function appExpenseTxBankAccount(Vendor $vendor, array $overrides = []): array
{
    $bank = Bank::create(['name' => 'Citibank', 'vendor_id' => $vendor->id, 'plaid_ins_id' => 'ins_'.uniqid()]);
    $account = BankAccount::create(array_merge([
        'vendor_id' => $vendor->id, 'bank_id' => $bank->id, 'account_number' => '4903',
        'plaid_account_id' => 'acc_'.uniqid(), 'type' => 'checking',
    ], $overrides));

    return ['bank' => $bank, 'account' => $account];
}

function appExpenseTxTransaction(BankAccount $account, array $overrides = []): Transaction
{
    return Transaction::create(array_merge([
        'transaction_date' => now()->format('Y-m-d'),
        'amount' => 42.00,
        'bank_account_id' => $account->id,
        'plaid_transaction_id' => 'tx_'.uniqid(),
        'plaid_merchant_description' => 'ACE HARDWARE',
    ], $overrides));
}

// --- index (the Transactions card) ----------------------------------------

it('needs a token to list unmatched transactions', function () {
    $this->getJson('/api/app/v1/expenses/transactions')->assertUnauthorized();
});

it('lists an unmatched transaction but excludes one with an expense, a check, or a payment', function () {
    ['vendor' => $vendor, 'admin' => $admin] = appExpenseTxTeam();
    ['account' => $account] = appExpenseTxBankAccount($vendor);
    $merchant = Vendor::factory()->create(['business_name' => 'Home Depot']);
    $expense = Expense::create(['amount' => 10, 'date' => now()->format('Y-m-d'), 'vendor_id' => $merchant->id, 'belongs_to_vendor_id' => $vendor->id, 'created_by_user_id' => $admin->id]);
    $check = Check::create(['check_type' => 'Check', 'check_number' => '1', 'date' => now()->format('Y-m-d'), 'bank_account_id' => $account->id, 'amount' => 10, 'vendor_id' => $merchant->id, 'belongs_to_vendor_id' => $vendor->id, 'created_by_user_id' => $admin->id]);

    $unmatched = appExpenseTxTransaction($account, ['amount' => 55.00]);
    $hasExpense = appExpenseTxTransaction($account, ['expense_id' => $expense->id]);
    $hasCheck = appExpenseTxTransaction($account, ['check_id' => $check->id]);
    $hasPayment = appExpenseTxTransaction($account);
    Payment::create(['amount' => 20, 'date' => now()->format('Y-m-d'), 'transaction_id' => $hasPayment->id, 'belongs_to_vendor_id' => $vendor->id, 'created_by_user_id' => $admin->id]);

    Sanctum::actingAs($admin, ['app']);

    $response = $this->getJson('/api/app/v1/expenses/transactions')->assertOk();

    expect($response->json('data.*.id'))->toBe([$unmatched->id])
        ->and($response->json('meta.total'))->toBe(1);
});

it('shows a person transfer (Venmo/Zelle) with no check, per the "person transfers stay visible" rule', function () {
    ['vendor' => $vendor, 'admin' => $admin] = appExpenseTxTeam();
    ['account' => $account] = appExpenseTxBankAccount($vendor);

    $venmo = appExpenseTxTransaction($account, [
        'amount' => 200.00,
        'check_number' => Transaction::TRANSFER_CHECK_NUMBER,
        'plaid_merchant_description' => 'VENMO *PATRYK SZADY',
    ]);

    Sanctum::actingAs($admin, ['app']);

    $response = $this->getJson('/api/app/v1/expenses/transactions')->assertOk();

    expect($response->json('data.*.id'))->toBe([$venmo->id])
        ->and($response->json('data.0.payment_label'))->toBe('Venmo');
});

it('scopes the Transactions card to the acting vendor\'s own bank accounts', function () {
    ['vendor' => $vendor, 'admin' => $admin] = appExpenseTxTeam();
    ['account' => $myAccount] = appExpenseTxBankAccount($vendor);
    $otherVendor = Vendor::factory()->create();
    ['account' => $otherAccount] = appExpenseTxBankAccount($otherVendor);

    $mine = appExpenseTxTransaction($myAccount);
    appExpenseTxTransaction($otherAccount);

    Sanctum::actingAs($admin, ['app']);

    $response = $this->getJson('/api/app/v1/expenses/transactions')->assertOk();

    expect($response->json('data.*.id'))->toBe([$mine->id]);
});

it('filters the Transactions card by vendor and date range', function () {
    ['vendor' => $vendor, 'admin' => $admin] = appExpenseTxTeam();
    ['account' => $account] = appExpenseTxBankAccount($vendor);
    $merchant = Vendor::factory()->create();

    $match = appExpenseTxTransaction($account, ['vendor_id' => $merchant->id, 'transaction_date' => '2026-05-01']);
    appExpenseTxTransaction($account, ['transaction_date' => '2026-05-01']);
    appExpenseTxTransaction($account, ['vendor_id' => $merchant->id, 'transaction_date' => '2026-01-01']);

    Sanctum::actingAs($admin, ['app']);

    $response = $this->getJson('/api/app/v1/expenses/transactions?vendor='.$merchant->id.'&date_from=2026-04-01&date_to=2026-06-01')->assertOk();

    expect($response->json('data.*.id'))->toBe([$match->id]);
});

it('searches the Transactions card by description text, same placeholder as the web ("e.g. ZELLE")', function () {
    ['vendor' => $vendor, 'admin' => $admin] = appExpenseTxTeam();
    ['account' => $account] = appExpenseTxBankAccount($vendor);

    $zelle = appExpenseTxTransaction($account, ['check_number' => Transaction::TRANSFER_CHECK_NUMBER, 'plaid_merchant_description' => 'ZELLE TO JOHN DOE']);
    appExpenseTxTransaction($account, ['plaid_merchant_description' => 'MENARDS']);

    Sanctum::actingAs($admin, ['app']);

    $response = $this->getJson('/api/app/v1/expenses/transactions?search=zelle')->assertOk();

    expect($response->json('data.*.id'))->toBe([$zelle->id]);
});

it('falls back to the shared amount filter for the Transactions card when no search text is given', function () {
    ['vendor' => $vendor, 'admin' => $admin] = appExpenseTxTeam();
    ['account' => $account] = appExpenseTxBankAccount($vendor);

    $match = appExpenseTxTransaction($account, ['amount' => 112.20]);
    appExpenseTxTransaction($account, ['amount' => 50.00]);

    Sanctum::actingAs($admin, ['app']);

    $response = $this->getJson('/api/app/v1/expenses/transactions?amount=112.20')->assertOk();

    expect($response->json('data.*.id'))->toBe([$match->id]);
});

// --- show (the "create an expense from this transaction" pre-fill) --------

it('404s a transaction that is no longer unmatched from the prefill endpoint', function () {
    ['vendor' => $vendor, 'admin' => $admin] = appExpenseTxTeam();
    ['account' => $account] = appExpenseTxBankAccount($vendor);
    $merchant = Vendor::factory()->create();
    $expense = Expense::create(['amount' => 10, 'date' => now()->format('Y-m-d'), 'vendor_id' => $merchant->id, 'belongs_to_vendor_id' => $vendor->id, 'created_by_user_id' => $admin->id]);
    $claimed = appExpenseTxTransaction($account, ['expense_id' => $expense->id]);

    Sanctum::actingAs($admin, ['app']);

    $this->getJson('/api/app/v1/expenses/transactions/'.$claimed->id)->assertNotFound();
    $this->getJson('/api/app/v1/expenses/transactions/999999999')->assertNotFound();
});

it('pre-fills amount/date/vendor and maps the check_number to a check_type', function () {
    ['vendor' => $vendor, 'admin' => $admin] = appExpenseTxTeam();
    ['account' => $account] = appExpenseTxBankAccount($vendor);
    $merchant = Vendor::factory()->create(['business_name' => 'Ace Hardware']);
    // VendorScope hides an unrelated vendor from the auth tenant's reads —
    // link it the same way "Belongs To Vendor" does, so the prefill's
    // vendor relation resolves instead of coming back null.
    $vendor->vendors()->attach($merchant->id);

    $transaction = appExpenseTxTransaction($account, [
        'amount' => 88.40, 'transaction_date' => '2026-03-10', 'vendor_id' => $merchant->id,
        'check_number' => '512',
    ]);

    Sanctum::actingAs($admin, ['app']);

    $response = $this->getJson('/api/app/v1/expenses/transactions/'.$transaction->id)->assertOk();

    $response->assertJsonPath('data.amount', 88.4)
        ->assertJsonPath('data.date', '2026-03-10')
        ->assertJsonPath('data.vendor.name', 'Ace Hardware')
        ->assertJsonPath('data.bank_account_id', $account->id)
        ->assertJsonPath('data.check_type', 'Check')
        ->assertJsonPath('data.check_number', '512')
        ->assertJsonPath('data.check_image', null);
});

it('maps the transfer/cash magic check numbers on the prefill, like ExpenseCreate::createExpenseFromTransaction()', function () {
    ['vendor' => $vendor, 'admin' => $admin] = appExpenseTxTeam();
    ['account' => $account] = appExpenseTxBankAccount($vendor);

    $transfer = appExpenseTxTransaction($account, ['check_number' => Transaction::TRANSFER_CHECK_NUMBER]);
    $cash = appExpenseTxTransaction($account, ['check_number' => Transaction::CASH_CHECK_NUMBER]);

    Sanctum::actingAs($admin, ['app']);

    $this->getJson('/api/app/v1/expenses/transactions/'.$transfer->id)->assertOk()
        ->assertJsonPath('data.check_type', 'Transfer')
        ->assertJsonPath('data.check_number', null);

    $this->getJson('/api/app/v1/expenses/transactions/'.$cash->id)->assertOk()
        ->assertJsonPath('data.check_type', 'Cash');
});

it('includes the scanned check image on the prefill, like the web\'s form.blade.php $transactionCheckImage block', function () {
    ['vendor' => $vendor, 'admin' => $admin] = appExpenseTxTeam();
    ['account' => $account] = appExpenseTxBankAccount($vendor);
    $transaction = appExpenseTxTransaction($account, ['check_number' => '700']);

    CheckImage::create([
        'image_filename' => 'check-700.jpg',
        'transaction_id' => $transaction->id,
        'belongs_to_vendor_id' => $vendor->id,
        'payee' => 'Handwritten Payee',
    ]);

    Sanctum::actingAs($admin, ['app']);

    $response = $this->getJson('/api/app/v1/expenses/transactions/'.$transaction->id)->assertOk();

    expect($response->json('data.check_image.url'))->toContain('checks/images')
        ->and($response->json('data.check_image.payee_name'))->toBe('Handwritten Payee');
});

// --- creating an expense FROM a transaction (ExpenseForm::store()'s transaction branch) --

it('links the transaction to a brand-new check created alongside the expense', function () {
    ['vendor' => $vendor, 'admin' => $admin] = appExpenseTxTeam();
    ['account' => $account] = appExpenseTxBankAccount($vendor);
    $project = appExpensesProjectForTx($vendor);
    $merchant = Vendor::factory()->create();
    $transaction = appExpenseTxTransaction($account, ['amount' => 64.10, 'check_number' => '900']);

    Sanctum::actingAs($admin, ['app']);

    $response = $this->postJson('/api/app/v1/expenses', [
        'amount' => '64.10', 'date' => now()->format('Y-m-d'), 'vendor_id' => $merchant->id,
        'project_id' => $project->id, 'transaction_id' => $transaction->id,
    ])->assertCreated();

    $expense = Expense::findOrFail($response->json('data.id'));
    $transaction->refresh();

    expect($expense->check_id)->not->toBeNull()
        ->and((int) $transaction->check_id)->toBe($expense->check_id)
        ->and($transaction->expense_id)->toBeNull();

    $check = Check::findOrFail($expense->check_id);
    expect((string) $check->check_number)->toBe('900')
        ->and((int) $check->bank_account_id)->toBe($account->id);
});

it('links the transaction directly to the expense when no check is involved (paid_by)', function () {
    ['vendor' => $vendor, 'admin' => $admin, 'member' => $member] = appExpenseTxTeam();
    ['account' => $account] = appExpenseTxBankAccount($vendor);
    $project = appExpensesProjectForTx($vendor);
    $merchant = Vendor::factory()->create();
    // No check_number on this one — an employee-paid charge with nothing to record as a check.
    $transaction = appExpenseTxTransaction($account, ['amount' => 30.00]);

    Sanctum::actingAs($admin, ['app']);

    $response = $this->postJson('/api/app/v1/expenses', [
        'amount' => '30.00', 'date' => now()->format('Y-m-d'), 'vendor_id' => $merchant->id,
        'project_id' => $project->id, 'paid_by' => $member->id, 'transaction_id' => $transaction->id,
    ])->assertCreated();

    $expense = Expense::findOrFail($response->json('data.id'));
    $transaction->refresh();

    expect($expense->check_id)->toBeNull()
        ->and((int) $transaction->expense_id)->toBe($expense->id);
});

it('sets the transaction\'s vendor_id to the expense\'s merchant when it differs from the hub vendor', function () {
    ['vendor' => $vendor, 'admin' => $admin] = appExpenseTxTeam();
    ['account' => $account] = appExpenseTxBankAccount($vendor);
    $project = appExpensesProjectForTx($vendor);
    $merchant = Vendor::factory()->create(['business_name' => 'Menards']);
    $transaction = appExpenseTxTransaction($account, ['amount' => 15.00]);

    Sanctum::actingAs($admin, ['app']);

    $this->postJson('/api/app/v1/expenses', [
        'amount' => '15.00', 'date' => now()->format('Y-m-d'), 'vendor_id' => $merchant->id,
        'project_id' => $project->id, 'transaction_id' => $transaction->id,
        'bank_account_id' => $account->id, 'check_type' => 'Cash',
    ])->assertCreated();

    $transaction->refresh();
    expect((int) $transaction->vendor_id)->toBe($merchant->id);
});

it('refuses creating an expense from an already-matched transaction (422)', function () {
    ['vendor' => $vendor, 'admin' => $admin] = appExpenseTxTeam();
    ['account' => $account] = appExpenseTxBankAccount($vendor);
    $project = appExpensesProjectForTx($vendor);
    $merchant = Vendor::factory()->create();
    $alreadyMatched = Expense::create(['amount' => 5, 'date' => now()->format('Y-m-d'), 'vendor_id' => $merchant->id, 'belongs_to_vendor_id' => $vendor->id, 'created_by_user_id' => $admin->id]);
    $transaction = appExpenseTxTransaction($account, ['expense_id' => $alreadyMatched->id]);

    Sanctum::actingAs($admin, ['app']);

    $this->postJson('/api/app/v1/expenses', [
        'amount' => '5.00', 'date' => now()->format('Y-m-d'), 'vendor_id' => $merchant->id,
        'project_id' => $project->id, 'transaction_id' => $transaction->id,
    ])->assertStatus(422)->assertJsonValidationErrors(['transaction_id']);
});

it('refuses creating an expense from a transaction outside the tenant\'s own bank accounts (422)', function () {
    ['vendor' => $vendor, 'admin' => $admin] = appExpenseTxTeam();
    $project = appExpensesProjectForTx($vendor);
    $merchant = Vendor::factory()->create();
    $otherVendor = Vendor::factory()->create();
    ['account' => $otherAccount] = appExpenseTxBankAccount($otherVendor);
    $foreignTransaction = appExpenseTxTransaction($otherAccount);

    Sanctum::actingAs($admin, ['app']);

    $this->postJson('/api/app/v1/expenses', [
        'amount' => '42.00', 'date' => now()->format('Y-m-d'), 'vendor_id' => $merchant->id,
        'project_id' => $project->id, 'transaction_id' => $foreignTransaction->id,
    ])->assertStatus(422)->assertJsonValidationErrors(['transaction_id']);
});

/** A project owned by $vendor and visible to it, same pattern as AppExpensesTest's appExpensesProject(). */
function appExpensesProjectForTx(Vendor $vendor): \App\Models\Project
{
    $client = \App\Models\Client::factory()->create();

    $project = \App\Models\Project::withoutEvents(fn () => \App\Models\Project::create([
        'project_name' => 'Kitchen Remodel',
        'client_id' => $client->id,
        'address' => '123 Main St',
        'city' => 'Northbrook',
        'state' => 'IL',
        'zip_code' => '60062',
        'belongs_to_vendor_id' => $vendor->id,
    ]));

    $project->vendors()->attach($vendor->id, ['client_id' => $client->id]);

    return $project;
}
