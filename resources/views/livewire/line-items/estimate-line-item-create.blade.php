<flux:modal name="estimate_line_item_form_modal" class="space-y-2 min-w-96">
    <div class="flex justify-between">
        <flux:heading size="lg">{{$view_text['card_title']}}</flux:heading>
    </div>

    <flux:separator variant="subtle" />

    <form wire:submit="{{$view_text['form_submit']}}" class="grid gap-6">
        <div
            x-data="{ edit_line_item: @entangle('edit_line_item') }"
            >
            <flux:select variant="listbox" wire:model.live="line_item_id" label="Line Item" searchable placeholder="Choose Line Item..." x-bind:disabled="edit_line_item">
                @foreach($this->line_items as $line_item)
                    <flux:option value="{{$line_item->id}}"><div>{{$line_item->name}} <br> <i class="font-normal">{{$line_item->category . ' / ' . $line_item->sub_category}}</i></div></flux:option>
                @endforeach
            </flux:select>
        </div>

        <div
            x-data="{ open: @entangle('line_item_id') }"
            x-show="open"
            x-transition
            class="my-4 space-y-4"
            >
            {{-- DESCRIPTION --}}
            <flux:textarea
                wire:model="form.desc"
                label="Description"
                rows="auto"
                resize="none"
                placeholder=""
            />

            {{-- NOTES --}}
            <flux:textarea
                wire:model="form.notes"
                label="Notes"
                rows="auto"
                resize="none"
                placeholder=""
            />

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                {{-- CATEGORY --}}
                <flux:input wire:model="form.category" label="Category" placeholder="Category" />

                {{-- SUB CATEGORY --}}
                <flux:input wire:model="form.sub_category" label="Sub Category" />
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                {{-- UNIT TYPE --}}
                <flux:select wire:model="form.unit_type" label="Unit Type" placeholder="Choose unit type...">
                    @include('livewire.line-items._unit_type_options')
                </flux:select>

                {{-- COST --}}
                <flux:input
                    wire:model.live.debounce.500ms="form.cost"
                    label="Amount"
                    type="number"
                    inputmode="decimal"
                    pattern="[0-9]*"
                    step="0.01"
                    placeholder="00.00"
                />
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                {{-- QUANTITY --}}
                <flux:input
                    wire:model.live.debounce.500ms="form.quantity"
                    label="Quantity"
                    type="number"
                    inputmode="numeric"
                    step=".1"
                    min=".1"
                    placeholder="1"
                />

                {{-- TOTAL --}}
                <flux:input
                    wire:model.live.debounce.500ms="form.total"
                    label="Total"
                    disabled
                    type="number"
                    inputmode="decimal"
                />
            </div>
        </div>

        <div class="flex space-x-2 sticky bottom-0">
            <flux:spacer />
            <div
                x-data="{ estimate_line_item: @entangle('estimate_line_item') }"
                x-show="estimate_line_item"
                >
                <flux:button wire:click="removeFromEstimate" variant="danger">Remove</flux:button>
            </div>
            <flux:button type="submit" variant="primary">{{$view_text['button_text']}}</flux:button>
        </div>
    </form>
</flux:modal>
