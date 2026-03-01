<x-form-modal name="estimate_line_item_form_modal" :title="$view_text['card_title']">
    <form id="estimate_line_item_form_modal_form" wire:submit="{{$view_text['form_submit']}}" class="space-y-4">
        <div x-data="{ edit_line_item: @entangle('edit_line_item') }">
            <flux:select variant="listbox" wire:model.live="line_item_id" label="Line Item" searchable placeholder="Choose Line Item..." x-bind:disabled="edit_line_item">
                @foreach($this->line_items as $line_item)
                    <flux:select.option value="{{$line_item->id}}"><div>{{$line_item->name}} <br> <i class="font-normal">{{$line_item->category . ' / ' . $line_item->sub_category}}</i></div></flux:select.option>
                @endforeach
            </flux:select>
        </div>

        <div
            x-data="{ open: @entangle('line_item_id') }"
            x-show="open"
            x-transition
            class="space-y-4"
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

            <div class="flex flex-col sm:flex-row gap-4">
                <div class="flex-1 min-w-0">
                    <flux:input wire:model="form.category" label="Category" placeholder="Category" />
                </div>
                <div class="flex-1 min-w-0">
                    <flux:input wire:model="form.sub_category" label="Sub Category" />
                </div>
            </div>

            <div class="flex flex-col sm:flex-row gap-4">
                <div class="flex-1 min-w-0">
                    <flux:select wire:model="form.unit_type" label="Unit Type" placeholder="Choose unit type...">
                        @include('livewire.line-items._unit_type_options')
                    </flux:select>
                </div>
                <div class="flex-1 min-w-0">
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
            </div>

            <div class="flex flex-col sm:flex-row gap-4">
                <div class="flex-1 min-w-0">
                    <flux:input
                        wire:model.live.debounce.500ms="form.quantity"
                        label="Quantity"
                        type="number"
                        inputmode="numeric"
                        step=".1"
                        min=".1"
                        placeholder="1"
                    />
                </div>
                <div class="flex-1 min-w-0">
                    <flux:input
                        wire:model.live.debounce.500ms="form.total"
                        label="Total"
                        disabled
                        type="number"
                        inputmode="decimal"
                    />
                </div>
            </div>
        </div>
    </form>

    <x-slot name="footer">
        <div x-data="{ edit_line_item: @entangle('edit_line_item') }" x-show="edit_line_item">
            @can('create', App\Models\LineItem::class)
                <flux:button
                    type="button"
                    wire:click="updateGlobalLineItem"
                    icon="pencil-square"
                    variant="filled"
                    tooltip="Update main"
                />
            @endcan
        </div>

        <flux:spacer />

        <div x-data="{ edit_line_item: @entangle('edit_line_item') }" x-show="edit_line_item">
            <flux:button wire:click="removeFromEstimate" variant="danger">Remove</flux:button>
        </div>
        <flux:button type="submit" form="estimate_line_item_form_modal_form" variant="primary">{{$view_text['button_text']}}</flux:button>
    </x-slot>
</x-form-modal>
