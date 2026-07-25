<div class="mt-4">
    <x-details.card
        :title="$view ? 'Vendor Documents' : $vendor->name"
        :title_href="!$view ? route('vendors.show', $vendor->id) : null"
    >
        <x-slot:title_extras>
            @if(!$vendor_docs->isEmpty())
                <div x-show="!open" x-cloak class="flex items-center gap-2">
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
            @endif
        </x-slot:title_extras>

        <x-slot:header_buttons>
            @can('create', App\Models\VendorDoc::class)
                <div @if(!$vendor_docs->isEmpty()) x-show="open" x-cloak @endif>
                    <flux:button.group>
                        <flux:button size="sm" wire:click="$dispatchTo('vendor-docs.vendor-doc-create', 'addDocument', { vendor: {{$vendor->id}} })">Add</flux:button>
                        @if(isset($vendor->expired_docs))
                            <flux:button size="sm" wire:click="$dispatchTo('vendor-docs.vendor-doc-create', 'requestDocument', { vendor: {{$vendor->id}} })">Request</flux:button>
                        @endif
                    </flux:button.group>
                </div>
            @endcan
        </x-slot:header_buttons>

        @if(!$vendor_docs->isEmpty())
            <x-slot:details>
                {{-- table-fixed + min-w-0 so the card never scrolls horizontally;
                     long values truncate with a title tooltip instead. --}}
                <flux:table class="table-fixed min-w-0 w-full">
                    <flux:table.columns>
                        <flux:table.column class="w-[38%]">Type</flux:table.column>
                        <flux:table.column class="w-[30%]">Exp Date</flux:table.column>
                        <flux:table.column class="w-[32%]">Policy #</flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @foreach($vendor_docs as $doc)
                            <flux:table.row :key="$doc->id">
                                <flux:table.cell variant="strong" class="w-[38%]">
                                    <x-truncate-tooltip :content="$doc->type_label">
                                    <a
                                        href="{{ route('expenses.original_receipt', ['vendor_docs', $doc->doc_filename]) }}"
                                        target="_blank"
                                        class="block truncate"
                                    >
                                        {{$doc->type_label}}
                                    </a>
                                    </x-truncate-tooltip>
                                </flux:table.cell>
                                <flux:table.cell class="w-[30%]">
                                    <flux:badge size="sm" :color="$doc->expiration_date > today() ? 'green' : 'red'" inset="top bottom">
                                        {{$doc->expiration_date->format('m/d/Y')}}
                                    </flux:badge>
                                </flux:table.cell>
                                <flux:table.cell class="w-[32%]">
                                    <x-truncate-tooltip :content="(string) $doc->number"><div class="truncate">{{ $doc->number }}</div></x-truncate-tooltip>
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </x-slot:details>
        @endif
    </x-details.card>
</div>
