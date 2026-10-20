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
 * App\Livewire\Projects\ProjectMaterials shows them (read-only here — the
 * OCR receipt upload/vendor-match flow that component also drives is
 * web-only). Admin-only, same as the web's viewMaterials gate.
 */
class AppProjectMaterialsReader
{
    /**
     * @return list<array{expense_id: int, vendor_name: ?string, order_number: ?string, date: ?string, availability: string, items: list<array{name: ?string, status: ?string, eta: ?string, area: ?string, image_url: ?string}>}>
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

        return $expenses->map(function (Expense $expense) use ($descsByKey) {
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
        })->filter()->values()->all();
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
