<flux:card class="mt-4 space-y-2">
    <div class="flex justify-between">
        <flux:heading size="lg">
            @if($view)
                Insurance
            @else
                <a href="{{route('vendors.show', $vendor->id)}}">{{$vendor->name}}</a>
            @endif
        </flux:heading>

        @can('create', App\Models\User::class)
            <div class="space-x-2">
                {{-- if any docs are expired.. policy? --}}
                @if(isset($vendor->expired_docs))
                    <flux:button.group>
                        <flux:button size="sm" wire:click="$dispatchTo('vendor-docs.vendor-doc-create', 'addDocument', { vendor: {{$vendor->id}} })">Add</flux:button>
                        <flux:button size="sm" wire:click="$dispatchTo('vendor-docs.vendor-doc-create', 'requestDocument', { vendor: {{$vendor->id}} })">Request</flux:button>
                    </flux:button.group>
                @else
                    <flux:button size="sm" wire:click="$dispatchTo('vendor-docs.vendor-doc-create', 'addDocument', { vendor: {{$vendor->id}} })">Add</flux:button>
                @endif
            </div>
        @endcan
    </div>

    @if(!$vendor_docs->isEmpty())
        <flux:separator variant="subtle" />
        <flux:table>
            <flux:columns>
                {{-- sortable :sorted="$sortBy === 'amount'" :direction="$sortDirection" wire:click="sort('amount')"> --}}
                <flux:column>Type</flux:column>
                <flux:column>Exp Date</flux:column>
                <flux:column>Policy #</flux:column>
            </flux:columns>

            <flux:rows>
                @foreach($vendor_docs as $doc_index => $doc)
                    <flux:row :key="$doc_index">
                        <flux:cell variant="strong">{{$doc->first()->type}}</flux:cell>
                        <flux:cell>
                            <flux:badge size="sm" :color="$doc->first()->expiration_date > today() ? 'green' : 'red'" inset="top bottom">
                                {{$doc->first()->expiration_date->format('m/d/Y')}}
                            </flux:badge>
                        </flux:cell>
                        <flux:cell>{{$doc->first()->number}}</flux:cell>
                    </flux:row>
                    {{-- <flux:badge size="sm" :color="$doc->first()->expiration_date > today() ? 'green' : 'red'" inset="top bottom">{{$doc->first()->expiration_date > today() ? 'Active' : 'Expired'}}</flux:badge> --}}
                @endforeach
            </flux:rows>
        </flux:table>
    @endif
</flux:card>
