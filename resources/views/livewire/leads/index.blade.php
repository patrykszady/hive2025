<div class="max-w-3xl space-y-2" wire:transition>
    @if($view === NULL)
        <x-filter-card heading="Lead Filters">
            {{-- single copy: the inline layout stacks below sm on its own --}}
            @include('livewire.leads.partials.filter-fields', ['layout' => 'inline'])
        </x-filter-card>
    @endif

    {{-- lazy island: the card paints from the shared skeleton first, then the
         real table swaps in — same loading treatment as the Projects card. --}}
    @island(name: 'leads-table', lazy: island_lazy(), always: true)
        @placeholder
            <x-index-table.placeholder
                heading="Leads"
                :columns="\App\Livewire\Leads\LeadsIndex::columnDefs()"
                :rows="\App\Livewire\Leads\LeadsIndex::placeholderRows()"
            >
                <x-slot:actions>
                    @include('livewire.leads.partials.index-actions')
                </x-slot:actions>
            </x-index-table.placeholder>
        @endplaceholder
    <x-index-table heading="Leads" :paginator="$this->leads" x-data="{
        bulkMode: false,
        exitBulkMode() {
            this.bulkMode = false;
            $wire.clearSelection();
        }
    }">
        <x-slot:badge>
            <flux:badge as="button" size="sm" color="zinc" icon="cursor-arrow-rays" x-show="!bulkMode" x-on:click="bulkMode = true">Select Items</flux:badge>
            <div class="flex items-center gap-2" x-show="bulkMode" x-cloak>
                <flux:badge color="indigo" size="sm">
                    <span x-text="$wire.selected.length"></span>&nbsp;Selected
                </flux:badge>
                <flux:button size="xs" variant="ghost" x-on:click="exitBulkMode()">Clear</flux:button>
                <flux:dropdown align="end">
                    <flux:button size="xs" icon-trailing="chevron-down">Bulk actions</flux:button>
                    <flux:menu>
                        <flux:menu.submenu heading="Change status">
                            @foreach(\App\Models\Lead::selectableStatuses() as $status)
                                <flux:menu.item
                                    wire:click="bulkSetStatus('{{ $status['code'] }}')"
                                    x-bind:disabled="$wire.selected.length === 0"
                                >
                                    <flux:badge size="sm" inset="top bottom" :color="$status['color']">{{ $status['label'] }}</flux:badge>
                                </flux:menu.item>
                            @endforeach
                        </flux:menu.submenu>
                    </flux:menu>
                </flux:dropdown>
            </div>
        </x-slot:badge>
        <x-slot:actions>
            @include('livewire.leads.partials.index-actions')
        </x-slot:actions>

            <flux:table
                wire:loading.class.delay.shortest="opacity-50 pointer-events-none"
                wire:target="bulkSetStatus"
                class="transition-opacity duration-150 index-table compact-table [:where(&)]:p-0 [:where(&)]:space-y-0"
            >
                <flux:table.columns>
                    <flux:table.column class="w-10 !px-3" x-show="bulkMode" x-cloak>
                        <span class="sr-only">Select</span>
                    </flux:table.column>
                    <flux:table.column sortable :sorted="$sortBy === 'date'" :direction="$sortDirection" wire:click="sort('date')" class="w-[14%]">Date</flux:table.column>
                    <flux:table.column class="w-[17%] min-w-0">Client</flux:table.column>
                    <flux:table.column class="w-[20%]">Status</flux:table.column>
                    <flux:table.column class="w-[14%]">Last</flux:table.column>
                    <flux:table.column class="w-[12%] min-w-0">Origin</flux:table.column>
                    <flux:table.column class="w-[23%] min-w-0">Address</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($this->leads as $lead)
                        <flux:table.row :key="$lead->id">
                            <flux:table.cell class="w-10 !px-3" x-show="bulkMode" x-cloak>
                                <flux:checkbox size="sm" wire:model="selected" value="{{ $lead->id }}" />
                            </flux:table.cell>
                            <flux:table.cell
                                wire:click="$dispatchTo('leads.lead-create', 'editLead', { lead: {{$lead->id}}})"
                                variant="strong"
                                class="cursor-pointer w-[14%] whitespace-nowrap hover:text-indigo-600 dark:hover:text-indigo-400"
                                >
                                {{ $lead->date->format('m/d/y') }}
                            </flux:table.cell>

                            <flux:table.cell class="w-[17%] min-w-0">
                                <x-table-link
                                    :href="($leadClient = $this->clientForLead($lead)) ? route('clients.show', $leadClient) : null"
                                    :label="$lead->lead_data['name'] ?? ''"
                                />
                            </flux:table.cell>

                            <flux:table.cell class="w-[20%]">
                                <x-status-select
                                    :value="$lead->last_status?->title ?? ''"
                                    :options="\App\Models\Lead::selectableStatuses()"
                                    method="updateLeadStatus"
                                    :model-id="$lead->id"
                                />
                            </flux:table.cell>

                            <flux:table.cell class="w-[14%] whitespace-nowrap">
                                @if($lead->last_status && !in_array($lead->last_status->title, ['New', 'Won', 'Lost', 'Not a Fit']))
                                    {{ $lead->last_status->created_at->format('m/d/y') }}
                                @endif
                            </flux:table.cell>

                            <flux:table.cell class="w-[12%] min-w-0">
                                <x-truncate-tooltip :content="$lead->origin">
                                    <div class="truncate">{{ $lead->origin }}</div>
                                </x-truncate-tooltip>
                            </flux:table.cell>

                            <flux:table.cell class="w-[23%] min-w-0">
                                @php($addressParts = $lead->shortAddressParts())
                                <x-truncate-tooltip :content="trim($addressParts['city'].' | '.$addressParts['street'], ' |')">
                                    <div class="truncate">
                                        @if($addressParts['city'] !== '')
                                            <span class="font-semibold">{{ $addressParts['city'] }}</span>
                                            @if($addressParts['street'] !== '')
                                                <span class="text-zinc-400 dark:text-zinc-500">|</span>
                                            @endif
                                        @endif
                                        {{ $addressParts['street'] }}
                                    </div>
                                </x-truncate-tooltip>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
    </x-index-table>
    @endisland
    <livewire:leads.lead-create />
</div>
