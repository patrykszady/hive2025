<?php

namespace Tests\Feature;

use App\Http\Controllers\ExpenseAutoMatchController;
use App\Models\Expense;
use App\Models\ExpenseReceipts;
use Illuminate\Database\Eloquent\Collection;
use Tests\TestCase;

class ExpenseAutoMatchControllerExtractPurchaseOrderCandidatesTest extends TestCase
{
    public function test_returns_purchase_order_then_handwritten_notes_when_both_exist(): void
    {
        $controller = new class extends ExpenseAutoMatchController
        {
            /**
             * @return array<int, string>
             */
            public function extractCandidates(Expense $expense): array
            {
                return $this->extractPurchaseOrderCandidates($expense);
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

        $this->assertSame(['PO 123', '17 MARCELA'], $controller->extractCandidates($expense));
    }

    public function test_falls_back_to_handwritten_notes_when_purchase_order_is_empty(): void
    {
        $controller = new class extends ExpenseAutoMatchController
        {
            /**
             * @return array<int, string>
             */
            public function extractCandidates(Expense $expense): array
            {
                return $this->extractPurchaseOrderCandidates($expense);
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

        $this->assertSame(['17 MARCELA'], $controller->extractCandidates($expense));
    }

    public function test_deduplicates_candidates_case_insensitively(): void
    {
        $controller = new class extends ExpenseAutoMatchController
        {
            /**
             * @return array<int, string>
             */
            public function extractCandidates(Expense $expense): array
            {
                return $this->extractPurchaseOrderCandidates($expense);
            }
        };

        $expense = new Expense();

        $receipt = new ExpenseReceipts([
            'receipt_items' => [
                'purchase_order' => ['PO 123', 'po 123'],
                'handwritten_notes' => ['PO 123'],
            ],
        ]);

        $expense->setRelation('receipts', new Collection([$receipt]));

        $this->assertSame(['PO 123'], $controller->extractCandidates($expense));
    }

    public function test_prioritizes_purchase_orders_across_all_receipts_before_any_handwritten_notes(): void
    {
        $controller = new class extends ExpenseAutoMatchController
        {
            /**
             * @return array<int, string>
             */
            public function extractCandidates(Expense $expense): array
            {
                return $this->extractPurchaseOrderCandidates($expense);
            }
        };

        $expense = new Expense();

        $newestReceipt = new ExpenseReceipts([
            'receipt_items' => [
                'handwritten_notes' => ['HN 1'],
            ],
            'created_at' => now(),
        ]);

        $olderReceipt = new ExpenseReceipts([
            'receipt_items' => [
                'purchase_order' => 'PO 999',
            ],
            'created_at' => now()->subDay(),
        ]);

        // Newest first to match how receipts are normally loaded.
        $expense->setRelation('receipts', new Collection([$newestReceipt, $olderReceipt]));

        $this->assertSame(['PO 999', 'HN 1'], $controller->extractCandidates($expense));
    }
}
