<?php

namespace App\Livewire\Forms;

use App\Services\Bids\BidWriter;
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

    /**
     * The per-row resolve-by-id-or-create logic lives in
     * App\Services\Bids\BidWriter so the Hive Contractors app's bids screen
     * writes identical rows.
     */
    public function store()
    {
        // $this->authorize('create', Bid::class);
        $this->component->validate();

        app(BidWriter::class)->upsertMany(
            $this->component->project,
            $this->component->vendor,
            $this->component->bids,
        );
    }
}
