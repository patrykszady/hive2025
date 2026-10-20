<?php

namespace App\Services\Expenses;

use App\Models\Expense;
use App\Models\ExpenseReceipts;
use App\Models\ReceiptLineItemDesc;
use Spatie\Activitylog\Models\Activity;

/**
 * A receipt line item's full detail: price/manufacturer/SKU fields off the
 * OCR'd receipt itself, enriched with the matched product's image/URL/area
 * (App\Models\ReceiptLineItemDesc) and, for a material order, its
 * status/ETA change history (the activitylog entries ProjectMaterials
 * records). Extracted from App\Livewire\Expenses\ExpenseShow::
 * selectReceiptItem() so the Hive Contractors app's own receipt item detail
 * endpoint (Api\App\V1\ExpenseReceiptItemController) builds the exact same
 * payload the web's item-detail modal shows — ExpenseShow now delegates
 * here instead of duplicating the logic.
 */
class ReceiptLineItemDetail
{
    /**
     * Null when the receipt or the item index doesn't exist (including a
     * receipt that belongs to a different expense) — mirrors
     * ExpenseShow::selectReceiptItem()'s early `return` on a missing item.
     *
     * @return array<string, mixed>|null
     */
    public function forItem(Expense $expense, int $receiptId, int $itemIndex): ?array
    {
        // Always fetch the latest receipt row to avoid stale in-memory
        // relation data — same fallback order as the Livewire method.
        $receipt = ExpenseReceipts::query()
            ->where('expense_id', $expense->id)
            ->find($receiptId);

        if (! $receipt) {
            $receipt = $expense->orderedReceipts->firstWhere('id', $receiptId);
        }

        $items = $receipt?->receipt_items['items'] ?? [];
        $item = $items[$itemIndex] ?? null;

        if (! $item) {
            return null;
        }

        $desc = ReceiptLineItemDesc::where('expense_receipt_id', $receiptId)
            ->where('item_index', $itemIndex)
            ->first();

        if ($desc) {
            if (empty($item['image_url']) && ! empty($desc->product_image_url)) {
                $item['image_url'] = $desc->product_image_url;
            }

            if (empty($item['product_url']) && ! empty($desc->product_url)) {
                $item['product_url'] = $desc->product_url;
            }

            if (empty($item['Area']) && ! empty($desc->area)) {
                $item['Area'] = $desc->area;
            }
        }

        $item['_vendor_name'] = $expense->vendor?->business_name;
        $item['_expense_date'] = $expense->date?->format('M j, Y');

        if ($receipt?->is_material_order) {
            $item['_history'] = Activity::where('log_name', 'materials')
                ->where('subject_type', 'App\\Models\\ExpenseReceipts')
                ->where('subject_id', $receipt->id)
                ->latest()
                ->get()
                ->flatMap(function ($log) use ($itemIndex) {
                    $tz = auth()->user()?->vendor?->timezone ?? config('app.timezone');

                    return collect($log->properties['items'] ?? [])
                        ->filter(fn ($change) => ($change['item_index'] ?? null) === $itemIndex)
                        ->map(fn ($change) => array_merge($change, ['date' => $log->created_at->timezone($tz)->format('n/j/y g:iA')]));
                })
                ->values()
                ->toArray();
        }

        return $item;
    }
}
