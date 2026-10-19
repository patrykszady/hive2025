<?php

namespace App\Services\Bids;

use App\Models\Project;
use App\Models\Vendor;

/**
 * The project bids list exactly as App\Livewire\Bids\BidCreate::addBids()
 * builds it for its modal — same ordering, same zeroed-out display amount,
 * same has_estimate_sections lock, same default "Original Bid" placeholder
 * row when nothing has been bid yet — so the Hive Contractors app's bids
 * screen shows identical rows.
 */
class AppBidDirectory
{
    /**
     * @return list<array{id: ?int, amount: ?float, type: int, name: string, project_id: int, vendor_id: int, has_estimate_sections: bool}>
     */
    public function listFor(Project $project, Vendor $vendor, int $viewingVendorId): array
    {
        $bids = $project->bids()
            ->vendorBids($vendor->id)
            ->with('estimate_sections')
            ->orderBy('type')
            ->get()
            ->values()
            ->map(function ($bid, int $key) use ($viewingVendorId) {
                $belongsToViewingVendor = (int) $bid->vendor_id === $viewingVendorId;
                $hasEstimateSections = $bid->estimate_sections->where('bid_id', $bid->id)->isNotEmpty();

                return [
                    'id' => $bid->id,
                    'amount' => (float) $bid->amount === 0.0 ? null : (float) $bid->amount,
                    'type' => (int) $bid->type,
                    'name' => $key === 0 ? 'Original Bid' : 'Change Order '.$key,
                    'project_id' => $project->id,
                    'vendor_id' => $vendor->id,
                    'has_estimate_sections' => $belongsToViewingVendor && $hasEstimateSections,
                ];
            })
            ->all();

        if ($bids === []) {
            return [[
                'id' => null,
                'amount' => null,
                'type' => 1,
                'name' => 'Original Bid',
                'project_id' => $project->id,
                'vendor_id' => $vendor->id,
                'has_estimate_sections' => false,
            ]];
        }

        return $bids;
    }
}
