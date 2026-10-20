<?php

namespace App\Services\Bids;

use App\Models\Bid;
use App\Models\Project;
use App\Models\Vendor;
use Illuminate\Validation\ValidationException;

/**
 * Saves a project's bids for a vendor exactly like
 * App\Livewire\Forms\BidForm::store() — each row either updates an existing
 * bid (resolved through THIS project's own bids for THIS vendor, so a
 * forged id can never reach another tenant's row) or creates one — so the
 * web's bid modal and the Hive Contractors app's bids screen write
 * identical rows.
 */
class BidWriter
{
    /**
     * @param  list<array{id?: int, amount: float|string, type?: int}>  $bids
     * @return list<Bid>
     */
    public function upsertMany(Project $project, Vendor $vendor, array $bids): array
    {
        return array_map(
            fn (array $bid, int $index): Bid => $this->upsertOne($project, $vendor, $bid, $index),
            $bids,
            array_keys($bids),
        );
    }

    /**
     * @param  array{id?: int, amount: float|string, type?: int}  $bid
     */
    private function upsertOne(Project $project, Vendor $vendor, array $bid, int $index): Bid
    {
        if (isset($bid['id'])) {
            // $bid['id'] is a client-controlled array value: resolve it
            // through this project's own bids for this vendor so it can't
            // be swapped for another tenant's bid id.
            $updated = $project->bids()->where('vendor_id', $vendor->id)->findOrFail($bid['id']);
            $updated->update([
                'amount' => $bid['amount'],
                'project_id' => $project->id,
            ]);

            return $updated;
        }

        $type = $bid['type'] ?? null;
        $type = is_numeric($type) ? (int) $type : ($index + 1);

        return Bid::create([
            'amount' => $bid['amount'],
            'type' => $type,
            'project_id' => $project->id,
            'vendor_id' => $vendor->id,
        ]);
    }

    /**
     * Removes a bid/change order exactly like
     * App\Livewire\Bids\BidCreate::removeChangeOrder() — scoped through
     * this project's own bids for this vendor, so a forged id can never
     * reach another tenant's row. Blocked the same two ways the web's
     * "Remove" button is hidden (bids/form.blade.php): never the Original
     * Bid (type 1, the loop's first row), and never a bid the viewing
     * vendor's own estimate sections already reference — the web only
     * skips that second guard in 'payment' context, which this project-page
     * endpoint is not.
     */
    public function delete(Project $project, Vendor $vendor, int $bidId, int $viewingVendorId): void
    {
        $bid = $project->bids()->where('vendor_id', $vendor->id)->with('estimate_sections')->findOrFail($bidId);

        if ((int) $bid->type === 1) {
            throw ValidationException::withMessages([
                'id' => 'The original bid cannot be removed.',
            ]);
        }

        $belongsToViewingVendor = (int) $bid->vendor_id === $viewingVendorId;
        $hasEstimateSections = $bid->estimate_sections->where('bid_id', $bid->id)->isNotEmpty();

        if ($belongsToViewingVendor && $hasEstimateSections) {
            throw ValidationException::withMessages([
                'id' => 'This bid is referenced by an estimate and cannot be removed.',
            ]);
        }

        $bid->delete();
    }
}
