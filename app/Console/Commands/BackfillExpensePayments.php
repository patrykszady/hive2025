<?php

namespace App\Console\Commands;

use App\Models\Expense;
use App\Models\ExpensePayment;
use App\Services\ExpensePaymentSync;
use App\Support\ReceiptTenders;
use Illuminate\Console\Command;

/**
 * Records how each expense was paid — card, store credit, gift card, points,
 * cash — from data Hive already holds: Amazon's charges, the tender block
 * printed on store receipts, the receipt reader's payment_methods. No new
 * OCR. An expense gets lines only when they add up to its amount (see
 * ReceiptTenders::forExpense); the rest are reported.
 *
 * Dry run unless --commit. Re-running replaces the lines it wrote before and
 * never touches lines a person entered.
 */
class BackfillExpensePayments extends Command
{
    protected $signature = 'expenses:backfill-payments
        {--expense=* : Only these expense IDs}
        {--vendor=* : Only expenses of these vendor IDs}
        {--commit : Write the payment lines (default: report only)}
        {--mismatches=15 : How many expenses whose tenders do not add up to list}';

    protected $description = 'Record how expenses were paid (card, store credit, gift card, points, cash) from their receipts';

    public function handle(): int
    {
        $query = Expense::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->whereHas('receipts')
            ->with(['receipts', 'vendor' => fn ($q) => $q->withoutGlobalScopes()]);

        if ($expenseIds = array_filter(array_map('intval', (array) $this->option('expense')))) {
            $query->whereIn('id', $expenseIds);
        }

        if ($vendorIds = array_filter(array_map('intval', (array) $this->option('vendor')))) {
            $query->whereIn('vendor_id', $vendorIds);
        }

        $commit = (bool) $this->option('commit');
        $status = ['matched' => 0, 'mismatch' => 0, 'none' => 0];
        $bySource = [];
        $byMethod = [];
        $offBankExpenses = 0;
        $skippedManual = 0;
        $mismatches = [];

        $query->chunkById(200, function ($expenses) use ($commit, &$status, &$bySource, &$byMethod, &$offBankExpenses, &$skippedManual, &$mismatches) {
            foreach ($expenses as $expense) {
                $result = ReceiptTenders::forExpense($expense);
                $status[$result['status']]++;

                if ($result['status'] === 'mismatch' && count($mismatches) < (int) $this->option('mismatches')) {
                    $mismatches[] = [
                        $expense->id,
                        (string) $expense->date?->format('Y-m-d'),
                        mb_strimwidth((string) $expense->vendor?->business_name, 0, 18, '…'),
                        number_format((float) $expense->amount, 2),
                        $result['source'],
                        number_format((float) $result['found'], 2),
                        mb_strimwidth(implode(', ', array_map(fn ($l) => $l['method'].($l['last_four'] ? ' '.$l['last_four'] : '').' '.$l['amount'], $result['lines'])), 0, 60, '…'),
                    ];
                }

                if ($result['status'] !== 'matched') {
                    continue;
                }

                $bySource[$result['source']] = ($bySource[$result['source']] ?? 0) + 1;

                foreach ($result['lines'] as $line) {
                    $byMethod[$line['method']]['lines'] = ($byMethod[$line['method']]['lines'] ?? 0) + 1;
                    $byMethod[$line['method']]['amount'] = ($byMethod[$line['method']]['amount'] ?? 0) + abs($line['amount']);
                }

                if (collect($result['lines'])->contains(fn ($l) => in_array($l['method'], ExpensePayment::OFF_BANK, true))) {
                    $offBankExpenses++;
                }

                if (! $commit) {
                    continue;
                }

                if (app(ExpensePaymentSync::class)->sync($expense)['status'] === 'manual') {
                    $skippedManual++;
                }
            }
        });

        $total = array_sum($status);
        $this->info(sprintf('%d expenses with receipts: %d paid in full by the lines found, %d where the lines do not add up, %d with no tender found.%s',
            $total, $status['matched'], $status['mismatch'], $status['none'], $commit ? '' : ' (dry run)'));

        $this->table(['Source', 'Expenses'], collect($bySource)->sortDesc()->map(fn ($n, $source) => [$source, $n])->values()->all());
        $this->table(['Method', 'Lines', 'Total'], collect($byMethod)->sortByDesc('lines')->map(fn ($m, $method) => [$method, $m['lines'], number_format($m['amount'], 2)])->values()->all());
        $this->line("Expenses paid at least partly off the bank (store credit, gift card, points, cash): {$offBankExpenses}");

        if ($skippedManual > 0) {
            $this->line("Left alone because a person entered their payments: {$skippedManual}");
        }

        if ($mismatches !== []) {
            $this->line('Tenders that do not add up to the expense (first '.count($mismatches).'):');
            $this->table(['Expense', 'Date', 'Vendor', 'Amount', 'Source', 'Found', 'Lines'], $mismatches);
        }

        return self::SUCCESS;
    }
}
