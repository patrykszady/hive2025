<?php

use App\Livewire\Expenses\ExpenseShow;
use App\Livewire\Expenses\LinkTransaction;
use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\Expense;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * The case that prompted this (2026-09-28): a $498.03 Schwake Stone invoice
 * dated 09/24 was paid by a $497.23 card charge on 08/19. The matcher needs
 * equal amounts within -7/+21 days, so the pair can only be linked by hand.
 *
 * @return array<string, mixed>
 */
function linkTxn_fixture(int $roleId = 1): array
{
    $company = Vendor::factory()->create(['business_name' => 'GS Test Co']);
    $supplier = Vendor::factory()->create(['business_name' => 'Schwake Stone', 'business_type' => 'Sub']);
    $otherSupplier = Vendor::factory()->create(['business_name' => 'Other Supplier', 'business_type' => 'Retail']);

    $user = new User();
    $user->forceFill([
        'first_name' => 'Link',
        'last_name' => 'Tester',
        'email' => 'link-txn-'.uniqid().'@example.test',
        'cell_phone' => fake()->unique()->numerify('224777####'),
        'primary_vendor_id' => $company->id,
    ]);
    $user->save();
    $company->users()->attach($user->id, ['role_id' => $roleId]);

    $bank = Bank::create(['name' => 'Capital One', 'vendor_id' => $company->id, 'plaid_ins_id' => 'ins_'.uniqid()]);
    $account = BankAccount::create(['vendor_id' => $company->id, 'bank_id' => $bank->id, 'account_number' => '0616', 'plaid_account_id' => 'acc_'.uniqid(), 'type' => 'credit']);

    $stranger = Vendor::factory()->create(['business_name' => 'Another Company']);
    $strangerBank = Bank::create(['name' => 'Chase', 'vendor_id' => $stranger->id, 'plaid_ins_id' => 'ins_'.uniqid()]);
    $strangerAccount = BankAccount::create(['vendor_id' => $stranger->id, 'bank_id' => $strangerBank->id, 'account_number' => '9999', 'plaid_account_id' => 'acc_'.uniqid(), 'type' => 'credit']);

    $expense = Expense::query()->create([
        'amount' => 498.03,
        'date' => '2026-09-24',
        'vendor_id' => $supplier->id,
        'belongs_to_vendor_id' => $company->id,
        'created_by_user_id' => $user->id,
    ]);

    $txn = fn (array $attrs) => Transaction::withoutGlobalScopes()->create(array_merge([
        'bank_account_id' => $account->id,
        'plaid_merchant_name' => 'Schwake Stone Limited LLC',
        'plaid_merchant_description' => 'SCHWAKE STONE LIMITED LLC',
    ], $attrs));

    return [
        'user' => $user,
        'expense' => $expense,
        'charge' => $txn(['amount' => 497.23, 'transaction_date' => '2026-08-19', 'vendor_id' => $supplier->id]),
        'exactOtherVendor' => $txn(['amount' => 498.03, 'transaction_date' => '2026-09-20', 'vendor_id' => $otherSupplier->id, 'plaid_merchant_name' => 'Other Supplier']),
        'alreadyLinked' => $txn(['amount' => 120.00, 'transaction_date' => '2026-09-10', 'vendor_id' => $supplier->id, 'expense_id' => 999999]),
        'tooOld' => $txn(['amount' => 498.03, 'transaction_date' => '2026-06-01', 'vendor_id' => $supplier->id]),
        'unrelated' => $txn(['amount' => 50.00, 'transaction_date' => '2026-09-22', 'vendor_id' => $otherSupplier->id, 'plaid_merchant_name' => 'Other Supplier']),
        'otherCompany' => $txn(['amount' => 497.23, 'transaction_date' => '2026-08-19', 'vendor_id' => $supplier->id, 'bank_account_id' => $strangerAccount->id]),
    ];
}

it('lists the vendor\'s charge first even when the amount differs, then close amounts from others', function () {
    $fx = linkTxn_fixture();

    $component = Livewire::actingAs($fx['user'])
        ->test(LinkTransaction::class, ['expense' => $fx['expense']])
        ->call('openPicker')
        ->assertSee('Link a bank transaction')
        ->assertSee('−$0.80')
        ->assertSee('Exact');

    expect($component->instance()->candidates->pluck('id')->all())
        ->toBe([$fx['charge']->id, $fx['exactOtherVendor']->id]);
});

it('links the charge and keeps the expense amount', function () {
    $fx = linkTxn_fixture();

    Livewire::actingAs($fx['user'])
        ->test(LinkTransaction::class, ['expense' => $fx['expense']])
        ->call('link', $fx['charge']->id)
        ->assertRedirect(route('expenses.show', $fx['expense']));

    expect(Transaction::withoutGlobalScopes()->find($fx['charge']->id)->expense_id)->toBe($fx['expense']->id)
        ->and((float) $fx['expense']->fresh()->amount)->toBe(498.03);
});

it('refuses another company\'s charge and one already linked', function (string $which) {
    $fx = linkTxn_fixture();
    $before = $fx[$which]->fresh()->expense_id;

    Livewire::actingAs($fx['user'])
        ->test(LinkTransaction::class, ['expense' => $fx['expense']])
        ->call('link', $fx[$which]->id)
        ->assertNotFound();

    expect(Transaction::withoutGlobalScopes()->find($fx[$which]->id)->expense_id)->toBe($before);
})->with(['otherCompany', 'alreadyLinked']);

it('is for admins only', function () {
    $fx = linkTxn_fixture(roleId: 2);

    Livewire::actingAs($fx['user'])
        ->test(LinkTransaction::class, ['expense' => $fx['expense']])
        ->call('link', $fx['charge']->id)
        ->assertForbidden();

    expect(Transaction::withoutGlobalScopes()->find($fx['charge']->id)->expense_id)->toBeNull();
});

it('offers the link on the expense page only while nothing is linked', function () {
    $fx = linkTxn_fixture();

    Livewire::actingAs($fx['user'])
        ->test(ExpenseShow::class, ['expense' => $fx['expense']])
        ->assertSee('Link bank transaction');

    Transaction::withoutGlobalScopes()->whereKey($fx['charge']->id)->update(['expense_id' => $fx['expense']->id]);

    Livewire::actingAs($fx['user'])
        ->test(ExpenseShow::class, ['expense' => $fx['expense']->fresh()])
        ->assertDontSee('Link bank transaction');
});
