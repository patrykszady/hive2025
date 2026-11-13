<?php

namespace App\Console\Commands;

use App\Http\Controllers\CompanyEmailController;
use App\Models\Expense;
use App\Models\ExpensePayment;
use App\Models\ExpenseReceipts;
use App\Models\Transaction;
use App\Models\Vendor;
use App\Services\Expenses\ReceiptChargePairer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Takes a wrongly linked bank charge off a receipt expense, and lets the
 * pairing rule place the receipt again.
 *
 * Until 2026-11-13 the receipt sweep (TransactionController::
 * add_transaction_to_expenses_sin_vendor) gave a vendor-less receipt the
 * nearest unlinked bank charge of the same amount, of any date and any
 * card, and that charge's vendor with it. On production that made one wrong
 * link: the $25 dump ticket of 2026-06-02 (expense 27088) took a Serpapi
 * charge from 2026-05-25 (transaction 28813) and was filed as "Serpapi",
 * while its own Groot charge got an expense of its own (27316).
 *
 * The charge comes off the expense (and off its card payment line). The
 * vendor goes back to none when it was the charge's and the name the
 * receipt prints is not that vendor's — it came with the wrong charge. Then
 * ReceiptChargePairer runs on the expense, which folds it into its true
 * charge's expense when the rule holds.
 */
class UnlinkReceiptCharge extends Command
{
    protected $signature = 'expenses:unlink-receipt-charge
        {expense : The receipt expense}
        {transaction : The bank charge wrongly linked to it}
        {--dry-run : Say what would change; change nothing}';

    protected $description = 'Take a wrongly linked bank charge off a receipt expense, and let the pairing rule place the receipt again';

    public function handle(ReceiptChargePairer $pairer): int
    {
        $expense = Expense::withoutGlobalScopes()->whereNull('deleted_at')->find((int) $this->argument('expense'));
        $charge = Transaction::withoutGlobalScopes()->whereNull('deleted_at')->find((int) $this->argument('transaction'));

        $refusal = match (true) {
            $expense === null => 'There is no expense #'.$this->argument('expense').'.',
            $charge === null => 'There is no transaction #'.$this->argument('transaction').'.',
            (int) $charge->expense_id !== (int) $expense->id => 'Transaction #'.$charge->id.' is not linked to expense #'.$expense->id.'.',
            ! ExpenseReceipts::withoutGlobalScopes()->whereNull('deleted_at')->where('expense_id', $expense->id)->exists() => 'Expense #'.$expense->id.' has no receipt: there would be nothing left of it.',
            default => null,
        };

        if ($refusal !== null) {
            $this->error($refusal);

            return self::FAILURE;
        }

        $printed = $this->printedName($expense);
        $resetVendor = (int) $expense->vendor_id > 0
            && (int) $expense->vendor_id === (int) $charge->vendor_id
            && ! $this->printedNameIsVendors($printed, (int) $expense->vendor_id);

        $this->line(sprintf(
            'Expense #%d ($%s, %s, "%s", filed under %s) — transaction #%d ($%s on %s, "%s").',
            $expense->id, number_format((float) $expense->amount, 2), $expense->date->toDateString(), $printed, $this->vendorName((int) $expense->vendor_id),
            $charge->id, number_format((float) $charge->amount, 2), $charge->transaction_date->toDateString(), (string) $charge->plaid_merchant_description,
        ));
        $this->line('- the charge comes off the expense');
        $this->line($resetVendor ? '- the expense goes back to no vendor: '.$this->vendorName((int) $expense->vendor_id).' came with the charge, and the receipt prints "'.$printed.'"' : '- the expense keeps its vendor');
        $this->line('- then the pairing rule places the receipt');

        if ($this->option('dry-run')) {
            $this->info('Dry run: nothing changed.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($expense, $charge, $resetVendor): void {
            $charge->expense_id = null;
            $charge->save();

            ExpensePayment::query()
                ->where('expense_id', $expense->id)
                ->where('transaction_id', $charge->id)
                ->update(['transaction_id' => null]);

            if ($resetVendor) {
                $expense->vendor_id = 0;
                $expense->save();
            }
        });

        $expense->refresh()->searchable();
        $charge->refresh()->searchable();

        $outcome = $pairer->pairExpense($expense);

        $this->info('Unlinked transaction #'.$charge->id.' from expense #'.$expense->id.'.');
        $this->line(match (true) {
            $outcome === null => 'The pairing rule found no charge for the receipt; it stays an expense of its own.',
            $outcome['status'] === ReceiptChargePairer::PAIRED => 'Paired: '.$outcome['reason'].($outcome['charge_expense_id'] ? ' — now expense #'.$outcome['charge_expense_id'].'.' : '.'),
            default => 'Not paired: '.$outcome['reason'].'.',
        });

        return self::SUCCESS;
    }

    private function printedName(Expense $expense): string
    {
        $receipt = ExpenseReceipts::withoutGlobalScopes()->whereNull('deleted_at')->where('expense_id', $expense->id)->latest('id')->first();
        $name = is_array($receipt?->receipt_items) ? ($receipt->receipt_items['merchant_name'] ?? '') : '';

        return is_string($name) ? trim((string) preg_replace('/\s+/u', ' ', $name)) : '';
    }

    /** Whether the receipt intake's own lookup reads the printed name as this vendor. */
    private function printedNameIsVendors(string $printed, int $vendorId): bool
    {
        if ($printed === '') {
            return true;
        }

        $match = app(CompanyEmailController::class)->fuzzyMatchVendor($printed, Vendor::withoutGlobalScopes()->get(['id', 'business_name']));

        return (int) $match?->id === $vendorId;
    }

    private function vendorName(int $vendorId): string
    {
        return $vendorId > 0 ? (string) (Vendor::withoutGlobalScopes()->whereKey($vendorId)->value('business_name') ?? 'vendor #'.$vendorId) : 'no vendor';
    }
}
