{{-- No margin here: vertical rhythm belongs to the page column (.page-col
     gap), and an extra mt-4 stacked on top of it made this card sit 32px below
     its neighbour instead of 16px. --}}
@php
    // Computed here, not inside the slots: an @if/@can INSIDE a slot passed to
    // a @blaze component is silently dropped (the whole slot renders empty).
    $hasDocs = ! $vendor_docs->isEmpty();
    $canCreateDoc = auth()->user()?->can('create', App\Models\VendorDoc::class) ?? false;
@endphp
<div>
    {{-- Same shared card as Estimates/Checks/Payments: <x-index-table> owns the
         header, the table chrome and the skeleton parity. Collapsible so the
         header keeps its accordion behaviour. --}}
    <x-index-table
        :heading="$view ? 'Vendor Documents' : $vendor->name"
        :collapsible="! $vendor_docs->isEmpty()"
    >
        {{-- Always the same count badge, whatever the accordion state: swapping
             between "3 Current" and nothing on open/close made the header jump. --}}
        {{-- Always the same count badge, whatever the accordion state. --}}
        <x-slot:badge>
            <div class="{{ $hasDocs ? 'contents' : 'hidden' }}">
                <flux:badge color="zinc" size="sm">{{ $vendor_docs->count() }}</flux:badge>
            </div>
        </x-slot:badge>

        <x-slot:actions>
            {{-- Add is always visible; it no longer appears/disappears with the
                 accordion state. --}}
            {{-- Group ONLY when both buttons render. flux:button.group strips the
                 right radius from ":first-child:not(:last-child)", and CSS
                 structural selectors still count a display:none sibling — so a
                 hidden "Request" wrapper left "Add" with a square right edge. --}}
            @php($showRequestDoc = isset($vendor->expired_docs))
            <div class="{{ $canCreateDoc ? 'contents' : 'hidden' }}">
                @if($showRequestDoc)
                    <flux:button.group>
                        <flux:button size="sm" wire:click="$dispatchTo('vendor-docs.vendor-doc-create', 'addDocument', { vendor: {{ $vendor->id }} })">Add</flux:button>
                        <flux:button size="sm" wire:click="$dispatchTo('vendor-docs.vendor-doc-create', 'requestDocument', { vendor: {{ $vendor->id }} })">Request</flux:button>
                    </flux:button.group>
                @else
                    <flux:button size="sm" wire:click="$dispatchTo('vendor-docs.vendor-doc-create', 'addDocument', { vendor: {{ $vendor->id }} })">Add</flux:button>
                @endif
            </div>
            {{-- Same chevron, same position and behaviour as Email Tracking. --}}
            <div class="{{ $hasDocs ? 'contents' : 'hidden' }}">
                <x-card-collapse-toggle label="Toggle documents" />
            </div>
        </x-slot:actions>

        @if(!$vendor_docs->isEmpty())
            {{-- Widths come from the shared columnDefs, so the skeleton and the
                 real header can never drift apart. --}}
            <x-index-table.table :columns="\App\Livewire\VendorDocs\VendorDocsCard::columnDefs()">
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
            </x-index-table.table>
        @endif
    </x-index-table>
</div>
