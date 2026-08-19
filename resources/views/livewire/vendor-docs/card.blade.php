{{-- No margin here: vertical rhythm belongs to the page column (.page-col
     gap), and an extra mt-4 stacked on top of it made this card sit 32px below
     its neighbour instead of 16px. --}}
@php
    // Computed here, not inside the slots: an @if/@can INSIDE a slot passed to
    // a @blaze component is silently dropped (the whole slot renders empty).
    $hasDocs = ! $vendor_docs->isEmpty();
    $canCreateDoc = auth()->user()?->can('create', App\Models\VendorDoc::class) ?? false;
    $canViewVendor = auth()->user()?->can('view', $vendor) ?? false;
@endphp
<div>
    {{-- Same shared card as Estimates/Checks/Payments: <x-index-table> owns the
         header, the table chrome and the skeleton parity. Collapsible so the
         header keeps its accordion behaviour. --}}
    {{-- Heading links to the vendor, except on the vendor's OWN page ($view),
         where the heading is a section label and the link would point at the
         page you are already on. Gated on the policy the route enforces
         (can:view,vendor) so we never render a link that 403s. --}}
    <x-index-table
        :heading="$view ? 'Vendor Documents' : $vendor->name"
        :href="! $view && $canViewVendor ? route('vendors.show', $vendor) : null"
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
                        <flux:table.cell variant="strong" class="w-[32%]">
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
                        <flux:table.cell class="w-[24%]">
                            <flux:badge size="sm" :color="$doc->expiration_date > today() ? 'green' : 'red'" inset="top bottom">
                                {{$doc->expiration_date->format('m/d/Y')}}
                            </flux:badge>
                        </flux:table.cell>
                        <flux:table.cell class="w-[28%]">
                            <x-truncate-tooltip :content="(string) $doc->number"><div class="truncate">{{ $doc->number }}</div></x-truncate-tooltip>
                        </flux:table.cell>
                        {{-- Coverage verification: the date a provider last
                             confirmed this policy. EWCCV reports the carrier's
                             filing with the state rating bureau; TrustLayer
                             reports the certificate the sub's agent provided —
                             either counts, and the tooltip names both.
                             Logic lives on the model ($doc->verification_summary)
                             because @php blocks miscompile in Blaze slots. --}}
                        <flux:table.cell class="w-[16%] whitespace-nowrap">
                            @if($doc->verification_summary)
                                <flux:tooltip :content="$doc->verification_summary['tip']" position="top">
                                    <div class="flex items-center gap-1.5">
                                        @if($doc->verification_summary['verified_at'])
                                            <flux:icon.shield-check variant="micro" class="size-3.5 shrink-0 text-emerald-500" />
                                            <span>{{ $doc->verification_summary['verified_at'] }}</span>
                                        @else
                                            <flux:icon.shield-exclamation variant="micro" class="size-3.5 shrink-0 text-amber-500" />
                                        @endif
                                    </div>
                                </flux:tooltip>
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </x-index-table.table>
        @endif
    </x-index-table>
</div>
