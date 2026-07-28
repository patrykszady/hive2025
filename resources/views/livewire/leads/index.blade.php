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
                :page-size="\App\Livewire\Leads\LeadsIndex::placeholderRows()"
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
                        <flux:menu.separator />
                        <flux:menu.item
                            variant="danger"
                            icon="trash"
                            wire:click="confirmBulkDelete"
                            x-bind:disabled="$wire.selected.length === 0"
                        >
                            Delete
                        </flux:menu.item>
                    </flux:menu>
                </flux:dropdown>
            </div>
        </x-slot:badge>
        <x-slot:actions>
            @include('livewire.leads.partials.index-actions')
        </x-slot:actions>

            <flux:table
                wire:loading.class.delay.shortest="opacity-50 pointer-events-none"
                wire:target="bulkSetStatus, bulkDelete"
                class="transition-opacity duration-150 index-table compact-table [:where(&)]:p-0 [:where(&)]:space-y-0"
            >
                <flux:table.columns>
                    <flux:table.column class="w-12 !px-3" x-show="bulkMode" x-cloak>
                        <span class="sr-only">Select</span>
                    </flux:table.column>
                    <flux:table.column sortable :sorted="$sortBy === 'date'" :direction="$sortDirection" wire:click="sort('date')" class="w-[14%]">Date</flux:table.column>
                    <flux:table.column class="w-[17%] min-w-0">Client</flux:table.column>
                    <flux:table.column class="w-[12%]">Status</flux:table.column>
                    <flux:table.column class="w-[10%]">Last</flux:table.column>
                    <flux:table.column class="w-[16%] min-w-0">Origin</flux:table.column>
                    <flux:table.column class="w-[31%] min-w-0">Address</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($this->leads as $lead)
                        <flux:table.row :key="$lead->id">
                            <flux:table.cell class="w-12 !px-3" x-show="bulkMode" x-cloak>
                                <flux:checkbox size="sm" wire:model="selected" value="{{ $lead->id }}" />
                            </flux:table.cell>
                            <flux:table.cell
                                wire:click="$dispatchTo('leads.lead-create', 'editLead', { lead: {{$lead->id}}})"
                                variant="strong"
                                class="cursor-pointer w-[14%] whitespace-nowrap hover:text-indigo-600 dark:hover:text-indigo-400"
                                >
                                {{-- Stored UTC (now() at intake) — same reason as Last below. --}}
                                {{ $lead->date->copy()->setTimezone(browser_timezone())->format('m/d/y') }}
                            </flux:table.cell>

                            <flux:table.cell class="w-[17%] min-w-0">
                                <x-table-link
                                    :href="($leadClient = $this->clientForLead($lead)) ? route('clients.show', $leadClient) : null"
                                    :label="$lead->lead_data['name'] ?? ''"
                                />
                            </flux:table.cell>

                            <flux:table.cell class="w-[12%]">
                                <x-status-select
                                    :value="$lead->last_status?->title ?? ''"
                                    :options="\App\Models\Lead::selectableStatuses()"
                                    method="updateLeadStatus"
                                    :model-id="$lead->id"
                                />
                            </flux:table.cell>

                            <flux:table.cell class="w-[10%] whitespace-nowrap">
                                @if($lead->last_status && !in_array($lead->last_status->title, ['New', 'Won', 'Lost', 'Not a Fit']))
                                    {{-- Stored UTC; show it in the viewer's/vendor's zone or a
                                         late-evening reply reads as the next day. --}}
                                    {{ $lead->last_status->created_at->copy()->setTimezone(browser_timezone())->format('m/d/y') }}
                                @endif
                            </flux:table.cell>

                            <flux:table.cell class="w-[16%] min-w-0">
                                <x-truncate-tooltip :content="$lead->origin">
                                    <div class="truncate">{{ $lead->origin }}</div>
                                </x-truncate-tooltip>
                            </flux:table.cell>

                            <flux:table.cell class="w-[31%] min-w-0">
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

    {{-- Bulk delete confirmation — same consequences as the single-lead delete
         (leads plus any client records / contacts that exist only for them,
         via Lead::deleteWithOrphans). Lives INSIDE the island: the bulk menu
         triggers from in here, and island-originated updates re-render only
         island content — outside, the modal would never open. Content gated
         so the impact queries run only while the modal is open. --}}
    <flux:modal wire:model.self="showBulkDelete" name="leads-bulk-delete-confirm" class="max-w-md">
        @if($showBulkDelete)
            @php($impact = $this->bulkDeleteImpact)
            <div class="space-y-4">
                <flux:heading size="lg">Delete {{ $impact['count'] }} {{ str('lead')->plural($impact['count']) }}?</flux:heading>

                <ul class="list-disc pl-5 space-y-1 text-sm text-zinc-600 dark:text-zinc-300">
                    <li>The {{ str('lead')->plural($impact['count']) }}, their statuses and message history are removed.</li>

                    @foreach($impact['clients'] as $clientName)
                        <li>The client record <strong>{{ $clientName }}</strong> is removed — it has no projects and no other contacts.</li>
                    @endforeach

                    @foreach($impact['users'] as $userName)
                        <li>The contact <strong>{{ $userName }}</strong> and their portal access are removed — they aren't linked to anything else.</li>
                    @endforeach
                </ul>

                <div class="flex justify-end gap-2 pt-2">
                    <flux:button variant="ghost" wire:click="$set('showBulkDelete', false)">Cancel</flux:button>
                    <flux:button variant="danger" icon="trash" wire:click="bulkDelete">Delete {{ str('Lead')->plural($impact['count']) }}</flux:button>
                </div>
            </div>
        @endif
    </flux:modal>
    @endisland
    <livewire:leads.lead-create />
</div>
