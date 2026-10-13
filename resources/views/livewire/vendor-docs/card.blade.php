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
                    @include('livewire.vendor-docs._doc-row', ['doc' => $doc])
                @endforeach
            </x-index-table.table>

            {{-- The older documents (every doc that is not the latest of its
                 type), folded away under the current ones: the renewal history
                 of each policy, expired certificates included. --}}
            @if($previous_docs->isNotEmpty())
                <flux:accordion class="mt-4 pt-2 pb-1 border-t border-zinc-200 dark:border-white/10">
                    <flux:accordion.item>
                        <flux:accordion.heading class="py-2">Previous / expired ({{ $previous_docs->count() }})</flux:accordion.heading>
                        <flux:accordion.content class="pt-2">
                            <x-index-table.table :columns="\App\Livewire\VendorDocs\VendorDocsCard::columnDefs()">
                                @foreach($previous_docs as $doc)
                                    @include('livewire.vendor-docs._doc-row', ['doc' => $doc])
                                @endforeach
                            </x-index-table.table>
                        </flux:accordion.content>
                    </flux:accordion.item>
                </flux:accordion>
            @endif
        @endif
    </x-index-table>
</div>
