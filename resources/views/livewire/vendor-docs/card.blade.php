<flux:card class="mt-4 space-y-2">
    <div class="flex justify-between">
        <flux:heading size="lg">
            @if($view)
                Insurance
            @else
                <a href="{{route('vendors.show', $vendor->id)}}">{{$vendor->name}}</a>
            @endif
        </flux:heading>

        @can('create', App\Models\VendorDoc::class)
            <div class="space-x-2">
                {{-- if any docs are expired.. policy? --}}
                <flux:button.group>
                    <flux:button size="sm" wire:click="$dispatchTo('vendor-docs.vendor-doc-create', 'addDocument', { vendor: {{$vendor->id}} })">Add</flux:button>
                    @if(isset($vendor->expired_docs))
                        <flux:button size="sm" wire:click="$dispatchTo('vendor-docs.vendor-doc-create', 'requestDocument', { vendor: {{$vendor->id}} })">Request</flux:button>
                    @endif
                </flux:button.group>
            </div>
        @endcan
    </div>

    @if(!$vendor_docs->isEmpty())
        <flux:separator variant="subtle" />

        <flux:table>
            <flux:table.columns>
                {{-- sortable :sorted="$sortBy === 'amount'" :direction="$sortDirection" wire:click="sort('amount')"> --}}
                <flux:table.column>Type</flux:table.column>
                <flux:table.column>Exp Date</flux:table.column>
                <flux:table.column>Policy #</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach($vendor_docs as $doc_index => $doc)
                    <flux:table.row :key="$doc_index">
                        <flux:table.cell variant="strong">
                            <a
                                href="{{ route('expenses.original_receipt', ['vendor_docs', $doc->first()->doc_filename]) }}"
                                target="_blank"
                                >
                                {{$doc->first()->type}}
                            </a>
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:badge size="sm" :color="$doc->first()->expiration_date > today() ? 'green' : 'red'" inset="top bottom">
                                {{$doc->first()->expiration_date->format('m/d/Y')}}
                            </flux:badge>
                        </flux:table.cell>
                        <flux:table.cell>{{$doc->first()->number}}</flux:table.cell>
                    </flux:table.row>
                    {{-- <flux:badge size="sm" :color="$doc->first()->expiration_date > today() ? 'green' : 'red'" inset="top bottom">{{$doc->first()->expiration_date > today() ? 'Active' : 'Expired'}}</flux:badge> --}}
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif
    {{-- <livewire:vendor-docs.vendor-doc-create /> --}}
</flux:card>
