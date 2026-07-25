{{-- Edit Bid button — included by BOTH the Project Finances card and its
     loading skeleton, so the header is real from the first paint. The bid
     check is one light query; the modal component it dispatches to is mounted
     with the card, so during the skeleton the click simply waits for load. --}}
@can('create', App\Models\Bid::class)
    @php
        $userBids = $project->bids()->vendorBids(auth()->user()->vendor->id)->with('estimate_sections')->get();
        $hasEditableBids = $userBids->isEmpty() || $userBids->contains(function ($bid) {
            return $bid->estimate_sections->isEmpty();
        });
    @endphp
    @if($hasEditableBids)
        <flux:button
            wire:click="$dispatchTo('bids.bid-create', 'addBids', { vendor: {{auth()->user()->vendor->id}}, project: {{$project->id}} })"
            size="sm"
            >
            Edit Bid
        </flux:button>
    @endif
@endcan
