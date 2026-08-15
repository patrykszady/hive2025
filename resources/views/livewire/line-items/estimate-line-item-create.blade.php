@php
    $section = $section_id ? $estimate->estimate_sections->find($section_id) : null;
    $isLocked = $edit_line_item && $section?->isLocked();
@endphp
<x-form-modal name="estimate_line_item_form_modal" :title="$view_text['card_title']">
    <form id="estimate_line_item_form_modal_form" wire:submit="{{$view_text['form_submit']}}" class="space-y-4">
        <div x-data="{ edit_line_item: @entangle('edit_line_item') }">
            <flux:select variant="listbox" wire:model.live="line_item_id" label="Line Item" searchable placeholder="Choose Line Item..." x-bind:disabled="edit_line_item" :disabled="$isLocked">
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
                :disabled="$isLocked"
            />

            {{-- NOTES --}}
            <flux:textarea
                wire:model="form.notes"
                label="Notes"
                rows="auto"
                resize="none"
                placeholder=""
                :disabled="$isLocked"
            />

            <div class="flex flex-col sm:flex-row gap-4">
                <div class="flex-1 min-w-0">
                    <flux:input wire:model="form.category" label="Category" placeholder="Category" :disabled="$isLocked" />
                </div>
                <div class="flex-1 min-w-0">
                    <flux:input wire:model="form.sub_category" label="Sub Category" :disabled="$isLocked" />
                </div>
            </div>

            <div class="flex flex-col sm:flex-row gap-4">
                <div class="flex-1 min-w-0">
                    <flux:select wire:model="form.unit_type" label="Unit Type" placeholder="Choose unit type..." :disabled="$isLocked">
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
                        :disabled="$isLocked"
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
                        :disabled="$isLocked"
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

            {{-- ALLOWANCES --}}
            <div class="space-y-2" x-data x-on:allowance-added.window="requestAnimationFrame(() => requestAnimationFrame(() => { const c = $el.closest('.overflow-y-auto'); if (c) { c.scrollTo({ top: c.scrollHeight, behavior: 'smooth' }); } }))">
                <div class="flex items-center justify-between">
                    <flux:heading size="sm">Allowances</flux:heading>
                    @unless($isLocked)
                        <flux:button size="xs" icon="plus" variant="ghost" wire:click="addAllowance">Add Allowance</flux:button>
                    @endunless
                </div>

                @php($unitTypeAvailable = $form->unit_type && $form->unit_type !== 'no_unit')

                @foreach($form->allowances as $aIndex => $allowance)
                    @php($rowPerUnit = $unitTypeAvailable && ($allowance['pricing_mode'] ?? 'per_unit') !== 'lump_sum')
                    @php($rowSuggestions = $this->previousAllowancesForRow($aIndex))
                    <div
                        class="flex flex-col sm:flex-row gap-2 sm:items-center"
                        wire:key="allowance-{{ $aIndex }}"
                        x-data="{
                            desc: @js($allowance['description'] ?? ''),
                            mode: @js($rowPerUnit ? 'per_unit' : 'lump_sum'),
                            suggestions: @js($rowSuggestions->mapWithKeys(fn (array $suggestion) => [
                                $suggestion['description'] => [
                                    'pricing_mode' => $suggestion['pricing_mode'],
                                    'unit_amount' => $suggestion['unit_amount'],
                                    'amount' => $suggestion['amount'],
                                ],
                            ])),
                            syncAllowance() {
                                const match = this.suggestions[this.desc.trim()];

                                if (!match) {
                                    return;
                                }

                                const isLumpSum = match.pricing_mode === 'lump_sum';
                                this.mode = match.pricing_mode;
                                const unitAmount = isLumpSum ? '' : (match.unit_amount ?? '');
                                const amount = isLumpSum ? (match.amount ?? '') : (unitAmount ? (Number(unitAmount) * {{ $form->quantity ?: 1 }}).toFixed(2) : '');

                                if (this.$refs.unitAmount) {
                                    this.$refs.unitAmount.value = unitAmount === null ? '' : String(unitAmount);
                                    this.$refs.unitAmount.dispatchEvent(new Event('input', { bubbles: true }));
                                }

                                if (this.$refs.amount) {
                                    this.$refs.amount.value = amount === null ? '' : String(amount);
                                    this.$refs.amount.dispatchEvent(new Event('input', { bubbles: true }));
                                }
                            }
                        }"
                    >
                        <div class="flex-1 min-w-0">
                            <flux:autocomplete
                                wire:model.live.debounce.500ms="form.allowances.{{ $aIndex }}.description"
                                x-on:input="desc = $event.target.value; syncAllowance()"
                                placeholder="Allowance description"
                                size="sm"
                                :disabled="$isLocked"
                            >
                                @foreach($rowSuggestions as $previousAllowance)
                                    <flux:autocomplete.item x-on:pointerdown.prevent="desc = @js($previousAllowance['description']); syncAllowance()">{{ $previousAllowance['description'] }}</flux:autocomplete.item>
                                @endforeach
                            </flux:autocomplete>
                        </div>
                        @if($unitTypeAvailable)
                            <div class="w-32 shrink-0">
                                <flux:input
                                    x-ref="unitAmount"
                                    wire:model.live.debounce.500ms="form.allowances.{{ $aIndex }}.unit_amount"
                                    x-bind:placeholder="desc.trim() !== '' && mode === 'per_unit' ? '0.00' : ''"
                                    type="number"
                                    inputmode="decimal"
                                    step="0.01"
                                    size="sm"
                                    x-bind:disabled="{{ $isLocked ? 'true' : 'false' }} || mode !== 'per_unit'"
                                >
                                    <x-slot name="iconLeading">
                                        <div x-show="desc.trim() !== ''" x-cloak class="contents">
                                            <div x-show="mode === 'per_unit'" class="contents">
                                                <flux:checkbox
                                                    wire:key="allowance-{{ $aIndex }}-perunit-on"
                                                    wire:click="toggleAllowancePerUnit({{ $aIndex }})"
                                                    x-on:click="mode = 'lump_sum'"
                                                    :checked="true"
                                                    :disabled="$isLocked"
                                                    tooltip="Price per {{ $form->unit_type }}"
                                                    class="ml-2.5"
                                                />
                                            </div>
                                            <div x-show="mode === 'lump_sum'" class="contents">
                                                <flux:checkbox
                                                    wire:key="allowance-{{ $aIndex }}-perunit-off"
                                                    wire:click="toggleAllowancePerUnit({{ $aIndex }})"
                                                    x-on:click="mode = 'per_unit'"
                                                    :checked="false"
                                                    :disabled="$isLocked"
                                                    tooltip="Price per {{ $form->unit_type }}"
                                                    class="ml-2.5"
                                                />
                                            </div>
                                        </div>
                                    </x-slot>
                                    <x-slot name="iconTrailing">
                                        <span x-show="desc.trim() !== '' && mode === 'per_unit'" x-cloak class="text-xs text-zinc-400 pr-2">/ {{ $form->unit_type }}</span>
                                    </x-slot>
                                </flux:input>
                            </div>
                        @endif
                        <div class="w-32 shrink-0">
                            <flux:input
                                x-ref="amount"
                                wire:model="form.allowances.{{ $aIndex }}.amount"
                                x-bind:placeholder="desc.trim() !== '' ? '0.00' : ''"
                                type="number"
                                inputmode="decimal"
                                step="0.01"
                                size="sm"
                                x-bind:disabled="{{ $isLocked ? 'true' : 'false' }} || mode === 'per_unit'"
                            />
                        </div>
                        @unless($isLocked)
                            <flux:button size="sm" icon="x-mark" variant="ghost" wire:click="removeAllowance({{ $aIndex }})" />
                        @endunless
                    </div>
                @endforeach
            </div>
        </div>
    </form>

    <x-slot name="footer">
        <div x-data="{ edit_line_item: @entangle('edit_line_item') }" x-show="edit_line_item">
            @unless($isLocked)
                @can('create', App\Models\LineItem::class)
                    <flux:button
                        type="button"
                        wire:click="updateGlobalLineItem"
                        icon="pencil-square"
                        variant="filled"
                        tooltip="Update main"
                    />
                @endcan
            @endunless
        </div>

        <flux:spacer />

        {{-- Credits only apply to line items locked into the signed contract
             (same condition that renders "Hide") — change-order items added
             after signing are editable directly. --}}
        @if($isLocked)
            <div x-data="{ edit_line_item: @entangle('edit_line_item') }" x-show="edit_line_item">
                <flux:button
                    wire:click="creditToChangeOrder"
                    variant="filled"
                    tooltip="Add an offsetting credit to a change order section"
                >
                    Credit
                </flux:button>
            </div>
        @endif

        <div x-data="{ edit_line_item: @entangle('edit_line_item') }" x-show="edit_line_item">
            <flux:button wire:click="removeFromEstimate" variant="danger">{{ $isLocked ? 'Hide' : 'Remove' }}</flux:button>
        </div>

        @unless($isLocked)
            <flux:button type="submit" form="estimate_line_item_form_modal_form" variant="primary">{{$view_text['button_text']}}</flux:button>
        @endunless
    </x-slot>
</x-form-modal>
