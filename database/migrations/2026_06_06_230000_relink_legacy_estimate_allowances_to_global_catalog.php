<?php

use App\Models\EstimateLineItem;
use App\Models\EstimateLineItemAllowance;
use App\Models\LineItemAllowance;
use App\Services\AllowanceReconciler;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Relink legacy estimate allowances to the matching global catalog entry,
     * collapsing free-text rows (e.g. "Tile: $5/sqft") onto the canonical
     * global allowance ("Tile") with its pricing mode and per-unit amount.
     */
    public function up(): void
    {
        $reconciler = app(AllowanceReconciler::class);

        EstimateLineItem::query()
            ->whereHas('allowances')
            ->with('allowances')
            ->chunkById(200, function ($lineItems) use ($reconciler) {
                $lineItemIds = $lineItems->pluck('line_item_id')->filter()->unique();

                $globalsByLineItem = LineItemAllowance::query()
                    ->whereIn('line_item_id', $lineItemIds)
                    ->get()
                    ->groupBy('line_item_id');

                foreach ($lineItems as $lineItem) {
                    if (! $lineItem->line_item_id) {
                        continue;
                    }

                    $globals = $globalsByLineItem->get($lineItem->line_item_id, collect());

                    if ($globals->isEmpty()) {
                        continue;
                    }

                    foreach ($lineItem->allowances as $allowance) {
                        $reconciled = $reconciler->reconcile($allowance, $lineItem, $globals);

                        if (! $reconciled) {
                            continue;
                        }

                        $allowance->update([
                            'line_item_allowance_id' => $reconciled['line_item_allowance_id'],
                            'description' => $reconciled['description'],
                            'pricing_mode' => $reconciled['pricing_mode'],
                            'unit_amount' => $reconciled['unit_amount'],
                            'amount' => $reconciled['amount'],
                        ]);
                    }
                }
            });
    }

    /**
     * Reverse the migrations. This backfill is not reversible.
     */
    public function down(): void
    {
        // No-op: the original legacy descriptions cannot be reconstructed.
    }
};
