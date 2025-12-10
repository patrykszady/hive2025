<div class="max-w-2xl">
    <flux:card class="space-y-2">
        <div class="flex justify-between">
            <flux:heading size="lg">Filters</flux:heading>
            @can('create', App\Models\Vendor::class)
                <flux:button wire:click="$dispatchTo('vendors.vendor-create', 'newVendor')">Add New Vendor</flux:button>
            @endcan
        </div>

        <flux:separator variant="subtle" />

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <flux:input wire:model.live="business_name" label="Vendor Name" icon="magnifying-glass" placeholder="Search Vendors" />

            <flux:select wire:model.live="vendor_type" label="Business Type" wire:model="vendor_type" placeholder="Choose type...">
                <flux:select.option value="All">All Vendor Types</flux:select.option>
                <flux:select.option value="Sub">Subcontractor</flux:select.option>
                <flux:select.option value="Retail">Retail</flux:select.option>
                <flux:select.option value="1099">1099/Independent</flux:select.option>
                <flux:select.option value="DBA">DBA</flux:select.option>
            </flux:select>
        </div>
    </flux:card>

    <flux:card class="mt-4 space-y-2">
        <div>
            <flux:heading size="lg">Vendors</flux:heading>
        </div>

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
    </flux:card>
</div>
