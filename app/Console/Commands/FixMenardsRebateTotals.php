<?php

namespace App\Console\Commands;

use App\Models\Expense;
use App\Models\Vendor;
use App\Support\ReceiptTenders;
use Illuminate\Console\Command;

/**
 * Menards purchases paid partly with rebate checks were recorded at the
 * amount left after the checks. A rebate check spent at the register prints
 * BEFORE the total ("MENARD REBATE NO: 6323495338 / 214.98-"), so TOTAL SALE
 * — and Menards' own transaction total, which the receipt import used — is
 * what remained; the purchase cost TOTAL SALE plus the checks. Many of these
 * were corrected by hand over the years; on 2026-10-02 expense 25952 still
 * read $173.43 for a $216.67 purchase, and 26088 read -$82.50 for an $82.50
 * one paid entirely by a rebate check.
 *
 * Raises an expense to TOTAL SALE + rebate checks when it holds TOTAL SALE
 * (or the negative of the full total). Anything else is listed for a person.
 * Also puts the receipt's own subtotal / tax / total right (they held what
 * Menards printed after the checks: 25950 read $0.00 under $243.01 of
 * lumber), Menards' printed figures kept alongside.
 * Dry run unless --commit. Re-running is harmless.
 */
class FixMenardsRebateTotals extends Command
{
    protected $signature = 'expenses:fix-menards-rebate-totals
        {--expense=* : Only these expense IDs}
        {--commit : Change the amounts (default: list them)}';

    protected $description = 'Set Menards expenses paid partly with rebate checks to the full purchase total';

    public function handle(): int
    {
        $vendorIds = Vendor::withoutGlobalScopes()->where('business_name', 'Menards')->pluck('id');

        $query = Expense::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->whereIn('vendor_id', $vendorIds)
            ->whereHas('receipts', fn ($q) => $q->where(fn ($w) => $w->where('receipt_items', 'like', '%REBATE NO%')->orWhere('receipt_html', 'like', '%REBATE NO%')))
            ->with('receipts')
            ->orderBy('date');

        if ($expenseIds = array_filter(array_map('intval', (array) $this->option('expense')))) {
            $query->whereIn('id', $expenseIds);
        }

        $fix = [];
        $review = [];
        $alreadyFull = 0;
        $receiptsFixed = 0;

        foreach ($query->get() as $expense) {
            $receipt = $expense->receipts->first(fn ($r) => stripos(ReceiptTenders::receiptText($r), 'REBATE NO') !== false);
            $text = $receipt ? ReceiptTenders::receiptText($receipt) : '';
            $full = ReceiptTenders::menardsRebateTotal($text);

            $amount = round((float) $expense->amount, 2);
            $row = [$expense->id, $expense->date?->format('Y-m-d'), number_format($amount, 2)];

            if ($full === null || preg_match('/Return Transaction|REFUND/i', $text)) {
                $review[] = [...$row, $full ? number_format($full['sale'], 2) : '-', $full ? number_format($full['rebates'], 2) : '-', $full === null ? 'no TOTAL SALE or rebate amount found' : 'a return'];

                continue;
            }

            if (abs($amount - $full['total']) <= 0.02) {
                $alreadyFull++;
                $receiptsFixed += $this->correctReceipt($receipt, $text);

                continue;
            }

            $short = abs($amount - $full['sale']) <= 0.02;
            $wrongSign = abs($amount + $full['total']) <= 0.02 || abs($amount + $full['rebates']) <= 0.02;

            if (! $short && ! $wrongSign) {
                $review[] = [...$row, number_format($full['sale'], 2), number_format($full['rebates'], 2), 'amount is neither TOTAL SALE nor the full total'];

                continue;
            }

            $fix[] = [...$row, number_format($full['total'], 2), number_format($full['rebates'], 2), $short ? 'held TOTAL SALE' : 'wrong sign'];

            if ($this->option('commit')) {
                $expense->update(['amount' => $full['total']]);
            }

            $receiptsFixed += $this->correctReceipt($receipt, $text);
        }

        $this->info(sprintf('%d Menards expenses spent rebate checks: %d already at the full total, %d %s, %d to review.',
            $alreadyFull + count($fix) + count($review), $alreadyFull, count($fix), $this->option('commit') ? 'corrected' : 'to correct (dry run)', count($review)));
        $this->info(sprintf('Receipt subtotal / tax / total %s on %d of them.', $this->option('commit') ? 'corrected' : 'to correct', $receiptsFixed));

        if ($fix !== []) {
            $this->table(['Expense', 'Date', 'Amount', 'Full total', 'Rebate checks', 'Why'], $fix);
        }

        if ($review !== []) {
            $this->line('For a person to check:');
            $this->table(['Expense', 'Date', 'Amount', 'TOTAL SALE', 'Rebate checks', 'Why'], $review);
        }

        return self::SUCCESS;
    }

    /**
     * Put the receipt's subtotal / tax / total right — only for an expense
     * whose amount is (or is being made) the full total, never on a receipt
     * read too poorly to trust. Returns 1 when it needed it.
     */
    protected function correctReceipt(?\App\Models\ExpenseReceipts $receipt, string $text): int
    {
        if ($receipt === null) {
            return 0;
        }

        $fields = is_array($receipt->receipt_items) ? $receipt->receipt_items : [];
        $corrected = ReceiptTenders::withMenardsRebateTotals($fields, $text);
        $same = round((float) ($fields['subtotal'] ?? 0), 2) === $corrected['subtotal']
            && round((float) ($fields['total'] ?? 0), 2) === $corrected['total']
            && array_key_exists('total_tax', $fields) && round((float) $fields['total_tax'], 2) === $corrected['total_tax'];

        if ($same) {
            return 0;
        }

        if ($this->option('commit')) {
            $receipt->update(['receipt_items' => $corrected]);
        }

        return 1;
    }
}
