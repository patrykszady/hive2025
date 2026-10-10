<?php

namespace App\Console\Commands;

use App\Models\Expense;
use App\Models\ExpensePayment;
use App\Support\ReceiptTenders;
use Illuminate\Console\Command;

/**
 * Development check of the tender parser against real receipts: the most
 * recent receipts whose text names store credit, a gift card or certificate,
 * a Menards rebate check, Pro Xtra Dollars or cash, and whose payment lines
 * do not add up to the expense. Those are the formats ReceiptTenders still
 * misreads — or expenses whose amount is wrong (Menards rebate checks were
 * corrected by hand for years). Reads only. Never runs in production: point
 * it at a copy of the data.
 */
class CheckReceiptTenders extends Command
{
    protected $signature = 'receipts:tender-check
        {--last=1000 : How many of the most recent receipts to check}
        {--show=40 : How many unread receipts to list}';

    protected $description = 'Development: list recent receipts whose store credit, gift card, certificate, rebate check, points or cash the parser cannot account for';

    /**
     * Tender words starting a line — never the survey footer's "...WIN A $5,000
     * HOME DEPOT GIFT CARD". Cash only with an amount after it: a "Cash" on its
     * own is a cash-sale account or a form's "check # or cash" box.
     */
    protected const OFF_BANK_WORDS = '/^(?:X{4,}\s?\d{4}\s*)?(?:STORE CREDIT|GIFT CARD|GIFT CERTIFICATE|CERTIFICATE|MENARDS? REBATE NO|PROXTRADOLLAR|PRO XTRA DOLLAR)\b|^CASH\b[^\n]*?\d+\.\d{2}|^CASH\s*\n\s*\$?\d+\.\d{2}/im';

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('receipts:tender-check reads a copy of the data in development; it does not run in production.');

            return self::FAILURE;
        }

        $expenseIds = \App\Models\ExpenseReceipts::query()
            ->join('expenses', 'expenses.id', '=', 'expense_receipts_data.expense_id')
            ->whereNull('expenses.deleted_at')
            ->orderByDesc('expenses.date')
            ->orderByDesc('expense_receipts_data.id')
            ->limit((int) $this->option('last'))
            ->pluck('expense_receipts_data.expense_id')
            ->unique()
            ->values();

        $expenses = Expense::withoutGlobalScopes()
            ->whereIn('id', $expenseIds)
            ->with(['receipts', 'vendor' => fn ($q) => $q->withoutGlobalScopes()])
            ->get();

        $counts = ['read' => 0, 'off_bank_read' => 0, 'off_bank_unread' => 0, 'card_unread' => 0, 'no_tender' => 0];
        $unread = [];

        foreach ($expenses->sortByDesc('date') as $expense) {
            $result = ReceiptTenders::forExpense($expense);
            $texts = $expense->receipts->map(fn ($receipt) => ReceiptTenders::receiptText($receipt));
            $mentions = $texts->contains(fn ($text) => preg_match(self::OFF_BANK_WORDS, $text) === 1);
            $offBankRead = $result['status'] === 'matched' && collect($result['lines'])->contains(fn ($line) => in_array($line['method'], ExpensePayment::OFF_BANK, true));

            if ($result['status'] === 'matched') {
                $counts['read']++;
                $offBankRead && $counts['off_bank_read']++;

                continue;
            }

            if (! $mentions && $result['status'] === 'none') {
                $counts['no_tender']++;

                continue;
            }

            $mentions ? $counts['off_bank_unread']++ : $counts['card_unread']++;

            if (count($unread) < (int) $this->option('show')) {
                $rebate = $texts->map(fn ($text) => ReceiptTenders::menardsRebateTotal($text))->filter()->first();
                $unread[] = [
                    $expense->id,
                    $expense->date?->format('Y-m-d'),
                    mb_strimwidth((string) $expense->vendor?->business_name, 0, 14, '…'),
                    number_format((float) $expense->amount, 2),
                    $result['found'] === null ? '-' : number_format((float) $result['found'], 2),
                    mb_strimwidth(implode(', ', array_map(fn ($l) => $l['method'].($l['last_four'] ? ' '.$l['last_four'] : '').' '.$l['amount'], $result['lines'])), 0, 54, '…') ?: 'nothing read',
                    $rebate ? 'Menards full total '.number_format($rebate['total'], 2) : ($mentions ? '' : 'card tenders do not add up'),
                ];
            }
        }

        $this->info(sprintf('%d expenses behind the last %d receipts:', $expenses->count(), (int) $this->option('last')));
        $this->table(['', 'Expenses'], [
            ['Payment lines add up to the expense', $counts['read']],
            ['   …of which store credit / gift card / points / cash', $counts['off_bank_read']],
            ['Store credit / gift card / certificate / rebate / points / cash NOT accounted for', $counts['off_bank_unread']],
            ['Card tenders that do not add up', $counts['card_unread']],
            ['No tender printed on the receipt', $counts['no_tender']],
        ]);

        if ($unread !== []) {
            $this->line('Not accounted for, or not adding up (newest first):');
            $this->table(['Expense', 'Date', 'Vendor', 'Amount', 'Lines sum', 'Lines read', 'Hint'], $unread);
        }

        return self::SUCCESS;
    }
}
