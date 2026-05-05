<div>
    <x-island-card
        :heading="$view ? 'Vendor Documents' : $vendor->name"
        :href="!$view ? route('vendors.show', $vendor->id) : null"
        class="mt-4"
        x-data="{ expanded: true }"
    >
        <x-slot:badge>
            @if(!$vendor_docs->isEmpty())
                <div x-show="!expanded" x-cloak class="flex items-center gap-2">
                    @php
                        $currentDocs = $vendor_docs->filter(fn($doc) => $doc->expiration_date > today());
                        $expiredDocs = $vendor_docs->filter(fn($doc) => $doc->expiration_date <= today());
                    @endphp

                    @if($currentDocs->count() > 0)
                        <flux:badge size="sm" color="green" inset="top bottom">
                            {{ $currentDocs->count() }} Current
                        </flux:badge>
                    @endif

                    @if($expiredDocs->count() > 0)
                        <flux:badge size="sm" color="red" inset="top bottom">
                            {{ $expiredDocs->count() }} Expired
                        </flux:badge>
                    @endif
                </div>

                <flux:icon.chevron-down class="w-5 h-5 transition-transform cursor-pointer" ::class="expanded ? 'rotate-180' : ''" @click="expanded = !expanded" />
            @endif
        </x-slot:badge>

        <x-slot:actions>
            @can('create', App\Models\VendorDoc::class)
                <div x-show="expanded" x-cloak>
                    <flux:button.group>
                        <flux:button size="sm" wire:click="$dispatchTo('vendor-docs.vendor-doc-create', 'addDocument', { vendor: {{$vendor->id}} })">Add</flux:button>
                        @if(isset($vendor->expired_docs))
                            <flux:button size="sm" wire:click="$dispatchTo('vendor-docs.vendor-doc-create', 'requestDocument', { vendor: {{$vendor->id}} })">Request</flux:button>
                        @endif
                    </flux:button.group>
                </div>
            @endcan
        </x-slot:actions>

        @if(!$vendor_docs->isEmpty())
            <div x-show="expanded" x-cloak x-collapse>
                <flux:separator variant="subtle" />

                <flux:table>
                    <flux:table.columns>
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
                                        {{$doc->type_label}}
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
    </x-island-card>
</div>
