<div class="max-w-2xl">
    <x-filter-card>
        {{-- single copy: the inline layout stacks below sm on its own --}}
        @include('livewire.vendors.partials.filter-fields', ['layout' => 'inline'])
    </x-filter-card>

    {{-- lazy island: the card paints from the shared skeleton first, then the
         real table swaps in — same loading treatment as the Leads card.
         always: true because this page's drivers live OUTSIDE the island — the
         filter card (business_name / vendor_type) and the VendorCreated event
         that prepends a new row. Without it those updates come back as a
         mode=skip fragment and the table keeps showing stale rows. --}}
    @island(name: 'vendors-table', lazy: true, always: true)
        @placeholder
            <x-index-table.placeholder
                heading="Vendors"
                floor-min="600px"
                :columns="\App\Livewire\Vendors\VendorsIndex::columnDefs()"
                :rows="\App\Livewire\Vendors\VendorsIndex::placeholderRows()"
                :page-size="\App\Livewire\Vendors\VendorsIndex::placeholderRows()"
                :compact="false"
                class="mt-4"
            >
                <x-slot:actions>
                    @include('livewire.vendors.partials.index-actions')
                </x-slot:actions>
            </x-index-table.placeholder>
        @endplaceholder
    <x-index-table heading="Vendors" :paginator="$this->vendors" class="mt-4">
        <x-slot:actions>
            @include('livewire.vendors.partials.index-actions')
        </x-slot:actions>

        <x-slot:before>
            @can('create', App\Models\Vendor::class)
                <livewire:vendors.vendor-create />
            @endcan
        </x-slot:before>

            {{-- /vendors is max-w-2xl (~630px interior): lower the 640px floor --}}
            <flux:table class="index-table [:where(&)]:p-0 [:where(&)]:space-y-0" style="--index-table-min: 600px">
                <flux:table.columns>
                    <flux:table.column class="w-[40%] min-w-0" sortable :sorted="$sortBy === 'business_name'" :direction="$sortDirection" wire:click="sort('business_name')">Vendor</flux:table.column>
                    <flux:table.column class="w-[18%]">Type</flux:table.column>
                    @can('create', App\Models\Vendor::class)
                        <flux:table.column class="w-[20%]" sortable :sorted="$sortBy === 'ytd_expense_sum'" :direction="$sortDirection" wire:click="sort('ytd_expense_sum')">YTD Paid</flux:table.column>
                    @endcan
                    <flux:table.column class="w-[22%]" sortable :sorted="$sortBy === 'attached_at'" :direction="$sortDirection" wire:click="sort('attached_at')">Since</flux:table.column>
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
                            <flux:table.cell variant="strong" class="min-w-0">
                                <x-table-link :href="route('vendors.show', ['vendor' => $new['id']])" :label="$new['name']" />
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
                            <flux:table.cell variant="strong" class="min-w-0"><x-table-link :href="route('vendors.show', $vendor->id)" :label="$vendor->name" /></flux:table.cell>
                            <flux:table.cell><flux:badge color="blue" inset="top bottom">{{$vendor->business_type}}</flux:badge></flux:table.cell>
                            @can('create', App\Models\Vendor::class)
                                <flux:table.cell>{{ money($vendor->ytd_expense_sum) }}</flux:table.cell>
                            @endcan
                            <flux:table.cell class="whitespace-nowrap">
                                {{ ($this->attachedMap[$vendor->id] ?? null) ? \Carbon\Carbon::parse($this->attachedMap[$vendor->id])->diffForHumans() : '—' }}
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
    </x-index-table>
    @endisland

    <livewire:vendors.vendor-payment-email-tracking-table lazy />
</div>
