<div>
    <x-details.card
        title="Subs / Vendors"
        :expanded="true"
        :details_text="false"
        :separator="false"
    >
        <x-slot:header_buttons>
            @can('update', $project)
                <flux:button size="sm" icon="plus" wire:click="openInviteModal">
                    Invite
                </flux:button>
            @endcan
        </x-slot:header_buttons>

        <x-slot:details>
            @forelse ($project->vendors as $vendor)
                @php
                    $vendorStatus = $project->latestVendorStatus($vendor->id);
                @endphp
                <div wire:key="project-vendor-{{ $vendor->id }}" class="flex items-center gap-3 py-2 sm:py-3 [&:not(:last-child)]:border-b [&:not(:last-child)]:border-zinc-800/15 dark:[&:not(:last-child)]:border-white/20">
                    <flux:subheading class="flex-1 min-w-0 truncate">
                        <flux:link href="{{ route('vendors.show', $vendor) }}" variant="ghost" :accent="false" class="font-normal no-underline hover:no-underline hover:text-indigo-600 dark:hover:text-indigo-400" wire:navigate.hover>
                            {{ $vendor->short_name ?? $vendor->name }}
                        </flux:link>
                    </flux:subheading>
                    @if ($vendorStatus)
                        <flux:badge size="sm" :color="$vendorStatus->badge_color" inset="top bottom">
                            {{ $vendorStatus->title }}
                        </flux:badge>
                    @endif
                </div>
            @empty
                <flux:text class="py-2 text-zinc-400">No vendors invited yet.</flux:text>
            @endforelse
        </x-slot:details>
    </x-details.card>

    {{-- Invite Modal --}}
    <flux:modal wire:model.self="showModal" class="min-w-[22rem]">
        <form wire:submit="save">
            <div class="space-y-6">
                <flux:heading size="lg">Invite Vendor to Project</flux:heading>

                {{-- NOTE: a <style> block here once hid `[data-flux-option] >
                     div:first-child` to suppress the checkmark column — and
                     its second selector leaked GLOBALLY, so when Flux 2.16
                     moved an option's whole content into that first div, every
                     select on every project page rendered empty. Options keep
                     their standard indicator now; never blanket-hide Flux
                     internals by structure again. --}}
                <flux:select
                    wire:model="vendor_id"
                    variant="listbox"
                    placeholder="Choose vendor..."
                    label="Vendor"
                    searchable
                    data-vendor-listbox
                >
                    <x-slot name="search">
                        <flux:select.search placeholder="Search vendors..." />
                    </x-slot>

                    @foreach ($this->availableVendors as $vendor)
                        <flux:select.option 
                            wire:key="vendor-{{ $vendor->id }}" 
                            :value="$vendor->id"
                            :disabled="$this->isVendorInvited($vendor)"
                        >
                            <div class="flex items-center gap-2 whitespace-nowrap">
                                <flux:avatar size="xs" name="{{ $vendor->short_name ?? $vendor->name }}" color="auto" color:seed="{{ $vendor->id }}" />
                                @if ($this->isVendorInvited($vendor))
                                    <span class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-md bg-green-100 text-green-700">
                                        <flux:icon.check class="h-3.5 w-3.5" />
                                    </span>
                                @endif
                                <span class="flex-1 min-w-0 truncate">{{ $vendor->short_name ?? $vendor->name }}</span>
                            </div>
                        </flux:select.option>
                    @endforeach
                </flux:select>
                <flux:error name="vendor_id" />

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">Cancel</flux:button>
                    </flux:modal.close>
                    <flux:button 
                        type="submit" 
                        variant="primary"
                        :disabled="$this->vendor_id && $this->isVendorInvited(\App\Models\Vendor::find($this->vendor_id))"
                    >
                        Invite
                    </flux:button>
                </div>
            </div>
        </form>
    </flux:modal>
</div>
