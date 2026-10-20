<?php

namespace App\Http\Resources\Api\App;

use App\Models\Receipt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The app's Receipt Patterns list row and edit form — App\Livewire\
 * Receipts\ReceiptsIndex's row/modal shape. The email receipt-PARSING
 * pattern (not an expense's own uploaded receipt image/PDF).
 *
 * @mixin Receipt
 */
class ReceiptPatternResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $subjects = $this->from_subject ?? [];
        if (! is_array($subjects)) {
            $subjects = [$subjects];
        }

        return [
            'id' => $this->id,
            'vendor' => $this->vendor ? [
                'id' => $this->vendor->id,
                'business_name' => $this->vendor->business_name,
            ] : null,
            'from_address' => $this->from_address,
            'from_subjects' => array_values($subjects),
            'receipt_type' => $this->receipt_type !== null ? (int) $this->receipt_type : null,
            'options' => is_array($this->options) ? $this->options : [],
        ];
    }
}
