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
                $updated_bid = Bid::withoutGlobalScopes()->findOrFail($bid['id']);
                $updated_bid->update([
                    'amount' => $bid['amount'],
                    'project_id' => $this->component->project->id,
                ]);
            } else {
                $bid_index = count($this->component->bids);

                Bid::create([
                    'amount' => $bid['amount'],
                    'type' => $bid_index + 1,
                    'project_id' => $this->component->project->id,
                    'vendor_id' => $this->component->vendor->id,
                ]);
            }
        }
    }
}
