<?php

namespace App\Observers;

use App\Models\EstimateLineItem;
use App\Models\EstimateSection;

class EstimateLineItemObserver
{
    /**
     * Handle the EstimateLineItem "created" event.
     */
    public function created(EstimateLineItem $estimateLineItem): void
    {
        $this->adjust_section_total($estimateLineItem, 'add');
    }

    /**
     * Handle the EstimateLineItem "updated" event.
     */
    public function updated(EstimateLineItem $estimateLineItem): void
    {
        $this->adjust_section_total($estimateLineItem, 'add');
    }

    /**
     * Handle the EstimateLineItem "deleted" event.
     */
    public function deleted(EstimateLineItem $estimateLineItem): void
    {
        $this->adjust_section_total($estimateLineItem, 'subtract');
    }

    /**
     * Handle the EstimateLineItem "restored" event.
     */
    public function restored(EstimateLineItem $estimateLineItem): void
    {
        $this->adjust_section_total($estimateLineItem, 'add');
    }

    /**
     * Handle the EstimateLineItem "force deleted" event.
     */
    public function forceDeleted(EstimateLineItem $estimateLineItem): void
    {
        $this->adjust_section_total($estimateLineItem, 'subtract');
    }

    public function adjust_section_total(EstimateLineItem $estimateLineItem, $operator)
    {
        $section = EstimateSection::findOrFail($estimateLineItem->section_id);

        if ($operator == 'add') {
            if (! empty($estimateLineItem->getOriginal())) {
                $section->total -= $estimateLineItem->getOriginal()['total'];
            }
            $section->total += $estimateLineItem->total;
        } elseif ($operator == 'subtract') {
            $section->total -= $estimateLineItem->total;
        } else {

        }

        $section->save();
        // $section->refresh();

        //queue this?
        //if section belongs to bid .. adjust that bid.
        $bid = $section->bid;
        if (isset($bid)) {
            $bid_sections_sum = EstimateSection::where('bid_id', $bid->id)->sum('total');
            $bid->amount = $bid_sections_sum;
            $bid->save();

            if ($bid->amount == 0.00) {
                $bid->delete();
            }
        }
    }
}
