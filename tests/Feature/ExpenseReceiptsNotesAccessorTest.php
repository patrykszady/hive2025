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

    public function test_notes_ignores_billing_summary_value_on_customer_po_row(): void
    {
        $receipt = new ExpenseReceipts([
            'receipt_items' => [
                'purchase_order' => '',
                'handwritten_notes' => [],
                'raw_content' => "Customer PO Number:\t\tDiscounts:\t0.00\nOrder Number:\t0f504a20-a493-4cfe-ad5f-1590391ffd18",
            ],
        ]);

        $this->assertSame('', $receipt->notes);
    }

    public function test_notes_strips_form_field_label_and_dedupes_redundant_purchase_order(): void
    {
        // Menards receipt: the handwritten note carries the "Job # or Name :"
        // form label and the purchase_order repeats the same value. The notes
        // accessor should strip the label and drop the redundant PO → "3143".
        $receipt = new ExpenseReceipts([
            'receipt_items' => [
                'purchase_order' => '3143',
                'handwritten_notes' => ['Job # or Name : 3143'],
            ],
        ]);

        $this->assertSame('3143', $receipt->notes);
    }

    public function test_strip_po_field_label_handles_common_labels(): void
    {
        $this->assertSame('3143', ExpenseReceipts::stripPoFieldLabel('Job # or Name : 3143'));
        $this->assertSame('991', ExpenseReceipts::stripPoFieldLabel('Job Name - 991'));
        $this->assertSame('42', ExpenseReceipts::stripPoFieldLabel('Job: 42'));
        $this->assertSame('4820', ExpenseReceipts::stripPoFieldLabel('PO #: 4820'));
        $this->assertSame('Smith Kitchen', ExpenseReceipts::stripPoFieldLabel('PO/Job Name: Smith Kitchen'));

        // Plain values and prose are left untouched.
        $this->assertSame('3143', ExpenseReceipts::stripPoFieldLabel('3143'));
        $this->assertSame('stock', ExpenseReceipts::stripPoFieldLabel('stock'));
        $this->assertSame('Jobsite crew', ExpenseReceipts::stripPoFieldLabel('Jobsite crew'));
    }

    public function test_notes_drops_value_wholly_contained_in_a_longer_note(): void
    {
        // A bare purchase_order that is a substring of a richer handwritten note
        // should not appear twice.
        $receipt = new ExpenseReceipts([
            'receipt_items' => [
                'purchase_order' => 'Smith',
                'handwritten_notes' => ['Smith Kitchen Remodel'],
            ],
        ]);

        $this->assertSame('Smith Kitchen Remodel', $receipt->notes);
    }
}

