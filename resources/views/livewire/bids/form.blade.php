@php
    $hasEditableBids = collect($bids)->contains('has_estimate_sections', false);
@endphp

<x-form-modal name="bids_form_modal" title="Project Bids">
    <x-slot name="headerActions">
        @if($hasEditableBids)
            <flux:button wire:navigate.hover wire:click="addChangeOrder" icon="plus" size="sm">Change Order</flux:button>
        @endif
    </x-slot>

    <form id="bids_form_modal_form" wire:submit="{{$view_text['form_submit']}}" class="space-y-4">
        @foreach($bids as $bid_index => $bid)
            <flux:card class="space-y-6">
                <div class="flex justify-between">
                    <flux:heading size="lg">{{$bid['name']}}</flux:heading>
                    @if(!$loop->first && $bid['has_estimate_sections'] != true)
                        <flux:button size="sm" wire:click="removeChangeOrder({{$bid_index}})">Remove</flux:button>
                    @endif
                </div>

                <flux:field>
                    <flux:input.group>
                        <flux:input.group.prefix>Amount</flux:input.group.prefix>

                        @if($bid['has_estimate_sections'])
                            <flux:input
                                wire:model.live.debounce.500ms="bids.{{$bid_index}}.amount"
                                disabled
                                icon="currency-dollar"
                                type="number"
                                size="lg"
                                inputmode="decimal"
                                pattern="[0-9]*"
                                step="0.01"
                                placeholder="123.45"
                            />
                        @else
                            <flux:input
                                wire:model.live.debounce.500ms="bids.{{$bid_index}}.amount"
                                icon="currency-dollar"
                                type="number"
                                size="lg"
                                inputmode="decimal"
                                pattern="[0-9]*"
                                step="0.01"
                                placeholder="123.45"
                            />
                        @endif
                    </flux:input.group>
                </flux:field>
            </flux:card>
        @endforeach
    </form>

    <x-slot name="footer">
        <flux:spacer />
        <flux:button type="submit" form="bids_form_modal_form" variant="primary">{{$view_text['button_text']}}</flux:button>
    </x-slot>
</x-form-modal>