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

    public function test_notes_skips_street_address_followed_by_city_state_zip_line(): void
    {
        $receipt = new ExpenseReceipts([
            'receipt_items' => [
                'purchase_order' => '0',
                'handwritten_notes' => [],
                'raw_content' => "670 S RAND ROAD\nLAKE ZURICH, IL 60047 (847)726-0707\n\n1952 00002 58533\n04/28/26 09:24 AM",
            ],
        ]);

        $this->assertSame('', $receipt->notes);
    }

    public function test_notes_does_not_fall_back_to_arbitrary_leading_line_in_raw_content(): void
    {
        // The leading-line fallback was removed: it produced false positives
        // like "CASHIER MELANIE" or "350 E. KENSINGTON". Handwritten content
        // must come from the structured handwritten_notes array (re-OCR if missing).
        $receipt = new ExpenseReceipts([
            'receipt_items' => [
                'purchase_order' => '',
                'handwritten_notes' => [],
                'raw_content' => "17 MARCELA\n\nSome receipt body\nCASHIER *",
            ],
        ]);

        $this->assertSame('', $receipt->notes);
    }
}
