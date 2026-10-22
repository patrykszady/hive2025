<?php

use App\Livewire\ReceiptAccounts\ReceiptAccountVendorCreate;
use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\Distribution;
use App\Models\Expense;
use App\Models\Transaction;
use App\Models\TransactionBulkMatch;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * The expense modal's "Vendor match" button opens the vendor's matches with
 * a new Amount Match filled in from the form — expense 26319, the $179
 * Business Prime renewal, is what an "= $179, B2B Prime → OFFICE" rule
 * looks like.
 *
 * @return array<string, mixed>
 */
function vendorMatchFromExpenses_fixture(): array
{
    $company = Vendor::factory()->create(['business_name' => 'GS Test Co']);
    $amazon = Vendor::factory()->create(['business_name' => 'Amazon', 'business_type' => 'Retail']);
    $company->vendors()->syncWithoutDetaching([$amazon->id]);
    $office = Distribution::query()->create(['name' => 'OFFICE', 'vendor_id' => $company->id]);

    $user = new User();
    $user->forceFill([
        'first_name' => 'Match', 'last_name' => 'Maker',
        'email' => 'vendor-match-'.uniqid().'@example.test',
        'cell_phone' => fake()->unique()->numerify('224779####'),
        'primary_vendor_id' => $company->id,
    ]);
    $user->save();
    $company->users()->attach($user->id, ['role_id' => 1]);

    $bank = Bank::create(['name' => 'Citibank', 'vendor_id' => $company->id, 'plaid_ins_id' => 'ins_'.uniqid()]);
    $account = BankAccount::create(['vendor_id' => $company->id, 'bank_id' => $bank->id, 'account_number' => '4903', 'plaid_account_id' => 'acc_'.uniqid(), 'type' => 'Checking']);

    $expense = Expense::query()->create([
        'amount' => 179.00, 'date' => '2026-02-02', 'vendor_id' => $amazon->id, 'distribution_id' => $office->id,
        'belongs_to_vendor_id' => $company->id, 'created_by_user_id' => 0,
    ]);
    Transaction::withoutGlobalScopes()->create([
        'bank_account_id' => $account->id, 'vendor_id' => $amazon->id, 'amount' => 179.00, 'transaction_date' => '2026-02-04',
        'expense_id' => $expense->id, 'plaid_merchant_name' => 'B2b Prime', 'plaid_merchant_description' => 'DEBIT PURCHASE Feb 04 4849 B2B Prime*3R64644T3 26036',
    ]);
    $unmatched = Transaction::withoutGlobalScopes()->create([
        'bank_account_id' => $account->id, 'vendor_id' => $amazon->id, 'amount' => 41.10, 'transaction_date' => '2026-03-02',
        'plaid_merchant_name' => 'Amazon Mktpl', 'plaid_merchant_description' => 'DEBIT PURCHASE AMAZON MKTPL*2B4',
    ]);

    return compact('company', 'amazon', 'office', 'user', 'expense', 'unmatched');
}

it('sends the expense modal\'s amount, vendor, distribution and charge to the vendor match', function () {
    $fx = vendorMatchFromExpenses_fixture();

    $expenseModal = Livewire::actingAs($fx['user'])
        ->test(\App\Livewire\Expenses\ExpenseCreate::class, ['embedded' => true])
        ->instance();
    $expenseModal->editExpense($fx['expense']);
    $expenseModal->openVendorMatch();

    $event = collect(\Livewire\store($expenseModal)->get('dispatched', []))
        ->map(fn ($dispatched) => $dispatched->serialize())
        ->firstWhere('name', 'matchFor');

    expect($event['component'])->toBe('receipt-accounts.receipt-account-vendor-create')
        ->and($event['params']['vendor'])->toBe($fx['amazon']->id)
        ->and((float) $event['params']['amount'])->toBe(179.0)
        ->and($event['params']['description'])->toBe('B2b Prime')
        ->and($event['params']['distribution'])->toBe($fx['office']->id);
});

