<?php

namespace App\Services\Projects;

use App\Models\Expense;
use App\Models\Project;
use App\Models\ReceiptLineItemDesc;
use App\Scopes\ExpenseScope;
use App\Support\MaterialOrderStatus;
use Carbon\Carbon;

/**
 * The project's material orders exactly as
 * App\Livewire\Projects\ProjectMaterials shows them — listFor() for the
 * whole card, one() for the single order the app's upload screen shows
 * right after App\Services\Projects\ProjectMaterialsUploader creates it.
 * Admin-only, same as the web's viewMaterials gate.
 */
class AppProjectMaterialsReader
{
    /**
     * @return list<array{expense_id: int, vendor_name: ?string, order_number: ?string, date: ?string, availability: string, items: list<array{name: ?string, status: ?string, eta: ?string, area: ?string, image_url: ?string, receipt_id: int, item_index: int}>}>
     */
    public function listFor(Project $project): array
    {
        $expenses = Expense::withoutGlobalScope(ExpenseScope::class)
            ->where('project_id', $project->id)
            ->with(['vendor', 'orderedReceipts'])
            ->whereHas('receipts', fn ($query) => $query->where('is_material_order', true))
            ->latest('date')
            ->get();

        $receiptIds = $expenses
            ->map(fn (Expense $expense) => $expense->orderedReceipts->first(fn ($r) => $r->is_material_order)?->id)
            ->filter()
            ->unique()
            ->values();

        $descsByKey = $receiptIds->isNotEmpty()
            ? ReceiptLineItemDesc::whereIn('expense_receipt_id', $receiptIds)
                ->get()
                ->keyBy(fn ($d) => $d->expense_receipt_id.'|'.$d->item_index)
            : collect();

        return $expenses->map(fn (Expense $expense) => $this->transform($expense, $descsByKey))->filter()->values()->all();
    }

    /**
     * The single order the app's upload screen shows right after a receipt
     * is OCR'd — same shape as a `listFor()` row, for the one expense just
     * created by App\Services\Projects\ProjectMaterialsUploader.
     *
     * @return array{expense_id: int, vendor_name: ?string, order_number: ?string, date: ?string, availability: string, items: list<array{name: ?string, status: ?string, eta: ?string, area: ?string, image_url: ?string, receipt_id: int, item_index: int}>}|null
     */
    public function one(Expense $expense): ?array
    {
        $expense->loadMissing(['vendor', 'orderedReceipts']);

        $receipt = $expense->orderedReceipts->first(fn ($r) => $r->is_material_order);

        $descsByKey = $receipt
            ? ReceiptLineItemDesc::where('expense_receipt_id', $receipt->id)->get()->keyBy(fn ($d) => $d->expense_receipt_id.'|'.$d->item_index)
            : collect();

        return $this->transform($expense, $descsByKey);
    }

    /**
     * @param  \Illuminate\Support\Collection<string, ReceiptLineItemDesc>  $descsByKey
     * @return array{expense_id: int, vendor_name: ?string, order_number: ?string, date: ?string, availability: string, items: list<array{name: ?string, status: ?string, eta: ?string, area: ?string, image_url: ?string, receipt_id: int, item_index: int}>}|null
     */
    private function transform(Expense $expense, $descsByKey): ?array
    {
        $receipt = $expense->orderedReceipts->first(fn ($r) => $r->is_material_order);

        if (! $receipt) {
            return null;
        }

        $rawItems = $receipt->receipt_items['items'] ?? [];
        $availableCount = 0;
        $unavailableCount = 0;

        $items = collect($rawItems)->values()->map(function (array $item, int $index) use ($receipt, $descsByKey, &$availableCount, &$unavailableCount) {
            $desc = $descsByKey->get($receipt->id.'|'.$index);
            $isAvailable = $this->isItemAvailable($item);
            $isAvailable ? $availableCount++ : $unavailableCount++;

            return [
                'name' => $item['name'] ?? $item['description'] ?? null,
                'status' => MaterialOrderStatus::normalize($item['Status'] ?? null),
                'eta' => $item['ETA'] ?? null,
                'area' => $item['Area'] ?? $desc?->area,
                'image_url' => $item['image_url'] ?? $desc?->product_image_url,
                // Lets the app ask for this item's full detail (price history,
                // product image) via GET expenses/{expense}/receipts/{receipt}/
                // items/{index} — mirrors ProjectMaterials::selectItem($expenseId, $itemIndex).
                'receipt_id' => $receipt->id,
                'item_index' => $index,
            ];
        })->values()->all();

        $availability = match (true) {
            $unavailableCount === 0 => 'green',
            $availableCount === 0 => 'red',
            default => 'amber',
        };

        return [
            'expense_id' => $expense->id,
            'vendor_name' => $expense->vendor?->business_name,
            'order_number' => $receipt->receipt_items['invoice_number'] ?? $expense->invoice,
            'date' => $expense->date?->toDateString(),
            'availability' => $availability,
            'items' => $items,
        ];
    }

    /** Mirrors ProjectMaterials::isItemAvailable(). */
    private function isItemAvailable(array $item): bool
    {
        $status = MaterialOrderStatus::normalize($item['Status'] ?? null);

        if ($status === 'cancelled') {
            return false;
        }

        if (MaterialOrderStatus::isResolved($status)) {
            return true;
        }

        $rawDate = $item['ETA'] ?? null;

        if (empty($rawDate)) {
            return ! in_array($status, ['back order', 'open'], true);
        }

        try {
            $vendorTz = auth()->user()?->vendor?->timezone ?? config('app.timezone');

            return ! Carbon::parse($rawDate)->startOfDay()->gt(now($vendorTz)->startOfDay());
        } catch (\Throwable) {
            return true;
        }
    }
}
