<?php

namespace App\Services\Estimates;

use App\Models\Estimate;
use App\Models\EstimateAiDraft;
use App\Models\EstimateAiRun;
use App\Models\EstimateLineItem;
use App\Models\EstimateSection;

/**
 * Discards an AI draft — every line item it added force-deleted, the
 * section's recorded inquiry/scope cleared, the draft itself marked
 * discarded (so the pattern it came from, if any, can propose again) — the
 * shared body of App\Livewire\Estimates\EstimateAIGenerator::discardDraft()
 * and the Hive Contractors app's EstimateAiController::discard().
 *
 * Nothing here touches a section locked by a signature: a draft that became
 * part of a signed contract stays in it.
 */
class EstimateAiDraftWriter
{
    public function __construct(private EstimateSectionWriter $sections) {}

    /**
     * @param  list<int>  $lineItemIds  The drafted line items to remove — the draft's own recorded ids.
     */
    public function discard(Estimate $estimate, int $sectionId, ?int $draftId, array $lineItemIds): void
    {
        $section = $estimate->estimate_sections()->find($sectionId);

        if ($section?->isLocked()) {
            return;
        }

        foreach ($lineItemIds as $id) {
            $line = $estimate->estimate_line_items()->find($id);

            if ($line !== null && ! $line->section?->isLocked()) {
                $line->forceDelete();
            }
        }

        $section?->forceFill(['ai_inquiry' => null, 'ai_scope' => null, 'ai_note' => null])->save();

        EstimateAiDraft::whereKey($draftId)->where('estimate_id', $estimate->id)->update(['status' => EstimateAiDraft::DISCARDED]);
    }

    /**
     * How much of a run can still be taken off the estimate: its drafted
     * lines that are still there (or went into a combined line that is) and
     * not in a signed section — and how many are held by a signature.
     *
     * @return array{removable: int, signed: int}
     */
    public function standing(Estimate $estimate, EstimateAiRun $run): array
    {
        $locked = $this->lockedSectionIds($estimate);
        $lines = $estimate->estimate_line_items()->get(['id', 'section_id'])->keyBy('id');
        $standing = ['removable' => 0, 'signed' => 0];

        foreach ($run->drafts as $draft) {
            foreach ((array) $draft->drafted_items as $item) {
                $line = $lines->get((int) ($item['estimate_line_item_id'] ?? 0)) ?? $lines->get((int) ($item['combined_into'] ?? 0));

                if ($line !== null) {
                    $standing[in_array((int) $line->section_id, $locked, true) ? 'signed' : 'removable']++;
                }
            }
        }

        return $standing;
    }

    /**
     * Discards a whole-estimate run: each section's draft as above, the
     * combined lines its lines went into (when a combined line holds nothing
     * but this run's work), and then the sections the run itself brought in.
     *
     * @return array{kept_combined: int}  combined lines left standing because they also carry other work
     */
    public function discardRun(Estimate $estimate, EstimateAiRun $run): array
    {
        if ((int) $run->estimate_id !== (int) $estimate->id) {
            return ['kept_combined' => 0];
        }

        $locked = $this->lockedSectionIds($estimate);
        $drafts = $run->drafts()->get()->reject(fn (EstimateAiDraft $draft) => in_array((int) $draft->section_id, $locked, true));
        $keptCombined = $this->removeCombinedLines($estimate, $drafts->all(), $locked);

        foreach ($drafts as $draft) {
            $lineItemIds = collect($draft->drafted_items ?? [])->pluck('estimate_line_item_id')->filter()->map(fn ($id) => (int) $id)->values()->all();

            $this->discard($estimate, (int) $draft->section_id, $draft->id, $lineItemIds);
        }

        $this->tidyRun($estimate, $run);

        return ['kept_combined' => $keptCombined];
    }

    /**
     * The sections a run brought in, once they hold nothing: one it created
     * is removed for good (never wanted, so not parked among the restorable
     * sections); an empty one it gave a name goes back to unnamed. A section
     * with lines in it — the draft's own, or the estimator's — stands, and so
     * does anything a signature holds.
     *
     * @param  list<int>  $exceptSectionIds  sections to leave even if empty (one about to be drafted again)
     */
    public function tidyRun(Estimate $estimate, EstimateAiRun $run, array $exceptSectionIds = []): void
    {
        if ((int) $run->estimate_id !== (int) $estimate->id) {
            return;
        }

        foreach ((array) $run->sections as $drafted) {
            $section = in_array($drafted['origin'] ?? null, ['created', 'named'], true) && ! in_array((int) ($drafted['section_id'] ?? 0), $exceptSectionIds, true)
                ? $estimate->estimate_sections()->find($drafted['section_id'] ?? null)
                : null;

            if ($section === null || $section->isLocked() || $section->estimate_line_items()->exists()) {
                continue;
            }

            if ($drafted['origin'] === 'named') {
                $section->forceFill(['name' => null, 'ai_inquiry' => null, 'ai_scope' => null, 'ai_note' => null])->save();

                continue;
            }

            // Lines of the draft the estimator had already removed the restorable way go with their section.
            EstimateLineItem::withoutEvents(fn () => $section->estimate_line_items()->onlyTrashed()->forceDelete());
            $this->sections->deleteSection($section);
            $section->forceDelete();
        }
    }

    /**
     * Drafted lines that were later combined with their likes into one line
     * elsewhere (EstimateLineItemCombiner) took their money with them. A
     * combined line made up of nothing but these drafts' lines goes with the
     * draft; one that also carries other work is left, and counted.
     *
     * @param  list<EstimateAiDraft>  $drafts
     * @param  list<int>  $locked
     */
    protected function removeCombinedLines(Estimate $estimate, array $drafts, array $locked): int
    {
        $contributed = [];
        foreach ($drafts as $draft) {
            foreach ((array) $draft->drafted_items as $item) {
                if (! empty($item['combined_into'])) {
                    $contributed[(int) $item['combined_into']] = ($contributed[(int) $item['combined_into']] ?? 0.0) + (float) ($item['combined_total'] ?? 0);
                }
            }
        }

        $kept = 0;
        foreach ($contributed as $lineId => $total) {
            $line = $estimate->estimate_line_items()->find($lineId);

            if ($line === null) {
                continue;
            }

            if (in_array((int) $line->section_id, $locked, true) || abs((float) $line->total - $total) > 0.01) {
                $kept++;

                continue;
            }

            $section = $line->section;
            $line->forceDelete();

            if ($section !== null) {
                $section->total = $section->estimate_line_items()->sum('total');
                $section->save();
            }
        }

        return $kept;
    }

    /** @return list<int> */
    protected function lockedSectionIds(Estimate $estimate): array
    {
        return $estimate->estimate_sections()->get()
            ->filter(fn (EstimateSection $section) => $section->isLocked())
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
