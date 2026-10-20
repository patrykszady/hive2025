<?php

namespace App\Services\Estimates;

use App\Models\Bid;
use App\Models\EmailTemplate;
use App\Models\Estimate;
use Illuminate\Support\Collection;

/**
 * The contract-settings slice of App\Livewire\Estimates\EstimateAccept's
 * "Settings" modal save() — required contract signer selection, contract
 * template choice, and manually reassigning which bid (original contract
 * vs. a specific change order) each section belongs to. Payments/dates are
 * App\Services\Estimates\EstimatePaymentScheduler's job; this is the other
 * half, shared by EstimateAccept and the Hive Contractors app's
 * EstimateContractSettingsController so both write the exact same bid
 * amounts/assignments and options.
 *
 * EstimateAccept::save() keeps iterating its own Livewire $this->bids/
 * $this->sections arrays (bid_index is a UI-only position in that
 * collection) but converts bid_index to the bid's real id before calling
 * save() here — see that method. Operating on ids rather than positions is
 * what lets a discrete HTTP request reach the identical end state without
 * reconstructing the Livewire page's in-memory array.
 */
class EstimateContractSettingsWriter
{
    /**
     * @return Collection<int, EmailTemplate>
     */
    public function contractTemplates(Estimate $estimate): Collection
    {
        return EmailTemplate::withoutGlobalScopes()
            ->where('vendor_id', $estimate->belongs_to_vendor_id)
            ->where('type', 'contract')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, \App\Models\User>
     */
    public function vendorUsers(Estimate $estimate): Collection
    {
        return $estimate->vendor?->users()
            ->wherePivot('role_id', 1)
            ->wherePivot('is_employed', 1)
            ->get() ?? collect();
    }

    /**
     * This estimate's project+vendor bids, Original (lowest type) first —
     * mirrors the query EstimateAccept::mount()/refreshEstimateData() build
     * $this->bids from.
     *
     * @return Collection<int, Bid>
     */
    public function bids(Estimate $estimate): Collection
    {
        return $estimate->project->bids()
            ->vendorBids($estimate->belongs_to_vendor_id)
            ->with('estimate_sections')
            ->orderBy('type')
            ->get();
    }

    /**
     * Auto-scaffolds the "Original Bid" exactly like
     * EstimateAccept::mount()/refreshEstimateData() do whenever the project
     * has none yet (only ever a write someone who can edit the estimate
     * triggers — see the $canEdit guard both callers already have via
     * auth()->user()?->can('update', $estimate)).
     */
    public function ensureOriginalBid(Estimate $estimate, Collection $bids): Collection
    {
        if ($bids->isNotEmpty()) {
            return $bids;
        }

        $bid = Bid::create([
            'amount' => 0.00,
            'type' => 1,
            'project_id' => $estimate->project_id,
            'vendor_id' => $estimate->belongs_to_vendor_id,
        ]);

        return collect([$bid]);
    }

    /**
     * A new change-order bid — mirrors EstimateAccept::newEstimateBid():
     * type is one past the current highest, amount starts at 0.
     */
    public function addBid(Estimate $estimate): Bid
    {
        $maxType = (int) ($estimate->project->bids()->vendorBids($estimate->belongs_to_vendor_id)->max('type') ?? 0);

        return Bid::create([
            'amount' => 0.00,
            'type' => $maxType + 1,
            'project_id' => $estimate->project_id,
            'vendor_id' => $estimate->belongs_to_vendor_id,
        ]);
    }

    /**
     * Persists required signer ids, contract template ids, and the
     * section -> bid assignment — the exact end state
     * EstimateAccept::save()'s foreach($this->bids as $bid_index => $bid)
     * loop produces: every bid with no section assigned to it this round is
     * deleted (the lowest-type/"Original" bid is kept and zeroed instead),
     * every bid WITH sections gets its amount recomputed and each of those
     * sections' bid_id set to it.
     *
     * @param  array<int>  $requiredVendorSignerIds
     * @param  array<int>  $contractTemplateIds
     * @param  array<int, int>  $sectionBidIds  section id => bid id (a
     *   section not listed, or pointed at a bid id that isn't one of this
     *   estimate's project bids, falls back to the Original bid — the same
     *   default EstimateAccept::mount() gives a section with no bid_id yet)
     */
    public function save(Estimate $estimate, array $requiredVendorSignerIds, array $contractTemplateIds, array $sectionBidIds): void
    {
        $bids = $this->ensureOriginalBid($estimate, $this->bids($estimate));
        $originalBid = $bids->first();
        $validBidIds = $bids->pluck('id')->all();

        $sections = $estimate->estimate_sections;

        $assignments = $sections->mapWithKeys(function ($section) use ($sectionBidIds, $validBidIds, $originalBid) {
            $requested = $sectionBidIds[$section->id] ?? null;
            $bidId = ($requested !== null && in_array((int) $requested, $validBidIds, true))
                ? (int) $requested
                : $originalBid->id;

            return [$section->id => $bidId];
        });

        foreach ($bids as $bid) {
            $assignedSectionIds = $assignments->filter(fn ($bidId) => $bidId === $bid->id)->keys();
            $assignedSections = $sections->whereIn('id', $assignedSectionIds);

            if ($assignedSections->isEmpty()) {
                if ($bid->id !== $originalBid->id) {
                    $bid->delete();
                } else {
                    $bid->amount = 0.00;
                    $bid->save();
                }

                continue;
            }

            $bid->amount = $assignedSections->sum('total');
            $bid->save();

            foreach ($assignedSections as $section) {
                $section->bid_id = $bid->id;
                $section->save();
            }
        }

        $options = $estimate->options ?? [];
        $options['required_vendor_signer_ids'] = array_map('intval', $requiredVendorSignerIds);
        $options['contract_template_ids'] = array_map('intval', $contractTemplateIds);
        $estimate->options = $options;
        $estimate->save();
    }
}
