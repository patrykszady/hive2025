<div
    class="grid grid-cols-5 gap-4 xl:relative sm:px-6 lg:max-w-7xl"
    wire:key="estimate-show-{{ $estimate->id }}"
    x-data
    x-on:copy-to-clipboard.window="
        navigator.clipboard.writeText($event.detail.url).catch(() => {
            $flux.toast({ text: 'Failed to copy link.', variant: 'danger' });
        })
    "
>
    <div class="col-span-5 space-y-4 lg:col-span-2 lg:h-32">
        {{-- ESTIMATE DETAILS --}}
        @island(name: 'estimate-details', always: true)
            @include('livewire.estimates.estimate-details', [
                'estimate' => $this->estimate,
                'client' => $this->estimate->client,
                'project' => $this->estimate->project,
                'showChanges' => $this->showChanges,
                'showAllowances' => $this->showAllowances,
            ])
        @endisland

        {{-- PAYMENT SCHEDULE --}}
        @island(name: 'payment-schedule')
            @if($this->estimate->payments)
                <flux:card class="space-y-2">
                    <flux:accordion transition>
                        <flux:accordion.item>
                            <flux:accordion.heading>
                                <flux:heading size="lg">Payment Schedule</flux:heading>
                            </flux:accordion.heading>

                            <flux:accordion.content>
                                <flux:separator variant="subtle" />
                                <flux:table class="max-w-full">
                                    <flux:table.columns>
                                        <flux:table.column></flux:table.column>
                                        <flux:table.column>Amount</flux:table.column>
                                    </flux:table.columns>

                                    <flux:table.rows>
                                        @foreach($this->estimate->payments as $payment)
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
        @endisland

        {{-- PROJECT FINANCES --}}
        @island(name: 'project-finances')
            @if($this->estimate->options)
                @cannot('update', $this->estimate)
                    {{-- CLIENT-FRIENDLY PROJECT FINANCES --}}
                    <x-client-finances
                        :project="$this->estimate->project"
                        :showReimbursementDownload="true"
                    />
                @else
                    <livewire:projects.project-finances :project="$this->estimate->project" lazy />
                @endcannot
            @endif
        @endisland
    </div>

    <div class="col-span-5 space-y-2 lg:col-span-3 lg:col-start-3">
        {{-- SECTIONS --}}
        @cannot('update', $estimate)
            <div class="space-y-2">
                @foreach($sections as $index => $section)
                    <flux:card class="space-y-2 overflow-x-auto">
                        <flux:table class="max-w-full">
                            <flux:table.columns>
                                <flux:table.column class="w-6"></flux:table.column>
                                <flux:table.column class="w-1/3">Item</flux:table.column>
                                <flux:table.column>Quantity</flux:table.column>
                                <flux:table.column>Unit</flux:table.column>
                                <flux:table.column>Cost</flux:table.column>
                                <flux:table.column>Total</flux:table.column>
                            </flux:table.columns>

                            <flux:table.rows>
                                @foreach($estimate->estimate_sections->find($section['id'])->estimate_line_items->sortBy($sortBy, SORT_REGULAR, $sortDirection === 'desc') as $line_item_index => $line_item)
                                    <flux:table.row wire:key="line-item-{{$line_item->id}}" wire:transition>
                                        <flux:table.cell class="align-top !pl-6">{{$index + 1}}.{{$line_item_index + 1}}</flux:table.cell>
                                        <flux:table.cell variant="strong" class="align-top !whitespace-normal break-words">
                                            <div class="flex flex-col min-w-0">
                                                <div class="leading-5 flex items-center gap-x-1">
                                                    <b class="min-w-0">{{$line_item->name}}</b>
                                                    @foreach($this->creditBadges[$line_item->id] ?? [] as $creditNumber)
                                                        <flux:badge size="sm" color="orange" class="shrink-0 whitespace-nowrap">Credit on line item {{ $creditNumber }}</flux:badge>
                                                    @endforeach
                                                </div>
                                                <div class="leading-5"><i>{{$line_item->category}}@if($line_item->sub_category)/@endif{{$line_item->sub_category}}</i></div>
                                            </div>
                                        </flux:table.cell>
                                        <flux:table.cell class="align-top">{{$line_item->unit_type !== 'no_unit' ? $line_item->quantity : ''}}</flux:table.cell>
                                        <flux:table.cell class="align-top">{{$line_item->unit_type !== 'no_unit' ? $line_item->unit_type : ''}}</flux:table.cell>
                                        <flux:table.cell class="align-top">{{$line_item->unit_type !== 'no_unit' ? money($line_item->cost) : ''}}</flux:table.cell>
                                        <flux:table.cell variant="strong" class="align-top">{{money($line_item->total)}}</flux:table.cell>
                                    </flux:table.row>
                                        @if($showAllowances)
                                            @foreach($line_item->allowances as $allowance)
                                                <flux:table.row wire:key="line-item-{{$line_item->id}}-allowance-{{$allowance->id}}">
                                                    <flux:table.cell></flux:table.cell>
                                                    <flux:table.cell colspan="4" class="text-xs italic text-gray-400 !whitespace-normal break-words">Allowance: {{ $allowance->description }}</flux:table.cell>
                                                    <flux:table.cell class="text-xs italic text-gray-400">{{ money($allowance->amount) }}</flux:table.cell>
                                                </flux:table.row>
                                            @endforeach
                                            @if($line_item->allowances->isNotEmpty())
                                                <flux:table.row wire:key="line-item-{{$line_item->id}}-allowance-total">
                                                    <flux:table.cell></flux:table.cell>
                                                    <flux:table.cell colspan="4" class="text-xs font-semibold text-gray-500 text-right">Total + Allowances:</flux:table.cell>
                                                    <flux:table.cell class="text-xs font-semibold text-gray-500">{{ money($line_item->total + $line_item->allowances->sum('amount')) }}</flux:table.cell>
                                                </flux:table.row>
                                            @endif
                                        @endif
                                @endforeach
                            </flux:table.rows>
                        </flux:table>
                    </flux:card>
                @endforeach
            </div>
        @endcannot
        @can('update', $estimate)
            <div wire:sort="sort_sections" class="space-y-2">
                @foreach($sections as $index => $section)
                    <flux:card class="!py-3 !px-6 overflow-hidden" wire:sort:item="{{$section['id']}}" wire:key="section-{{$section['id']}}" wire:transition>
                    <flux:accordion transition>
                        <flux:accordion.item class="!pt-0 !pb-0 !border-b-0">
                            <flux:accordion.heading>
                                <div class="flex items-center gap-2 group" x-data="{ editing: false }" x-on:click.stop>
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
                                                wire:model="sections.{{$index}}.name"
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

                                    <flux:icon.chevron-up-down
                                        variant="solid"
                                        wire:sort:handle
                                        class="size-4 shrink-0 text-gray-400 opacity-40 group-hover:opacity-90 group-hover:text-gray-600 active:opacity-90 active:text-gray-600 cursor-grab active:cursor-grabbing"
                                    />
                                </div>
                            </flux:accordion.heading>

                            <flux:accordion.content>
                                <flux:separator variant="subtle"/>
                                <div class="-mx-6">
                                    <flux:table>
                                        <flux:table.columns>
                                            <flux:table.column class="w-6 !pl-6"></flux:table.column>
                                            <flux:table.column class="w-1/3">Item</flux:table.column>
                                            <flux:table.column>Quantity</flux:table.column>
                                            <flux:table.column>Unit</flux:table.column>
                                            <flux:table.column>Cost</flux:table.column>
                                            <flux:table.column class="!pr-6">Total</flux:table.column>
                                        </flux:table.columns>

                                        <flux:table.rows wire:sort="sort_line_item" wire:sort:group="line-items" wire:sort:group-id="{{ $section['id'] }}">
                                            @php
                                                $activeItems = $estimate->estimate_sections->find($section['id'])->estimate_line_items->sortBy($sortBy, SORT_REGULAR, $sortDirection === 'desc')->values();

                                                if ($showChanges) {
                                                    $trashedItems = \App\Models\EstimateLineItem::onlyTrashed()
                                                        ->where('estimate_id', $estimate->id)
                                                        ->where('section_id', $section['id'])
                                                        ->get();

                                                    // Build merged list: active items in order, trashed items
                                                    // inserted at their original position from the activity log.
                                                    $mergedItems = collect();
                                                    foreach ($activeItems as $item) {
                                                        $mergedItems->push($item);
                                                    }

                                                    $insertOffset = 0;
                                                    foreach ($trashedItems->sortBy(fn ($t) => $recentChanges['line_items'][$t->id]['original_order'] ?? PHP_INT_MAX) as $trashed) {
                                                        $originalOrder = $recentChanges['line_items'][$trashed->id]['original_order'] ?? null;
                                                        if ($originalOrder !== null) {
                                                            $insertAt = min($originalOrder + $insertOffset, $mergedItems->count());
                                                            $mergedItems->splice($insertAt, 0, [$trashed]);
                                                            $insertOffset++;
                                                        } else {
                                                            $mergedItems->push($trashed);
                                                        }
                                                    }
                                                } else {
                                                    $mergedItems = $activeItems;
                                                }

                                                $activeNumber = 0;
                                            @endphp
                                            @foreach($mergedItems as $line_item)
                                                @php
                                                    $isTrashed = $line_item->trashed();
                                                    $liChanged = $showChanges ? ($recentChanges['line_items'][$line_item->id] ?? null) : null;
                                                    if (! $isTrashed) {
                                                        $activeNumber++;
                                                    }
                                                @endphp

                                                @if($isTrashed)
                                                    <flux:table.row wire:key="trashed-line-item-{{$line_item->id}}" class="opacity-45 line-through bg-red-50!">
                                                        <flux:table.cell class="!pl-6"></flux:table.cell>
                                                        <flux:table.cell variant="strong" class="align-top">
                                                            <div class="flex flex-col">
                                                                <div class="leading-5">
                                                                    <b>{{$line_item->name}}</b>
                                                                    <flux:badge size="sm" color="red" class="ml-1 no-underline!">Removed</flux:badge>
                                                                </div>
                                                                <div class="leading-5"><i>{{$line_item->category}}@if($line_item->sub_category)/@endif{{$line_item->sub_category}}</i></div>
                                                            </div>
                                                        </flux:table.cell>
                                                        <flux:table.cell></flux:table.cell>
                                                        <flux:table.cell></flux:table.cell>
                                                        <flux:table.cell></flux:table.cell>
                                                        <flux:table.cell variant="strong" class="align-top !pr-6">{{money($line_item->total)}}</flux:table.cell>
                                                    </flux:table.row>
                                                @else
                                                <flux:table.row wire:sort:item="{{$line_item->id}}" wire:key="line-item-{{$line_item->id}}" wire:transition :class="$liChanged ? 'bg-yellow-50!' : ''">
                                                    <flux:table.cell wire:sort:handle class="cursor-grab active:cursor-grabbing align-top !pl-16">{{$index + 1}}.{{$activeNumber}}</flux:table.cell>
                                                        <flux:table.cell variant="strong" class="align-top !whitespace-normal break-words">
                                                            <div class="flex flex-col min-w-0">
                                                                <div class="leading-5 flex items-center gap-x-1">
                                                                    <a
                                                                        class="cursor-pointer min-w-0"
                                                                        wire:click="$dispatchTo('line-items.estimate-line-item-create', 'editOnEstimate', { estimate_line_item_id: {{$line_item->id}} })"
                                                                        >
                                                                        <b>{{$line_item->name}}</b>
                                                                    </a>
                                                                    @foreach($this->creditBadges[$line_item->id] ?? [] as $creditNumber)
                                                                        <flux:badge size="sm" color="orange" class="shrink-0 whitespace-nowrap">Credit on line item {{ $creditNumber }}</flux:badge>
                                                                    @endforeach
                                                                    @if($liChanged)
                                                                        <flux:badge size="sm" color="amber" class="ml-1">{{ ucfirst($liChanged['event']) }}</flux:badge>
                                                                    @endif
                                                                </div>
                                                                <div class="leading-5"><i>{{$line_item->category}}@if($line_item->sub_category)/@endif{{$line_item->sub_category}}</i></div>
                                                            </div>
                                                        </flux:table.cell>
                                                        <flux:table.cell class="align-top">{{$line_item->unit_type !== 'no_unit' ? $line_item->quantity : ''}}</flux:table.cell>
                                                        <flux:table.cell class="align-top">{{$line_item->unit_type !== 'no_unit' ? $line_item->unit_type : ''}}</flux:table.cell>
                                                        <flux:table.cell class="align-top">{{$line_item->unit_type !== 'no_unit' ? money($line_item->cost) : ''}}</flux:table.cell>
                                                    <flux:table.cell variant="strong" class="align-top !pr-6">{{money($line_item->total)}}</flux:table.cell>
                                                </flux:table.row>
                                                @if($showAllowances)
                                                        @foreach($line_item->allowances as $allowance)
                                                            <flux:table.row wire:key="line-item-{{$line_item->id}}-allowance-{{$allowance->id}}" :class="$liChanged ? 'bg-yellow-50!' : ''">
                                                                <flux:table.cell class="!pl-16"></flux:table.cell>
                                                                <flux:table.cell colspan="4" class="text-xs italic text-gray-400 !whitespace-normal break-words">Allowance: {{ $allowance->description }}</flux:table.cell>
                                                                <flux:table.cell class="text-xs italic text-gray-400 !pr-6">{{ money($allowance->amount) }}</flux:table.cell>
                                                            </flux:table.row>
                                                        @endforeach
                                                        @if($line_item->allowances->isNotEmpty())
                                                            <flux:table.row wire:key="line-item-{{$line_item->id}}-allowance-total" :class="$liChanged ? 'bg-yellow-50!' : ''">
                                                                <flux:table.cell class="!pl-16"></flux:table.cell>
                                                                <flux:table.cell colspan="4" class="text-xs font-semibold text-gray-500 text-right">Total + Allowances:</flux:table.cell>
                                                                <flux:table.cell class="text-xs font-semibold text-gray-500 !pr-6">{{ money($line_item->total + $line_item->allowances->sum('amount')) }}</flux:table.cell>
                                                            </flux:table.row>
                                                        @endif
                                                    @endif
                                                @endif
                                            @endforeach
                                        </flux:table.rows>
                                        @php
                                            $adminSectionAllowanceTotal = $estimate->estimate_sections->find($section['id'])->estimate_line_items->sum(fn ($li) => $li->allowances->sum('amount'));
                                        @endphp
                                        <tfoot>
                                            <tr>
                                                <td colspan="6" class="!p-0 border-none">
                                                    <div class="w-full">
                                                        <flux:separator variant="subtle" />
                                                        <flux:separator variant="subtle" class="-mt-2" />
                                                    </div>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td colspan="6" class="!p-0 border-none">
                                                    <div class="flex items-center w-full">
                                                        <div class="flex items-center gap-2">
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
                                                        </div>
                                                        <div class="flex-1"></div>
                                                        <div class="flex items-center gap-2">
                                                            <span class="text-sm font-semibold text-zinc-800 dark:text-white text-right pr-3 py-0.5">Total:</span>
                                                            <span class="text-sm font-semibold text-zinc-800 dark:text-white py-0.5 ps-3 pe-4">{{money($section['total'])}}</span>
                                                        </div>
                                                    </div>
                                                </td>
                                            </tr>
                                            @if($showAllowances && $adminSectionAllowanceTotal > 0)
                                                    <tr>
                                                        <td colspan="5" class="text-xs italic text-gray-400 text-right pr-3 py-0.5">Allowances:</td>
                                                        <td class="text-xs italic text-gray-400 py-0.5 ps-3 pe-4">{{ money($adminSectionAllowanceTotal) }}</td>
                                                    </tr>
                                                    <tr>
                                                        <td colspan="5" class="text-xs font-semibold text-gray-500 text-right pr-3 pt-1 pb-0.5"><span class="border-t border-gray-200 pt-1">Total + Allowances:</span></td>
                                                        <td class="text-xs font-semibold text-gray-500 pt-1 pb-0.5 ps-3 pe-4 border-t border-gray-200">{{ money($section['total'] + $adminSectionAllowanceTotal) }}</td>
                                                    </tr>
                                            @endif
                                        </tfoot>
                                    </flux:table>
                                </div>
                            </flux:accordion.content>
                        </flux:accordion.item>
                    </flux:accordion>
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

            <flux:button
                wire:click="$dispatchTo('estimates.estimate-a-i-generator', 'openAIGenerator')"
                variant="filled"
                icon="sparkles"
                >
                AI Generate
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
        <livewire:estimates.estimate-a-i-generator :estimate="$estimate"/>
        <livewire:estimates.estimate-email />
        @endcan
    </div>
</div>
