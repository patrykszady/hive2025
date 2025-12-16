<?php

namespace Tests\Feature;

use App\Http\Controllers\ExpenseAutoMatchController;
use App\Models\Expense;
use App\Models\ExpenseReceipts;
use Illuminate\Database\Eloquent\Collection;
use Tests\TestCase;

class ExpenseAutoMatchControllerExtractPurchaseOrderTest extends TestCase
{
    public function test_extracts_purchase_order_from_handwritten_notes_when_purchase_order_is_empty(): void
    {
        $controller = new class extends ExpenseAutoMatchController
        {
            public function extract(Expense $expense): ?string
            {
                return $this->extractPurchaseOrder($expense);
            }
        };

        $expense = new Expense();

        $receipt = new ExpenseReceipts([
            'receipt_items' => [
                'purchase_order' => '',
                'handwritten_notes' => ['17 MARCELA'],
            ],
        ]);

        $expense->setRelation('receipts', new Collection([$receipt]));

        $this->assertSame('17 MARCELA', $controller->extract($expense));
    }

    public function test_prefers_purchase_order_over_handwritten_notes_when_both_exist(): void
    {
        $controller = new class extends ExpenseAutoMatchController
        {
            public function extract(Expense $expense): ?string
            {
                return $this->extractPurchaseOrder($expense);
            }
        };

        $expense = new Expense();

        $receipt = new ExpenseReceipts([
            'receipt_items' => [
                'purchase_order' => 'PO 123',
                'handwritten_notes' => ['17 MARCELA'],
            ],
        ]);

        $expense->setRelation('receipts', new Collection([$receipt]));

        $this->assertSame('PO 123', $controller->extract($expense));
    }
}
