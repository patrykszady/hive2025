<x-form-modal name="expense_splits_form_modal" title="Expense Splits">
    <form id="expense_splits_form_modal_form" wire:submit="{{$view_text['form_submit']}}" class="space-y-4">
        @foreach ($expense_splits as $index => $split)
            {{-- Keyed by the persisted id where there is one: removeSplit()
                 reindexes the array, so position alone let the morph carry
                 input state onto the wrong split card. --}}
            <flux:card wire:key="split-{{ $split['id'] ?? 'new-'.$index }}" class="space-y-6">
                <div class="flex justify-between">
                    <flux:heading size="lg">Split {{$index + 1}}</flux:heading>
                    {{-- action button to the right --}}
                    {{-- cannot remove if splits is equal to 2 or less --}}
                    @if($loop->count > 2)
                        <flux:button.group>
                            <flux:button size="sm" wire:click="$dispatch('addSplit')">Add</flux:button>
                            <flux:button size="sm" wire:click="removeSplit({{$index}})">Remove</flux:button>
                        </flux:button.group>
                    @else
                        <flux:button size="sm" wire:click="$dispatch('addSplit')">Add Split</flux:button>
                    @endif
                </div>

                @if ($expense_line_items)
                    <div>
                        <flux:table class="table-fixed w-full">
                            <flux:table.columns>
                                <flux:table.column class="w-14"></flux:table.column>
                                <flux:table.column class="w-[49%]">Desc</flux:table.column>
                                <flux:table.column class="w-[15%]" align="end">Price</flux:table.column>
                                <flux:table.column class="w-[10%]" align="end">Qty</flux:table.column>
                                <flux:table.column class="w-[20%]" align="end">Total</flux:table.column>
                            </flux:table.columns>

                            <flux:table.rows>
                            @if(!is_array($expense_line_items))
                                @foreach($expense_line_items->items as $line_item_index => $line_item)
                                    <flux:table.row class="transition-colors duration-150 {{ (isset($split['items']) && isset($split['items'][$line_item_index]['checkbox']) && $split['items'][$line_item_index]['checkbox'] == true) ? 'bg-indigo-50 dark:bg-indigo-900/10' : '' }}">
                                        <flux:table.cell class="text-center">
                                            <flux:checkbox
                                                wire:model.live="expense_splits.{{$index}}.items.{{$line_item_index}}.checkbox"
                                                :disabled="isset($line_item->split_index) && $line_item->split_index !== null && $line_item->split_index != $index"
                                                class="{{ (isset($line_item->split_index) && $line_item->split_index !== null && $line_item->split_index != $index) ? 'opacity-50 cursor-not-allowed' : '' }}"
                                            />
                                        </flux:table.cell>
                                        <flux:table.cell class="max-w-0">
                                            <span class="block truncate transition-opacity transition-colors duration-150 {{ (isset($line_item->split_index) && $line_item->split_index !== null && $line_item->split_index != $index) ? 'text-gray-300 line-through opacity-50' : '' }}">{{ $line_item->Description }}</span>
                                        </flux:table.cell>
                                        <flux:table.cell align="end">
                                            <span class="transition-opacity transition-colors duration-150 {{ (isset($line_item->split_index) && $line_item->split_index !== null && $line_item->split_index != $index) ? 'text-gray-300 line-through opacity-50' : '' }}">{{ money($line_item->Price) }}</span>
                                        </flux:table.cell>
                                        <flux:table.cell align="end">
                                            <span class="transition-opacity transition-colors duration-150 {{ (isset($line_item->split_index) && $line_item->split_index !== null && $line_item->split_index != $index) ? 'text-gray-300 line-through opacity-50' : '' }}">{{$line_item->Quantity}}</span>
                                        </flux:table.cell>
                                        <flux:table.cell variant="strong" class="whitespace-nowrap" align="end">
                                            <span class="transition-opacity transition-colors duration-150 {{ (isset($line_item->split_index) && $line_item->split_index !== null && $line_item->split_index != $index) ? 'text-gray-300 line-through opacity-50' : '' }}">{{ money($line_item->TotalPrice) }}</span>
                                        </flux:table.cell>
                                    </flux:table.row>
                                @endforeach
                            @endif
                            </flux:table.rows>
                        </flux:table>
                    </div>
                @endif

                <flux:separator variant="subtle" />

                {{-- SPLIT AMOUNT --}}
                <flux:input
                    wire:model.live.debounce.500ms="expense_splits.{{ $index }}.amount"
                    {{-- x-bind:disabled="{{$expense_line_items ? TRUE : FALSE}}" --}}
                    inputmode="decimal"
                    pattern="[0-9]*"
                    step="0.01"
                    label="Amount"
                    type="number"
                    size="lg"
                    placeholder="123.45"
                />

                {{-- SPLIT PROJECT --}}
                <flux:field>
                    <flux:label>Project</flux:label>
                    <flux:select wire:model.live="expense_splits.{{ $index }}.project_id" variant="listbox" searchable placeholder="Choose project...">
                        @foreach($projects as $project)
                            <flux:select.option value="{{$project->id}}"><div>{{ $project->short_address }} <br> <i class="font-normal">{{$project->project_name}}</i></div></flux:select.option>
                        @endforeach

                        <flux:select.option disabled>--------------</flux:select.option>

                        @foreach($distributions as $distribution)
                            <flux:select.option value="D:{{$distribution->id}}">{{$distribution->name}}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:error name="expense_splits.{{ $index }}.project_id" />
                </flux:field>

                {{-- REIMBURSEMNT --}}
                <flux:field>
                    <flux:radio.group wire:model.live="expense_splits.{{ $index }}.reimbursment" label="Reimbursment" variant="segmented">
                        <flux:radio value="None" label="None" />
                        <flux:radio value="Client" label="Client" />
                    </flux:radio.group>

                    <flux:error name="expense_splits.{{ $index }}.reimbursment" />
                </flux:field>

                {{-- NOTES --}}
                <flux:textarea
                    wire:model.live.debounce.500ms="expense_splits.{{ $index }}.note"
                    label="Notes"
                    rows="auto"
                    resize="none"
                    placeholder="Notes"
                />
            </flux:card>
        @endforeach

        <flux:error name="expense_splits_total_match" />
    </form>

    <x-slot name="footer">
        <flux:button disabled variant="primary" icon="currency-dollar">
            {{money($this->splits_sum)}}
        </flux:button>

        <flux:spacer />

        <flux:button type="submit" form="expense_splits_form_modal_form" variant="primary">{{$view_text['button_text']}}</flux:button>
    </x-slot>
</x-form-modal>
