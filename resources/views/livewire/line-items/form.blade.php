<x-form-modal name="line_item_form_modal" :title="$view_text['card_title']">
    <form
        id="line_item_form_modal_form"
        x-data="{ line_title: @entangle('form.name'), existing_line_item_id: @entangle('existing_line_item_id') }"
        wire:submit="{{$view_text['form_submit']}}"
        class="space-y-4"
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
                    <flux:input wire:model="form.category" label="Category" />
                </div>
                <div class="flex-1 min-w-0">
                    <flux:input wire:model="form.sub_category" label="Sub Category" />
                </div>
            </div>

            <div class="flex flex-col sm:flex-row items-end gap-4">
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

            <flux:separator variant="subtle" />

            <div class="space-y-2" x-data x-on:allowance-added.window="requestAnimationFrame(() => requestAnimationFrame(() => { const c = $el.closest('.overflow-y-auto'); if (c) { c.scrollTo({ top: c.scrollHeight, behavior: 'smooth' }); } }))">
                <div class="flex items-center justify-between">
                    <flux:heading size="sm">Allowances</flux:heading>
                    <flux:button size="xs" icon="plus" variant="ghost" wire:click="addAllowance">Add Allowance</flux:button>
                </div>

                @php($unitTypeAvailable = $form->unit_type && $form->unit_type !== 'no_unit')

                @forelse($form->allowances as $aIndex => $allowance)
                    @php($rowHasDescription = trim($allowance['description'] ?? '') !== '')
                    @php($rowPerUnit = $unitTypeAvailable && ($allowance['pricing_mode'] ?? 'per_unit') !== 'lump_sum')
                    <div class="flex gap-2 sm:items-center" wire:key="line-item-allowance-{{ $aIndex }}" x-data="{ desc: @js($allowance['description'] ?? '') }">
                        <div class="flex-1 min-w-0">
                            <flux:input
                                wire:model.live.debounce.500ms="form.allowances.{{ $aIndex }}.description"
                                x-on:input="desc = $event.target.value"
                                placeholder="Allowance description"
                                size="sm"
                            />
                        </div>
                        <div class="w-32 shrink-0">
                            @if($rowPerUnit)
                                <flux:input
                                    wire:model="form.allowances.{{ $aIndex }}.unit_amount"
                                    x-bind:placeholder="desc.trim() !== '' ? '0.00' : ''"
                                    type="number"
                                    inputmode="decimal"
                                    step="0.01"
                                    size="sm"
                                >
                                    @if($unitTypeAvailable)
                                        <x-slot name="iconLeading">
                                            <div x-show="desc.trim() !== ''" x-cloak class="contents">
                                                <flux:checkbox
                                                    wire:key="allowance-{{ $aIndex }}-perunit-{{ $rowPerUnit ? 'on' : 'off' }}"
                                                    wire:click="toggleAllowancePerUnit({{ $aIndex }})"
                                                    :checked="$rowPerUnit"
                                                    tooltip="Price per {{ $form->unit_type }}"
                                                    class="ml-2.5"
                                                />
                                            </div>
                                        </x-slot>
                                    @endif
                                    <x-slot name="iconTrailing">
                                        <span x-show="desc.trim() !== ''" x-cloak class="text-xs text-zinc-400 pr-2">/ {{ $form->unit_type }}</span>
                                    </x-slot>
                                </flux:input>
                            @else
                                <flux:input
                                    wire:model="form.allowances.{{ $aIndex }}.amount"
                                    x-bind:placeholder="desc.trim() !== '' ? '0.00' : ''"
                                    type="number"
                                    inputmode="decimal"
                                    step="0.01"
                                    size="sm"
                                >
                                    @if($unitTypeAvailable)
                                        <x-slot name="iconLeading">
                                            <div x-show="desc.trim() !== ''" x-cloak class="contents">
                                                <flux:checkbox
                                                    wire:key="allowance-{{ $aIndex }}-perunit-{{ $rowPerUnit ? 'on' : 'off' }}"
                                                    wire:click="toggleAllowancePerUnit({{ $aIndex }})"
                                                    :checked="$rowPerUnit"
                                                    tooltip="Price per {{ $form->unit_type }}"
                                                    class="ml-2.5"
                                                />
                                            </div>
                                        </x-slot>
                                    @endif
                                </flux:input>
                            @endif
                        </div>
                        <flux:button size="sm" icon="x-mark" variant="ghost" wire:click="removeAllowance({{ $aIndex }})" />
                    </div>
                @empty
                    <flux:text size="sm" class="text-zinc-500">No allowances yet. Add one to build the catalog for future estimates.</flux:text>
                @endforelse
            </div>
        </div>
    </form>

    <x-slot name="footer">
        <flux:spacer />
        <flux:button type="submit" form="line_item_form_modal_form" variant="primary">{{$view_text['button_text']}}</flux:button>
    </x-slot>
</x-form-modal>
