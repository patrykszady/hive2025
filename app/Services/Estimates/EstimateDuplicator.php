<?php

namespace App\Services\Estimates;

use App\Models\Estimate;
use App\Models\EstimateLineItem;
use App\Models\EstimateSection;
use App\Models\Project;

/**
 * Duplicates a whole estimate, or a single section onto another estimate —
 * the shared body of App\Livewire\Estimates\EstimateDuplicate::save()/
 * save_section() and the Hive Contractors app's EstimateController, so both
 * surfaces copy sections and line items identically.
 */
class EstimateDuplicator
{
    /**
     * Duplicate every section and line item of $source onto a brand new
     * estimate for $project.
     */
    public function duplicateEstimate(Estimate $source, Project $project, string $costMode = 'exact', bool $includeAllowances = true): Estimate
    {
        $newEstimate = Estimate::create([
            'project_id' => $project->id,
            'belongs_to_vendor_id' => $source->belongs_to_vendor_id,
        ]);

        foreach ($source->estimate_sections as $section) {
            $this->copySection($section, $newEstimate, $costMode, $includeAllowances);
        }

        return $newEstimate;
    }

    /**
     * Duplicate a single section onto $targetEstimate.
     */
    public function duplicateSection(EstimateSection $section, Estimate $targetEstimate, string $costMode = 'exact', bool $includeAllowances = true): EstimateSection
    {
        $newSection = $section->replicate();
        $newSection->estimate_id = $targetEstimate->id;
        $newSection->bid_id = null;
        $newSection->name = $section->name.' - Copy from Estimate '.$section->estimate_id;

        // Reset total to 0 so the observer can calculate it correctly.
        $newSection->total = 0;
        $newSection->save();

        $this->copyLineItems($section, $newSection, $costMode, $includeAllowances);

        return $newSection;
    }

    /**
     * Copy a section (as part of a whole-estimate duplicate) onto
     * $newEstimate, replicating the section row itself (unlike
     * duplicateSection, which builds a fresh "Copy from Estimate ..." name).
     */
    protected function copySection(EstimateSection $section, Estimate $newEstimate, string $costMode, bool $includeAllowances): EstimateSection
    {
        $newSection = $section->replicate();
        $newSection->estimate_id = $newEstimate->id;
        $newSection->bid_id = null;
        $newSection->total = 0;
        $newSection->save();

        $this->copyLineItems($section, $newSection, $costMode, $includeAllowances);

        return $newSection;
    }

    protected function copyLineItems(EstimateSection $section, EstimateSection $newSection, string $costMode, bool $includeAllowances): void
    {
        foreach ($section->estimate_line_items as $estimateLineItem) {
            $newLineItem = new EstimateLineItem();
            $newLineItem->estimate_id = $newSection->estimate_id;
            $newLineItem->section_id = $newSection->id;
            $newLineItem->quantity = $estimateLineItem->quantity;

            $lineItemTemplate = $estimateLineItem->line_item;

            $newLineItem->line_item_id = $lineItemTemplate->id;
            $newLineItem->name = $estimateLineItem->name ?? $lineItemTemplate->name;
            $newLineItem->category = $estimateLineItem->category ?? $lineItemTemplate->category;
            $newLineItem->sub_category = $estimateLineItem->sub_category ?? $lineItemTemplate->sub_category;
            $newLineItem->unit_type = $estimateLineItem->unit_type ?? $lineItemTemplate->unit_type;
            $newLineItem->desc = $estimateLineItem->desc ?? $lineItemTemplate->desc;
            $newLineItem->notes = $estimateLineItem->notes ?? $lineItemTemplate->notes;

            if ($costMode === 'exact') {
                $newLineItem->cost = $estimateLineItem->cost;
                $newLineItem->total = $estimateLineItem->cost * $estimateLineItem->quantity;
            } else {
                $newLineItem->cost = $lineItemTemplate->cost;
                $newLineItem->total = $lineItemTemplate->cost * $estimateLineItem->quantity;
            }

            $newLineItem->save(); // Observer will add this to the section total.

            if ($includeAllowances) {
                foreach ($estimateLineItem->allowances as $allowance) {
                    $newLineItem->allowances()->create([
                        'description' => $allowance->description,
                        'amount' => $allowance->amount,
                    ]);
                }
            }
        }
    }
}