it('opens the vendor\'s matches with a new Amount Match on top, filled in, and saves it', function () {
    $fx = vendorMatchFromExpenses_fixture();
    $existing = TransactionBulkMatch::create([
        'vendor_id' => $fx['amazon']->id, 'amount' => 41.10, 'distribution_id' => null,
        'options' => ['amount_type' => '=', 'desc' => 'Amazon Mktpl', 'splits' => []], 'belongs_to_vendor_id' => $fx['company']->id,
    ]);

    $component = Livewire::actingAs($fx['user'])
        ->test(ReceiptAccountVendorCreate::class)
        ->call('matchFor', vendor: $fx['amazon']->id, amount: '179.00', description: 'B2b Prime', distribution: $fx['office']->id);

    $matches = $component->get('transactions_bulk_matches');
    expect($component->get('vendor')->id)->toBe($fx['amazon']->id)
        ->and($matches)->toHaveCount(2)
        ->and($matches[0])->toBe([
            'id' => null,
            'amount' => '179.00',
            'distribution_id' => $fx['office']->id,
            'options' => ['amount_type' => '=', 'desc' => 'B2b Prime'],
            'split' => false,
            'splits' => null,
        ])
        ->and($matches[1]['id'])->toBe($existing->id);

    $component->call('store')
        ->assertDispatched('modal-close', name: 'receipt_account_vendor_form_modal')
        // Saved from the expense modal: it closes itself on this event, a
        // round trip later (both in one response left it open).
        ->assertDispatched('vendor-match-saved');

    $rule = TransactionBulkMatch::query()->where('vendor_id', $fx['amazon']->id)->where('id', '!=', $existing->id)->sole();
    expect((float) $rule->amount)->toBe(179.0)
        ->and($rule->distribution_id)->toBe($fx['office']->id)
        ->and($rule->options['desc'])->toBe('B2b Prime')
        ->and($rule->belongs_to_vendor_id)->toBe($fx['company']->id);
});

it('never prefills another company\'s distribution', function () {
    $fx = vendorMatchFromExpenses_fixture();
    $stranger = vendorMatchFromExpenses_fixture();

    $matches = Livewire::actingAs($fx['user'])
        ->test(ReceiptAccountVendorCreate::class)
        ->call('matchFor', vendor: $fx['amazon']->id, amount: '41.10', distribution: $stranger['office']->id)
        ->get('transactions_bulk_matches');

    expect($matches[0]['distribution_id'])->toBeNull();
});

it('mounts the vendor match inside the expense modal under a real root tag', function () {
    // Livewire re-emits a child under the first tag of its HTML; a view that
    // opens with a marker comment records an empty tag and the parent's next
    // re-render throws "Invalid Livewire child tag name".
    $fx = vendorMatchFromExpenses_fixture();

    $children = Livewire::actingAs($fx['user'])
        ->test(\App\Livewire\Expenses\ExpenseCreate::class)
        ->snapshot['memo']['children'];

    expect($children['expense-vendor-match'][0] ?? null)->toBe('div');
});

it('leaves other modals alone when the vendor match was opened from /vendor_match', function () {
    $fx = vendorMatchFromExpenses_fixture();

    Livewire::actingAs($fx['user'])
        ->test(ReceiptAccountVendorCreate::class)
        ->call('editReceiptVendor', $fx['amazon']->id)
        ->call('store')
        ->assertDispatched('modal-close', name: 'receipt_account_vendor_form_modal')
        ->assertNotDispatched('vendor-match-saved');
});

it('applies a rule saved from a bank charge\'s expense modal right away and takes the charge off the card', function () {
    // State Farm, $15.87 (2026-10-22): the rule was saved and its pass linked
    // the charge 6 seconds later, but the modal stayed open and the charge
    // stayed on /expenses.
    $fx = vendorMatchFromExpenses_fixture();

    Livewire::actingAs($fx['user'])
        ->test(ReceiptAccountVendorCreate::class)
        ->call('matchFor', vendor: $fx['amazon']->id, amount: '41.10', description: 'Amazon Mktpl', distribution: $fx['office']->id, transaction: $fx['unmatched']->id)
        ->call('store')
        ->assertDispatched('transactions-bulk-linked', transactionIds: [$fx['unmatched']->id])
        ->assertDispatched('vendor-match-saved');

    $expense = Expense::withoutGlobalScopes()->find($fx['unmatched']->fresh()->expense_id);
    expect($expense)->not->toBeNull()
        ->and($expense->distribution_id)->toBe($fx['office']->id)
        ->and((float) $expense->amount)->toBe(41.1);
});

it('closes the expense modal once its vendor match is saved', function () {
    $fx = vendorMatchFromExpenses_fixture();

    Livewire::actingAs($fx['user'])
        ->test(\App\Livewire\Expenses\ExpenseCreate::class)
        ->call('editExpense', $fx['expense']->id)
        ->dispatch('vendor-match-saved')
        ->assertDispatched('modal-close', name: 'expenses_form_modal');
});

