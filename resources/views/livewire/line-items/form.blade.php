<flux:modal name="line_item_form_modal" class="space-y-2 min-w-96">
    <div class="flex justify-between">
        <flux:heading size="lg">{{$view_text['card_title']}}</flux:heading>
    </div>

    <flux:separator variant="subtle" />

    <form
        x-data="{ line_title: @entangle('form.name'), existing_line_item_id: @entangle('existing_line_item_id') }"
        wire:submit="{{$view_text['form_submit']}}"
        class="grid gap-6"
        >
        <flux:input wire:model.live.debounce.500ms="form.name" label="Item Title" placeholder="Item Title" x-bind:disabled="existing_line_item_id"/>

        <div
            x-show="line_title && existing_line_item_id !== 'NEW'"
            x-transition
            >
            <flux:fieldset>
                <flux:radio.group wire:model.live="existing_line_item_id" label="Existing Line Items" variant="cards" class="flex-col" :indicator="false">
                    @foreach($this->line_items as $line_item)
                        <flux:radio value="{{$line_item->id}}" label="{{$line_item->name}}" description="{{$line_item->desc}}" />
                    @endforeach

                    <flux:radio value="NEW" label="Create New Line Item" description="" />
                </flux:radio.group>
            </flux:fieldset>
        </div>

        <div
            x-show="existing_line_item_id === 'NEW'"
            x-transition
            class="space-y-2"
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
                {{-- placeholder="Category"  --}}
                <flux:input wire:model="form.category" label="Category" />

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

            <div class="flex space-x-2 sticky bottom-0">
                <flux:spacer />

                <flux:button type="submit" variant="primary">{{$view_text['button_text']}}</flux:button>
            </div>
        </div>
    </form>
</flux:modal>
