<div class="grid grid-cols-5 gap-4 xl:relative sm:px-6 lg:max-w-7xl" wire:key="estimate-show-{{ $estimate->id }}">
    <div class="col-span-5 space-y-4 lg:col-span-2 lg:h-32">
        {{-- ESTIMATE DETAILS --}}
        @include('livewire.estimates.estimate-details', [
            'estimate' => $estimate,
            'client' => $estimate->client,
            'project' => $estimate->project,
        ])

        {{-- PAYMENT SCHEDULE --}}
        @if($estimate->payments)
            <flux:card class="space-y-2">
                <flux:accordion transition>
                    <flux:accordion.item>
                        <flux:accordion.heading>
                            <flux:heading size="lg">Payment Schedule</flux:heading>
                        </flux:accordion.heading>

                        <flux:accordion.content>
                            <flux:separator variant="subtle" />
                            <flux:table>
                                <flux:table.columns>
                                    <flux:table.column></flux:table.column>
                                    <flux:table.column>Amount</flux:table.column>
                                </flux:table.columns>

                                <flux:table.rows>
                                    @foreach($estimate->payments as $payment)
                                        <flux:table.row>
                                            <flux:table.cell>{{$payment['description']}}</flux:table.cell>
                                            <flux:table.cell>{{$loop->last && $payment['amount'] == '' ? 'Balance' : money($payment['amount'])}}</flux:table.cell>
                                        </flux:table.row>
                                    @endforeach
                                </flux:table.rows>
                            </flux:table>
                        </flux:accordion.content>
                    </flux:accordion.item>
                </flux:accordion>
            </flux:card>
        @endif

        {{-- PROJECT FINANCES --}}
        @if($estimate->options)
            @cannot('update', $estimate)
                {{-- CLIENT-FRIENDLY PROJECT FINANCES --}}
                <x-client-finances
                    :project="$estimate->project"
                    :showReimbursementDownload="true"
                />
            @else
                <livewire:projects.project-finances :project="$estimate->project" lazy />
            @endcannot
        @endif
    </div>

    <div class="col-span-5 space-y-2 lg:col-span-3 lg:col-start-3">
        {{-- SECTIONS --}}
        @cannot('update', $estimate)
            <div class="space-y-2">
                @foreach($sections as $index => $section)
                    <flux:card class="space-y-2">
                        {{-- HEADING --}}
                        <flux:heading>
                            <span class="text-base font-semibold">{{ $section['name'] ?: 'Unnamed Section' }}</span>
                        </flux:heading>

                        <flux:accordion transition>
                            <flux:accordion.item :expanded="true">
                                <flux:accordion.heading>
                                    {{-- only when accordion closed --}}
                                </flux:accordion.heading>

                                <flux:accordion.content>
                                    <flux:separator variant="subtle"/>
                                    <flux:table>
                                        <flux:table.columns>
                                            <flux:table.column class="w-6"></flux:table.column>
                                            <flux:table.column class="w-1/3">Item</flux:table.column>
                                            <flux:table.column>Quantity</flux:table.column>
                                            <flux:table.column>Unit</flux:table.column>
                                            <flux:table.column>Cost</flux:table.column>
                                            <flux:table.column>Total</flux:table.column>
                                        </flux:table.columns>

                                        <flux:table.rows>
                                            @foreach($estimate->estimate_sections->find($section['id'])->estimate_line_items as $line_item_index => $line_item)
                                                <flux:table.row>
                                                    <flux:table.cell>{{$index + 1}}.{{$line_item_index + 1}}</flux:table.cell>
                                                    <flux:table.cell variant="strong">
                                                        <b>{{$line_item->name}}</b>
                                                        <br>
                                                        <i>{{$line_item->category}}@if($line_item->sub_category)/@endif{{$line_item->sub_category}}</i>
                                                    </flux:table.cell>
                                                    <flux:table.cell>{{$line_item->unit_type !== 'no_unit' ? $line_item->quantity : ''}}</flux:table.cell>
                                                    <flux:table.cell>{{$line_item->unit_type !== 'no_unit' ? $line_item->unit_type : ''}}</flux:table.cell>
                                                    <flux:table.cell>{{$line_item->unit_type !== 'no_unit' ? money($line_item->cost) : ''}}</flux:table.cell>
                                                    <flux:table.cell variant="strong">{{money($line_item->total)}}</flux:table.cell>
                                                </flux:table.row>
                                            @endforeach
                                        </flux:table.rows>
                                    </flux:table>
                                </flux:accordion.content>
                            </flux:accordion.item>
                        </flux:accordion>

                        {{-- FOOTER --}}
                        <flux:separator variant="subtle"/>
                        <div class="flex justify-between">
                            <div></div>
                            <flux:button disabled>
                                {{money($section['total'])}}
                            </flux:button>
                        </div>
                    </flux:card>
                @endforeach
            </div>
        @endcannot
        @can('update', $estimate)
            <div x-sort="$wire.sort_sections($key, $position)" class="space-y-2">
                @foreach($sections as $index => $section)
                    <flux:card class="space-y-2" x-sort:item="{{$section['id']}}" x-bind:key="{{$section['id']}}">
                    {{-- HEADING --}}
                    <flux:heading>
                        <div class="flex justify-between group" x-data="{ editing: false }">
                            <div class="flex items-center gap-2">
                                {{-- Display mode: show name as text with dropdown --}}
                                <template x-if="!editing">
                                    <div class="flex items-center gap-2">
                                        <span class="text-base font-semibold">{{ $section['name'] ?: 'Unnamed Section' }}</span>
                                        <flux:dropdown>
                                            <flux:button size="sm" icon="ellipsis-vertical" variant="ghost"></flux:button>

                                            <flux:menu>
                                                <flux:menu.item icon="pencil" x-on:click="editing = true; $nextTick(() => $refs.nameInput?.focus())">Edit Name</flux:menu.item>
                                                <flux:menu.separator />
                                                <flux:menu.item icon="document-duplicate" wire:click="sectionDuplicate({{$index}})">Duplicate Section</flux:menu.item>
                                                <flux:menu.item icon="arrow-up-on-square" wire:click="$dispatchTo('estimates.estimate-duplicate', 'duplicateToEstimateModal', { section: {{$section['id']}} })">Duplicate to Estimate</flux:menu.item>
                                                <flux:menu.separator />
                                                <flux:menu.item icon="trash" wire:click="sectionDelete({{$index}})" variant="danger">Disable Section</flux:menu.item>
                                            </flux:menu>
                                        </flux:dropdown>
                                    </div>
                                </template>

                                {{-- Edit mode: show input --}}
                                <template x-if="editing">
                                    <flux:input.group>
                                        <flux:input
                                            x-ref="nameInput"
                                            wire:model.defer="sections.{{$index}}.name"
                                            x-on:keydown.enter="$wire.sectionUpdate({{$index}}); editing = false"
                                            x-on:keydown.escape="editing = false"
                                            type="text"
                                            required
                                            placeholder="Section Name"
                                            kbd="Enter"
                                        />
                                        <flux:button icon="check" variant="primary" x-on:click="$wire.sectionUpdate({{$index}}); editing = false"></flux:button>
                                        <flux:button icon="x-mark" variant="ghost" x-on:click="editing = false"></flux:button>
                                    </flux:input.group>
                                </template>
                            </div>
                            <flux:icon.chevron-up-down
                                variant="solid"
                                x-sort:handle
                                class="text-gray-400 opacity-40 group-hover:opacity-90 group-hover:text-gray-600 active:opacity-90 active:text-gray-600"
                            />
                        </div>
                    </flux:heading>

                    <flux:accordion transition>
                        <flux:accordion.item>
                            <flux:accordion.heading>
                                {{-- only when accordion closed --}}
                            </flux:accordion.heading>

                            <flux:accordion.content>
                                <flux:separator variant="subtle"/>
                                    <flux:table>
                                        <flux:table.columns>
                                            <flux:table.column class="w-6"></flux:table.column>
                                            <flux:table.column class="w-1/3">Item</flux:table.column>
                                            <flux:table.column>Quantity</flux:table.column>
                                            <flux:table.column>Unit</flux:table.column>
                                            <flux:table.column>Cost</flux:table.column>
                                            <flux:table.column>Total</flux:table.column>
                                        </flux:table.columns>

                                        <flux:table.rows x-sort="$wire.sort_line_item($key, $position)">
                                            @foreach($estimate->estimate_sections->find($section['id'])->estimate_line_items as $line_item_index => $line_item)
                                                <div>
                                                    <flux:table.row x-sort:item="{{$line_item->id}}" x-bind:key="$line_item->id">
                                                        <flux:table.cell x-sort:handle>{{$index + 1}}.{{$line_item_index + 1}}</flux:table.cell>
                                                        <flux:table.cell variant="strong">
                                                            <a
                                                                class="cursor-pointer"
                                                                wire:click="$dispatchTo('line-items.estimate-line-item-create', 'editOnEstimate', { estimate_line_item_id: {{$line_item->id}} })"
                                                                >
                                                                <b>{{$line_item->name}}</b>
                                                            </a>
                                                            <br>
                                                            <i>{{$line_item->category}}@if($line_item->sub_category)/@endif{{$line_item->sub_category}}</i>
                                                        </flux:table.cell>
                                                        <flux:table.cell>{{$line_item->unit_type !== 'no_unit' ? $line_item->quantity : ''}}</flux:table.cell>
                                                        <flux:table.cell>{{$line_item->unit_type !== 'no_unit' ? $line_item->unit_type : ''}}</flux:table.cell>
                                                        <flux:table.cell>{{$line_item->unit_type !== 'no_unit' ? money($line_item->cost) : ''}}</flux:table.cell>
                                                        <flux:table.cell variant="strong">{{money($line_item->total)}}</flux:table.cell>
                                                    </flux:table.row>
                                                </div>
                                            @endforeach
                                        </flux:table.rows>
                                    </flux:table>
                            </flux:accordion.content>
                        </flux:accordion.item>
                    </flux:accordion>

                    {{-- FOOTER --}}
                    <flux:separator variant="subtle"/>
                    <div class="flex justify-between">
                        <flux:button.group>
                            <flux:button
                                wire:click="$dispatchTo('line-items.estimate-line-item-create', 'addToEstimate', { section_id: {{$section['id']}} })"
                                icon="plus"
                                >
                                Item
                            </flux:button>

                            @if(!empty($trashedLineItems[$section['id']]))
                                <flux:dropdown>
                                    <flux:button icon="arrow-path"></flux:button>

                                    <flux:menu>
                                        <flux:menu.heading>Restore Deleted Items</flux:menu.heading>
                                        @foreach($trashedLineItems[$section['id']] as $trashedLineItem)
                                            <flux:menu.item wire:click="lineItemRestore({{ $trashedLineItem['id'] }})">
                                                {{ $trashedLineItem['name'] }} — {{ money($trashedLineItem['total']) }}
                                            </flux:menu.item>
                                        @endforeach
                                    </flux:menu>
                                </flux:dropdown>
                            @endif
                        </flux:button.group>
                        <flux:button disabled>
                            {{money($section['total'])}}
                        </flux:button>
                    </div>
                </flux:card>
            @endforeach
        </div>

        <flux:button.group>
            <flux:button
                wire:click="sectionAdd"
                variant="primary"
                icon="plus"
                >
                Section
            </flux:button>

            @if(count($trashedSections) > 0)
                <flux:dropdown>
                    <flux:button icon="arrow-path"></flux:button>

                    <flux:menu>
                        <flux:menu.heading>Restore Deleted Section</flux:menu.heading>
                        @foreach($trashedSections as $trashedSection)
                            <flux:menu.item wire:click="sectionRestore({{ $trashedSection['id'] }})">
                                {{ $trashedSection['name'] ?? 'Unnamed Section' }} — {{ money($trashedSection['total']) }}
                            </flux:menu.item>
                        @endforeach
                    </flux:menu>
                </flux:dropdown>
            @endif
        </flux:button.group>

        <livewire:line-items.estimate-line-item-create :estimate="$estimate"/>
        <livewire:estimates.estimate-email />
        @endcan
    </div>
</div>
