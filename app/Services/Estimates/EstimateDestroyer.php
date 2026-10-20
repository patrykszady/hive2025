<?php

namespace App\Services\Estimates;

use App\Models\Estimate;

/**
 * Permanently deletes an already-disabled estimate and everything that
 * exists only for it (its line items' allowances, its line items, its
 * sections) — the shared body of the trashed branch of
 * App\Livewire\Estimates\EstimatesIndex::removeEstimate() and the Hive
 * Contractors app's EstimateController::forceDelete(), so "Delete" on a
 * disabled estimate removes exactly the same rows on both surfaces.
 *
 * Callers must have already confirmed $estimate->trashed() and the
 * 'forceDelete' ability (EstimatePolicy) — this class only performs the
 * deletion itself.
 */
class EstimateDestroyer
{
    public function forceDelete(Estimate $estimate): void
    {
        $estimate->estimate_line_items()->each(function ($lineItem) {
            $lineItem->allowances()->delete();
            $lineItem->forceDelete();
        });
        $estimate->estimate_sections()->forceDelete();
        $estimate->forceDelete();
    }
}
