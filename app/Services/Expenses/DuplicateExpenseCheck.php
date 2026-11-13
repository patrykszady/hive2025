<?php

namespace App\Services\Expenses;

/**
 * The duplicate check, made in the same step that creates the second record
 * of a purchase (Patryk, 2026-11-13: "when creating expenses/29068 we should
 * have found expenses/29031 during the process and added the receipt and
 * transaction to 29068", "no matter how the expense is created").
 *
 * The models call this themselves, at once and in the same request or job:
 * a receipt landing on an expense (ExpenseReceipts), a bank charge linked
 * to an expense (Transaction), an expense's amount or date changing
 * (Expense). So the emailed scan, an upload, quick capture, the app, a
 * hand-made expense and every bank matcher reach the one rule
 * (ReceiptChargePairer) without each having to remember to ask.
 *
 * The expense it is told about is the one that stays: its counterpart is
 * folded into it. Whatever is in the middle of creating or editing that
 * expense therefore still holds a live expense afterwards, with the receipt
 * and the bank charge both on it.
 *
 * A singleton, because the pairing itself moves receipts and charges and
 * would otherwise call back into here for every row it touches.
 */
class DuplicateExpenseCheck
{
    private bool $running = false;

    public function expenseChanged(int $expenseId): void
    {
        if ($this->running || $expenseId <= 0) {
            return;
        }

        $this->running = true;

        try {
            rescue(fn () => app(ReceiptChargePairer::class)->pairExpense($expenseId, keep: true));
        } finally {
            $this->running = false;
        }
    }
}
