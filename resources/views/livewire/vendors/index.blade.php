<div class="max-w-2xl">
    {{-- Mobile: accordion collapsed by default --}}
    <flux:card class="!px-5 !py-2 sm:hidden">
        <flux:accordion transition>
            <flux:accordion.item>
                <flux:accordion.heading>
                    <flux:heading size="lg">Filters</flux:heading>
                </flux:accordion.heading>
                <flux:accordion.content>
                    <div class="flex flex-col gap-4">
                        <div class="min-w-0">
                            <flux:input wire:model.live="business_name" label="Vendor Name" icon="magnifying-glass" placeholder="Search Vendors" />
                        </div>
                        <div class="min-w-0">
                            <flux:select wire:model.live="vendor_type" label="Business Type" wire:model="vendor_type" placeholder="Choose type...">
                                <flux:select.option value="All">All Vendor Types</flux:select.option>
                                <flux:select.option value="Sub">Subcontractor</flux:select.option>
                                <flux:select.option value="Retail">Retail</flux:select.option>
                                <flux:select.option value="1099">1099/Independent</flux:select.option>
                                <flux:select.option value="DBA">DBA</flux:select.option>
                            </flux:select>
                        </div>
                    </div>
                </flux:accordion.content>
            </flux:accordion.item>
        </flux:accordion>
    </flux:card>

    {{-- Desktop: always expanded --}}
    <x-island-card heading="Filters" :separator="true" class="hidden sm:block">
        <div class="flex flex-col sm:flex-row items-end gap-4">
            <div class="flex-1 min-w-0">
                <flux:input wire:model.live="business_name" label="Vendor Name" icon="magnifying-glass" placeholder="Search Vendors" />
            </div>

            <div class="flex-1 min-w-0">
                <flux:select wire:model.live="vendor_type" label="Business Type" wire:model="vendor_type" placeholder="Choose type...">
                    <flux:select.option value="All">All Vendor Types</flux:select.option>
                    <flux:select.option value="Sub">Subcontractor</flux:select.option>
                    <flux:select.option value="Retail">Retail</flux:select.option>
                    <flux:select.option value="1099">1099/Independent</flux:select.option>
                    <flux:select.option value="DBA">DBA</flux:select.option>
                </flux:select>
            </div>
        </div>
    </x-island-card>

    <x-island-card heading="Vendors" class="mt-4">
        <x-slot:actions>
            @can('create', App\Models\Vendor::class)
                <flux:button size="sm" wire:click="$dispatchTo('vendors.vendor-create', 'newVendor')">Add New Vendor</flux:button>
            @endcan
        </x-slot:actions>

        <div class="space-y-2">
            <flux:table :paginate="$this->vendors->hasPages() ? $this->vendors : null">
                <flux:table.columns>
                    <flux:table.column sortable :sorted="$sortBy === 'business_name'" :direction="$sortDirection" wire:click="sort('business_name')">Vendor</flux:table.column>
                    <flux:table.column>Type</flux:table.column>
                    @can('create', App\Models\Vendor::class)
                        <flux:table.column sortable :sorted="$sortBy === 'ytd_expense_sum'" :direction="$sortDirection" wire:click="sort('ytd_expense_sum')">YTD Paid</flux:table.column>
                    @endcan
                    <flux:table.column sortable :sorted="$sortBy === 'attached_at'" :direction="$sortDirection" wire:click="sort('attached_at')">Since</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    {{-- Ephemeral newly created vendors for this session --}}
                    @foreach ($newVendors as $new)
                        <flux:table.row
                            :key="'new-'.$new['id']"
                            x-data="{ show: false }"
                            x-init="requestAnimationFrame(() => show = true)"
                            x-show="show"
                            x-transition:enter="transition ease-in duration-300"
                            x-transition:enter-start="opacity-0 translate-y-1"
                            x-transition:enter-end="opacity-100 translate-y-0"
                            class="bg-green-50 dark:bg-green-900/10"
                        >
                            <flux:table.cell variant="strong">
                                <a wire:navigate.hover href="{{ route('vendors.show', ['vendor' => $new['id']]) }}">{{ $new['name'] }}</a>
                            </flux:table.cell>
                            <flux:table.cell>
                                <flux:badge color="green" inset="top bottom">{{ $new['business_type'] }}</flux:badge>
                            </flux:table.cell>
                            @can('create', App\Models\Vendor::class)
                                <flux:table.cell>{{ money($new['ytd_expense_sum'] ?? 0) }}</flux:table.cell>
                            @endcan
                            <flux:table.cell>
                                <span class="text-green-700 dark:text-green-300">Just now</span>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach

                    @foreach ($this->vendors as $vendor)
                        @continue(in_array($vendor->id, $this->newVendorIds))
                        <flux:table.row :key="$vendor->id">
                            <flux:table.cell variant="strong"><a wire:navigate.hover href="{{route('vendors.show', $vendor->id)}}">{{$vendor->name}}</a></flux:table.cell>
                            <flux:table.cell><flux:badge color="blue" inset="top bottom">{{$vendor->business_type}}</flux:badge></flux:table.cell>
                            @can('create', App\Models\Vendor::class)
                                <flux:table.cell>{{ money($vendor->ytd_expense_sum) }}</flux:table.cell>
                            @endcan
                            <flux:table.cell>
                                {{ ($this->attachedMap[$vendor->id] ?? null) ? \Carbon\Carbon::parse($this->attachedMap[$vendor->id])->diffForHumans() : '—' }}
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>

            {{-- VENDOR FORM MODAL --}}
            @can('create', App\Models\Vendor::class)
                <livewire:vendors.vendor-create />
            @endcan
        </div>
    </x-island-card>

    <livewire:vendors.vendor-payment-email-tracking-table lazy />
</div>
