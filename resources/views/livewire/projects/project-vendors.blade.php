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
                <x-details.row
                    :title="$vendor->name"
                    :content="$vendorStatus?->title ?? 'Invited'"
                    :noCloak="true"
                />
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

                <flux:select wire:model="vendor_id" placeholder="Choose vendor..." label="Vendor">
                    @foreach ($this->availableVendors as $vendor)
                        <flux:select.option :value="$vendor->id">{{ $vendor->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:error name="vendor_id" />

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">Cancel</flux:button>
                    </flux:modal.close>
                    <flux:button type="submit" variant="primary">Invite</flux:button>
                </div>
            </div>
        </form>
    </flux:modal>
</div>
