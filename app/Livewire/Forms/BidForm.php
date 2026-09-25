<?php

namespace App\Livewire\Forms;

use App\Models\Bid;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Form;

class BidForm extends Form
{
    use AuthorizesRequests;

    // public function setBids($bids)
    // {
    //     $this->bids = $bids;
    //     // dd($bids);
    // }

    public function store()
    {
        // $this->authorize('create', Bid::class);
        $this->component->validate();

        foreach ($this->component->bids as $index => $bid) {
            if (isset($bid['id'])) {
                // $bid['id'] is a client-controlled array value: resolve it
                // through this project's own bids for this vendor so it
                // can't be swapped for another tenant's bid id.
                $updated_bid = $this->component->project->bids()
                    ->where('vendor_id', $this->component->vendor->id)
                    ->findOrFail($bid['id']);
                $updated_bid->update([
                    'amount' => $bid['amount'],
                    'project_id' => $this->component->project->id,
                ]);
            } else {
                $type = $bid['type'] ?? null;
                $type = is_numeric($type) ? (int) $type : ($index + 1);

                Bid::create([
                    'amount' => $bid['amount'],
                    'type' => $type,
                    'project_id' => $this->component->project->id,
                    'vendor_id' => $this->component->vendor->id,
                ]);
            }
        }
    }
}
