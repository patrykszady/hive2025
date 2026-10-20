<?php

namespace App\Services\Expenses;

use App\Models\Expense;

/**
 * AppExpenseDirectory::create()/update()'s return value — the saved
 * Expense plus, when a receipt photo was attached but OCR failed, the same
 * warning the web shows instead of silently dropping it (mirrors
 * ExpenseForm::store()/update()'s `session()->flash('warning', ...)` at
 * ExpenseForm.php:453-459,604-611,636-655 — the expense itself still saved,
 * only the receipt scan didn't attach).
 */
final class ExpenseSaveResult
{
    public function __construct(
        public readonly Expense $expense,
        public readonly ?string $receiptWarning = null,
    ) {
    }
}
