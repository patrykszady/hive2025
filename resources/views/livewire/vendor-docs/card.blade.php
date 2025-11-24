<flux:card class="mt-4 space-y-2" x-data="{ expanded: false }">
    <div class="flex justify-between">
        <div class="flex items-center gap-3 flex-1 cursor-pointer" @click="expanded = !expanded">
            <flux:heading size="lg">
                @if($view)
                    Insurance
                @else
                    <a href="{{route('vendors.show', $vendor->id)}}">{{$vendor->name}}</a>
                @endif
            </flux:heading>

            @if(!$vendor_docs->isEmpty())
                <div x-show="!expanded" class="flex items-center gap-2">
                    @php
                        $currentDocs = $vendor_docs->filter(fn($doc) => $doc->expiration_date > today());
                    @endphp
                    
                    @if($currentDocs->count() > 0)
                        <flux:badge size="sm" color="green" inset="top bottom">
                            {{ $currentDocs->count() }} Current
                        </flux:badge>
                    @endif
                </div>

                <flux:icon.chevron-down class="w-5 h-5 transition-transform" ::class="expanded ? 'rotate-180' : ''" />
            @endif
        </div>

        @can('create', App\Models\VendorDoc::class)
            <div class="space-x-2" x-show="expanded">
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
        <div x-show="expanded" x-collapse>
            <flux:separator variant="subtle" />

        <flux:table>
            <flux:table.columns>
                {{-- sortable :sorted="$sortBy === 'amount'" :direction="$sortDirection" wire:click="sort('amount')"> --}}
                <flux:table.column>Type</flux:table.column>
                <flux:table.column>Exp Date</flux:table.column>
                <flux:table.column>Policy #</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach($vendor_docs as $doc)
                    <flux:table.row :key="$doc->id">
                        <flux:table.cell variant="strong">
                            <a
                                href="{{ route('expenses.original_receipt', ['vendor_docs', $doc->doc_filename]) }}"
                                target="_blank"
                                >
                                {{$doc->type}}
                            </a>
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:badge size="sm" :color="$doc->expiration_date > today() ? 'green' : 'red'" inset="top bottom">
                                {{$doc->expiration_date->format('m/d/Y')}}
                            </flux:badge>
                        </flux:table.cell>
                        <flux:table.cell>{{$doc->number}}</flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
        </div>
    @endif
    {{-- <livewire:vendor-docs.vendor-doc-create /> --}}
</flux:card>
