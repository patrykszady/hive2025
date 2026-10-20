<?php

namespace App\Services\Sheets;

use App\Models\Category;
use App\Models\Expense;

/**
 * Assigning a category to an expense the P&L report flagged as
 * uncategorized — extracted from App\Livewire\Sheets\SheetShow::
 * categorizeExpense() so the Hive Contractors app's SheetController writes
 * identically.
 */
class UncategorizedExpenseAssigner
{
    public function assign(Expense $expense, Category $category): void
    {
        $expense->update(['category_id' => $category->id]);

        // Also set the vendor's default category if it doesn't have one.
        if ($expense->vendor && ! $expense->vendor->category_id) {
            $expense->vendor->update(['category_id' => $category->id]);
        }
    }
}
