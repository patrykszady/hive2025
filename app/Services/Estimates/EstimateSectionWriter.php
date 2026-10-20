<?php

namespace App\Services\Estimates;

use App\Models\Bid;
use App\Models\Estimate;
use App\Models\EstimateLineItem;
use App\Models\EstimateSection;

/**
 * Adds, renames, disables and restores an estimate section, including the
 * change-order bid bookkeeping — the shared body of
 * App\Livewire\Estimates\EstimateShow's section actions and the Hive
 * Contractors app's EstimateSectionController, so both surfaces keep section
 * totals and bid rollups identical.
 */
class EstimateSectionWriter
{
    public function addSection(Estimate $estimate, ?string $name = null): EstimateSection
    {
        $currentMaxOrder = EstimateSection::query()
            ->where('estimate_id', $estimate->id)
            ->where('order', '<', 999999)
            ->max('order');

        $nextOrder = is_null($currentMaxOrder) ? 0 : $currentMaxOrder + 1;

        $section = EstimateSection::create([
            'estimate_id' => $estimate->id,
            'order' => $nextOrder,
            'name' => $name,
            'total' => 0.00,
            'deleted_at' => null,
        ]);

        $this->maybeCreateChangeOrderBid($section);

        return $section;
    }

    public function renameSection(EstimateSection $section, ?string $name): EstimateSection
    {
        $section->name = $name;
        $section->save();

        return $section;
    }

    public function deleteSection(EstimateSection $section): void
    {
        // Push deleted sections to the end so restores can be reinserted cleanly.
        $section->order = 999999;
        $section->save();

        // Disable the section and its line items, but don't mutate stored totals.
        EstimateLineItem::withoutEvents(function () use ($section) {
            $section->estimate_line_items()->delete();
        });

        $section->delete();

        $this->rollUpBid($section);
    }

    public function restoreSection(EstimateSection $section): EstimateSection
    {
        $section->restore();

        $currentMaxOrder = EstimateSection::query()
            ->where('estimate_id', $section->estimate_id)
            ->where('order', '<', 999999)
            ->max('order');

        $section->order = is_null($currentMaxOrder) ? 0 : $currentMaxOrder + 1;
        $section->save();

        // Restore the line items without triggering observers that mutate section totals.
        EstimateLineItem::withoutEvents(function () use ($section) {
            $section->estimate_line_items()->onlyTrashed()->restore();
        });

        $section->total = $section->estimate_line_items()->sum('total');
        $section->save();

        $bid = $section->bid;

        // If the section had a bid_id but the bid was deleted, create a new change order bid
        if (! $bid && $section->bid_id) {
            $section->bid_id = null;
            $section->save();
            // Create a new change order bid for this restored section
            $this->maybeCreateChangeOrderBid($section);
            // Refresh section to get updated bid_id
            $section->refresh();
            $bid = $section->bid;
        }

        if ($bid) {
            $bid->amount = EstimateSection::where('bid_id', $bid->id)->sum('total');
            $bid->save();

            if ((float) $bid->amount === 0.0) {
                $bid->delete();
            }
        }

        return $section;
    }

    /**
     * Recompute and persist the section's bid total, deleting the bid once it
     * no longer has any amount to show.
     */
    protected function rollUpBid(EstimateSection $section): void
    {
        $bid = $section->bid;

        if (! $bid) {
            return;
        }

        $bid->amount = EstimateSection::where('bid_id', $bid->id)->sum('total');
        $bid->save();

        if ((float) $bid->amount === 0.0) {
            $bid->delete();
        }
    }

    /**
     * A section is only a change order once the contract exists — the base
     * (type 1) bid is created when the estimate is accepted/signed. Before
     * that, new sections are simply part of the still-unsigned estimate
     * (otherwise the finances double-count: the section sums into the
     * estimate fallback AND its CO bid).
     */
    public function maybeCreateChangeOrderBid(EstimateSection $section): void
    {
        $estimate = $section->estimate;
        $project = $estimate?->project;
        $projectId = $estimate?->project_id;
        $vendorId = $estimate?->belongs_to_vendor_id;

        if (! $projectId || ! $vendorId || ! $project) {
            return;
        }

        // Ensure latestStatus is loaded
        $project->loadMissing('latestStatus');

        // Only auto-create change order bids for Active or Service Call projects
        $statusTitle = $project->latestStatus?->title;
        $activeStatuses = ['Active', 'Service Call'];

        if (! in_array($statusTitle, $activeStatuses, true)) {
            return;
        }

        $hasBaseBid = Bid::where('project_id', $projectId)
            ->where('vendor_id', $vendorId)
            ->where('type', 1)
            ->exists();

        if (! $hasBaseBid) {
            return;
        }

        $nextType = (int) (Bid::where('project_id', $projectId)
            ->where('vendor_id', $vendorId)
            ->max('type') ?? 1) + 1;

        $bid = Bid::create([
            'amount' => 0.00,
            'type' => $nextType,
            'project_id' => $projectId,
            'vendor_id' => $vendorId,
        ]);

        $section->bid_id = $bid->id;
        $section->save();
    }
}
