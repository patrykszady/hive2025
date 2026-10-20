<?php

namespace App\Services\Estimates;

use App\Models\Estimate;
use App\Models\EstimateAiDraft;

/**
 * Discards an AI draft — every line item it added force-deleted, the
 * section's recorded inquiry/scope cleared, the draft itself marked
 * discarded (so the pattern it came from, if any, can propose again) — the
 * shared body of App\Livewire\Estimates\EstimateAIGenerator::discardDraft()
 * and the Hive Contractors app's EstimateAiController::discard().
 */
class EstimateAiDraftWriter
{
    /**
     * @param  list<int>  $lineItemIds  The drafted line items to remove — the web passes its live (post-edit) row list; the app passes the draft's own recorded ids.
     */
    public function discard(Estimate $estimate, int $sectionId, ?int $draftId, array $lineItemIds): void
    {
        foreach ($lineItemIds as $id) {
            $estimate->estimate_line_items()->find($id)?->forceDelete();
        }

        $estimate->estimate_sections()->find($sectionId)?->forceFill(['ai_inquiry' => null, 'ai_scope' => null])->save();

        EstimateAiDraft::whereKey($draftId)->where('estimate_id', $estimate->id)->update(['status' => EstimateAiDraft::DISCARDED]);
    }
}
