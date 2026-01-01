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

        {{-- PROJECT FIANCES --}}
        @if($estimate->options)
            <livewire:projects.project-finances :project="$estimate->project" lazy />
        @endif
    </div>

    <div class="col-span-5 space-y-2 lg:col-span-3 lg:col-start-3">
        {{-- SECTIONS --}}
        <div x-sort="$wire.sort_sections($key, $position)" class="space-y-2">
            @foreach($sections as $index => $section)
                <flux:card class="space-y-2" x-sort:item="{{$section['id']}}" x-bind:key="{{$section['id']}}">
                    {{-- HEADING --}}
                    <flux:heading>
                        <div class="flex justify-between group">
                            <div class="grid grid-cols-2 gap-4">
                                <flux:input.group>
                                    {{-- on clickaway sectionUpdate --}}
                                    <flux:input
                                        wire:keydown.enter="sectionUpdate({{$index}})"
                                        wire:blur="sectionUpdate({{$index}})"
                                        wire:model.live="sections.{{$index}}.name"
                                        type="text"
                                        required
                                        placeholder="Section Name"
                                        kbd="Enter"
                                    />

                                    <flux:dropdown>
                                        <flux:button icon-trailing="chevron-down"></flux:button>

                                        <flux:menu>
                                            <flux:menu.item wire:click="sectionUpdate({{$index}})">Update Section Name</flux:menu.item>
                                            <flux:menu.separator />
                                            <flux:menu.item wire:click="sectionDuplicate({{$index}})">Duplicate Section</flux:menu.item>
                                            <flux:menu.separator />
                                            {{-- wire:click="sectionDuplicateToEstimate({{$index}})" --}}
                                            <flux:menu.item wire:click="$dispatchTo('estimates.estimate-duplicate', 'duplicateToEstimateModal', { section: {{$section['id']}} })">Duplicate Section to Estimate</flux:menu.item>
                                            <flux:menu.separator />
                                            <flux:menu.item wire:click="sectionDelete({{$index}})" variant="danger">Delete Section</flux:menu.item>
                                        </flux:menu>
                                    </flux:dropdown>
                                </flux:input.group>
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
                                {{-- Show Line Items --}}
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
                                                        {{-- Use the loop index instead of database order --}}
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
                        <flux:button
                            wire:click="$dispatchTo('line-items.estimate-line-item-create', 'addToEstimate', { section_id: {{$section['id']}} })"
                            icon="plus"
                            >
                            Item
                        </flux:button>
                        <flux:button disabled>
                            {{money($section['total'])}}
                        </flux:button>
                    </div>
                </flux:card>
            @endforeach
        </div>

        <flux:button
            wire:click="sectionAdd"
            variant="primary"
            icon="plus"
            >
            Section
        </flux:button>

        <livewire:line-items.estimate-line-item-create :estimate="$estimate"/>
        <livewire:estimates.estimate-email />
    </div>
</div>
