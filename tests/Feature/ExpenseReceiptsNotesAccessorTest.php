<?php

namespace Tests\Feature;

use App\Models\ExpenseReceipts;
use Tests\TestCase;

class ExpenseReceiptsNotesAccessorTest extends TestCase
{
    public function test_notes_falls_back_to_pro_jobname_from_raw_content_when_purchase_order_is_empty(): void
    {
        $receipt = new ExpenseReceipts([
            'receipt_items' => [
                'purchase_order' => '',
                'handwritten_notes' => [],
                'raw_content' => "PRO Name:\nGregory Szady\n\nPRO JobName:\n\n17\n\nPoints Balance:\n24,554",
            ],
        ]);

        $this->assertSame('17', $receipt->notes);
    }

    public function test_notes_prefers_existing_purchase_order_over_raw_content_fallback(): void
    {
        $receipt = new ExpenseReceipts([
            'receipt_items' => [
                'purchase_order' => 'PO 4455',
                'handwritten_notes' => [],
                'raw_content' => "PRO JobName:\n\n17",
            ],
        ]);

        $this->assertSame('PO 4455', $receipt->notes);
    }
}
