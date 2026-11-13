<?php

/**
 * Shared fixtures for the receipt ⇄ bank charge pairing tests
 * (ReceiptChargePairerTest, ReceiptChargePairingEntryPointsTest): the two
 * half-records of production's $41.00 dump ticket of 2026-11-04 — expense
 * 29031 from bank charge 30237 under Groot, expense 29068 from the scanned
 * ticket printed "Solid Waste Agency of Northern Cook County" with no
 * vendor. require_once this file rather than declaring these names twice.
 */

use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\Expense;
use App\Models\ExpenseReceipts;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vendor;

if (! function_exists('pairingCompany')) {
    /** The name the transfer station prints: the agency, not the operator the bank names. */
    define('PAIRING_PRINTED_NAME', 'Solid Waste Agency of Northern Cook County');

    /** The bank's text for the charge, card 4849 in it. */
    define('PAIRING_DESCRIPTOR', 'DEBIT PURCHASE Nov 04 4849 GROOT INDUSTRIES INCO ELK GROVE VIL 26309');

    /**
     * A company (vendor id 1, like the app API's tests) with a checking
     * account, and Groot as a retail vendor. Sets test()->company,
     * test()->groot and test()->account.
     */
    function pairingCompany(): void
    {
        $company = Vendor::withoutGlobalScopes()->find(1)
            ?? Vendor::factory()->create(['id' => 1, 'business_name' => 'GS Test Co', 'business_type' => 'Sub']);
        $company->forceFill(['registration' => ['registered' => true]])->save();

        test()->company = $company;
        test()->groot = pairingVendor('Groot');
        test()->account = pairingAccount($company);
    }

    /** A Retail vendor with no pinned address. */
    function pairingVendor(string $name): Vendor
    {
        return Vendor::factory()->create([
            'business_name' => $name, 'business_type' => 'Retail',
            'address' => null, 'address_2' => null, 'city' => null, 'state' => null, 'zip_code' => null,
        ]);
    }

    function pairingAccount(Vendor $company): BankAccount
    {
        $bank = Bank::create(['name' => 'Citibank', 'vendor_id' => $company->id, 'plaid_ins_id' => 'ins_pairing_'.uniqid()]);

        return BankAccount::create([
            'vendor_id' => $company->id, 'bank_id' => $bank->id, 'account_number' => '4903',
            'plaid_account_id' => 'acc_pairing_'.uniqid(), 'type' => 'Checking',
        ]);
    }

    /** An Admin of the company. */
    function pairingAdmin(): User
    {
        $user = User::factory()->create(['primary_vendor_id' => test()->company->id]);
        $user->vendors()->attach(test()->company->id, ['role_id' => 1]);

        return $user;
    }

    /**
     * A bank charge with no expense yet: $41.00 on 2026-11-04, card 4849,
     * known as Groot's.
     *
     * @param  array<string, mixed>  $attributes
     */
    function pairingCharge(array $attributes = []): Transaction
    {
        return Transaction::withoutGlobalScopes()->create(array_merge([
            'bank_account_id' => test()->account->id,
            'vendor_id' => test()->groot->id,
            'amount' => 41.00,
            'transaction_date' => '2026-11-04',
            'posted_date' => '2026-11-06',
            'plaid_merchant_description' => PAIRING_DESCRIPTOR,
            'plaid_merchant_name' => 'Groot Industries',
        ], $attributes));
    }

    /**
     * The charge half: an expense made from a bank charge, with no receipt.
     *
     * @param  array<string, mixed>  $expense
     * @param  array<string, mixed>  $charge
     */
    function pairingChargeExpense(array $expense = [], array $charge = []): Expense
    {
        $created = Expense::query()->create(array_merge([
            'amount' => 41.00, 'date' => '2026-11-04', 'vendor_id' => test()->groot->id,
            'belongs_to_vendor_id' => test()->company->id, 'created_by_user_id' => 0,
        ], $expense));

        pairingCharge(array_merge([
            'vendor_id' => $created->vendor_id ?: null,
            'amount' => $created->amount,
            'transaction_date' => $created->date->toDateString(),
            'posted_date' => $created->date->copy()->addDays(2)->toDateString(),
            'expense_id' => $created->id,
        ], $charge));

        return $created;
    }

    /**
     * The receipt half: an expense made from a scanned receipt, with no
     * vendor and no bank charge. $card is the last four the receipt prints,
     * null for a receipt that prints none.
     *
     * @param  array<string, mixed>  $expense
     * @param  array<string, mixed>  $items  receipt_items on top of the ticket's own
     */
    function pairingReceiptExpense(array $expense = [], ?string $card = '4849', string $printedName = PAIRING_PRINTED_NAME, array $items = []): Expense
    {
        $created = Expense::query()->create(array_merge([
            'amount' => 41.00, 'date' => '2026-11-04', 'vendor_id' => 0, 'invoice' => '4249778',
            'belongs_to_vendor_id' => test()->company->id, 'created_by_user_id' => 0,
        ], $expense));

        pairingReceipt($created, $card, $printedName, $items);

        return $created;
    }

    /**
     * @param  array<string, mixed>  $items
     */
    function pairingReceipt(Expense $expense, ?string $card = '4849', string $printedName = PAIRING_PRINTED_NAME, array $items = []): ExpenseReceipts
    {
        return ExpenseReceipts::forceCreate([
            'expense_id' => $expense->id,
            'receipt_filename' => $expense->id.'-2026-11-10-21-11-36-45.pdf',
            'receipt_html' => '',
            'receipt_items' => array_merge(pairingTicketFields((float) $expense->amount, $expense->date->toDateString(), $card, $printedName, (string) $expense->invoice), $items),
        ]);
    }

    /**
     * What the receipt reader returns for a transfer-station ticket.
     *
     * @return array<string, mixed>
     */
    function pairingTicketFields(float $amount, string $date, ?string $card = '4849', string $printedName = PAIRING_PRINTED_NAME, string $ticket = '4249778'): array
    {
        return [
            'merchant_name' => $printedName,
            'total' => $amount,
            'transaction_date' => $date,
            'invoice_number' => $ticket,
            'purchase_order' => null,
            'payment_methods' => $card === null ? [] : [['type' => 'CreditCard', 'amount' => $amount, 'last_four' => $card]],
            'raw_content' => "Glenview Transfer Station\nOperated by\nGroot Industries, Inc\nCustomer: G S CONSTRUCTION\nTicket No.:\n{$ticket}\nMaterial\nQty\nTRASH\n0.41",
            'items' => [],
        ];
    }

    /**
     * The models' own duplicate check (DuplicateExpenseCheck), off or on.
     * Off for tests that build the two halves first and then act: with it
     * on, the second half is paired with the first the moment it is saved.
     */
    function pairingAutomaticCheck(bool $on): void
    {
        app()->instance(\App\Services\Expenses\DuplicateExpenseCheck::class, $on
            ? new \App\Services\Expenses\DuplicateExpenseCheck
            : new class extends \App\Services\Expenses\DuplicateExpenseCheck
            {
                public function expenseChanged(int $expenseId): void {}
            });
    }

    function pairingTrashed(Expense $expense): bool
    {
        return Expense::withoutGlobalScopes()->withTrashed()->findOrFail($expense->id)->trashed();
    }

    function pairingReceiptCount(Expense $expense): int
    {
        return ExpenseReceipts::withoutGlobalScopes()->whereNull('deleted_at')->where('expense_id', $expense->id)->count();
    }
}
