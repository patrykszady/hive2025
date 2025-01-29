<flux:modal name="expense_splits_form_modal" class="space-y-2">
    <flux:heading size="lg">Expense Splits</flux:heading>
    <flux:separator variant="subtle" />

    <form wire:submit="{{$view_text['form_submit']}}" class="grid gap-6">
        @foreach ($expense_splits as $index => $split)
            <flux:card class="space-y-6">
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

                <flux:table>
                    <flux:columns>
                        <flux:column></flux:column>
                        <flux:column>Desc</flux:column>
                        <flux:column>Price</flux:column>
                        <flux:column>Qty</flux:column>
                        <flux:column>Total</flux:column>
                    </flux:columns>

                    <flux:rows>
                        @if(!is_array($expense_line_items))
                            @foreach($expense_line_items->items as $line_item_index => $line_item)
                                <flux:row class="{{$split['items'] && $split['items'][$line_item_index]['checkbox'] == TRUE ? 'bg-gray-50' : ''}}">
                                    <flux:cell>
                                        <flux:checkbox
                                            wire:model.live="expense_splits.{{$index}}.items.{{$line_item_index}}.checkbox"
                                            :disabled="isset($line_item->split_index) ? $line_item->split_index != $index : FALSE"
                                            />
                                    </flux:cell>
                                    <flux:cell>{{Str::limit($line_item->Description, 20)}}</flux:cell>
                                    <flux:cell>{{money($line_item->Price)}}</flux:cell>
                                    <flux:cell>{{$line_item->Quantity}}</flux:cell>
                                    <flux:cell variant="strong" class="{{isset($line_item->split_index) ? $line_item->split_index != $index || $line_item->split_index == NULL ? 'text-gray-200' : 'text-gray-500' : 'text-gray-500'}} whitespace-nowrap">{{money($line_item->TotalPrice)}}</flux:cell>
                                </flux:row>
                            @endforeach
                        @endif
                    </flux:rows>
                </flux:table>

                <flux:separator variant="subtle" />

                {{-- SPLIT AMOUNT --}}
                <flux:input
                    wire:model.live="expense_splits.{{ $index }}.amount"
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
                            <flux:option value="{{$project->id}}"><div>{{$project->address}} <br> <i class="font-normal">{{$project->project_name}}</i></div></flux:option>
                        @endforeach

                        <flux:option disabled>--------------</flux:option>

                        @foreach($distributions as $distribution)
                            <flux:option value="D:{{$distribution->id}}">{{$distribution->name}}</flux:option>
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

        {{-- FOOTER --}}
        <div class="flex justify-between sticky bottom-0">
            <flux:button disabled variant="primary" icon="currency-dollar">
                {{money($this->splits_sum)}}
            </flux:button>

            <flux:button type="submit" variant="primary">{{$view_text['button_text']}}</flux:button>
        </div>
        <flux:error name="expense_splits_total_match" />
    </form>
</flux:modal>
