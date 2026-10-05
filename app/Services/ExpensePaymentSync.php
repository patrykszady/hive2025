<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\ExpensePayment;
use App\Support\ReceiptTenders;
use Illuminate\Support\Facades\DB;

/**
 * Keeps an expense's payment lines (ExpensePayment) true to its receipts:
 * run whenever a receipt is saved or removed and whenever the amount
 * changes, so the lines exist before the bank matcher looks at the expense
 * (TransactionController::add_expense_to_transactions subtracts the ones
 * that never reach the bank, and matches card lines to charges).
 *
 * Lines a person entered (source "manual") are never touched. Lines that no
 * longer add up to the expense are removed rather than left to mislead the
 * matcher. A card line keeps the charge it was matched to across re-syncs.
 */
class ExpensePaymentSync
{
    /**
     * @return array{status: string, source: ?string, lines: int}
     */
    public function sync(Expense $expense): array
    {
        // Payments a person entered replace whatever the receipts said.
        if (ExpensePayment::query()->where('expense_id', $expense->id)->where('source', ExpensePayment::SOURCE_MANUAL)->exists()) {
            ExpensePayment::query()->where('expense_id', $expense->id)->where('source', '!=', ExpensePayment::SOURCE_MANUAL)->delete();

            return ['status' => 'manual', 'source' => ExpensePayment::SOURCE_MANUAL, 'lines' => 0];
        }

        $expense->loadMissing('receipts');
        $result = ReceiptTenders::forExpense($expense);

        DB::transaction(function () use ($expense, $result) {
            $existing = ExpensePayment::query()->where('expense_id', $expense->id)->get();
            $matchedCharges = $existing->whereNotNull('transaction_id')
                ->mapWithKeys(fn (ExpensePayment $line) => [$this->key($line->method, $line->last_four, (float) $line->amount) => $line->transaction_id]);

            ExpensePayment::query()->where('expense_id', $expense->id)->delete();

            if ($result['status'] !== 'matched') {
                return;
            }

            foreach ($result['lines'] as $line) {
                $key = $this->key($line['method'], $line['last_four'], (float) $line['amount']);

                ExpensePayment::create([
                    'expense_id' => $expense->id,
                    'expense_receipt_id' => $result['receipt_id'],
                    'method' => $line['method'],
                    'amount' => $line['amount'],
                    'last_four' => $line['last_four'],
                    'brand' => $line['brand'],
                    'paid_at' => $line['paid_at'] ?? null,
                    'source' => $result['source'],
                    'source_ref' => $line['source_ref'] ?? null,
                    'transaction_id' => $matchedCharges->pull($key),
                ]);
            }
        });

        return ['status' => $result['status'], 'source' => $result['source'], 'lines' => $result['status'] === 'matched' ? count($result['lines']) : 0];
    }

    /**
     * Sync by id, outside any tenant scope, for model events: never lets a
     * failure here (a deploy's moment before the table exists, a receipt the
     * parser chokes on) break the receipt save or amount change that fired it.
     */
    public function syncById(int $expenseId): void
    {
        try {
            $expense = Expense::withoutGlobalScopes()->whereNull('deleted_at')->find($expenseId);

            if ($expense) {
                $this->sync($expense);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    protected function key(string $method, ?string $lastFour, float $amount): string
    {
        return $method.'|'.($lastFour ?? '').'|'.number_format($amount, 2, '.', '');
    }
}
