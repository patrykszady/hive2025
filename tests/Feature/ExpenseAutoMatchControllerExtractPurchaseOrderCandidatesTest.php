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

    public function test_extracts_ship_to_street_address_from_raw_content(): void
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

        // Mimics a Studio 41 / Kohler Store receipt where SHIP TO and SOLD TO contain the
        // customer delivery address, which should match to the job-site project address.
        $rawContent = implode("\n", [
            'SOLD TO:',
            'LOU & RICK FRIEDMAN',
            '239 PERTH RD',
            'CARY, IL 60013',
            '',
            'SHIP TO:',
            'LOU & RICK FRIEDMAN',
            '239 PERTH RD',
            'CARY, IL 60013',
        ]);

        $receipt = new ExpenseReceipts([
            'receipt_items' => [
                'purchase_order'    => '',
                'handwritten_notes' => [],
                'raw_content'       => $rawContent,
            ],
        ]);

        $expense->setRelation('receipts', new Collection([$receipt]));

        // The street address should be extracted once (deduplicated) even though it appears
        // under both SOLD TO and SHIP TO.
        $this->assertSame(['239 PERTH RD'], $controller->extractCandidates($expense));
    }

    public function test_extracts_ship_to_address_when_blank_lines_precede_name(): void
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

        $rawContent = "SHIP TO:\n\nJANE DOE\n17 MAIN ST\nSPRINGFIELD IL 62701\n";

        $receipt = new ExpenseReceipts([
            'receipt_items' => [
                'purchase_order'    => '',
                'handwritten_notes' => [],
                'raw_content'       => $rawContent,
            ],
        ]);

        $expense->setRelation('receipts', new Collection([$receipt]));

        $this->assertSame(['17 MAIN ST'], $controller->extractCandidates($expense));
    }
}
