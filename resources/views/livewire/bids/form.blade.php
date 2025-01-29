<flux:modal name="bids_form_modal" class="space-y-2">
    <div class="flex justify-between">
        <flux:heading size="lg">Project Bids</flux:heading>
        <flux:button wire:navigate.hover wire:click="addChangeOrder" icon="plus" size="sm">Change Order</flux:button>
    </div>

    <flux:separator variant="subtle" />

    <form wire:submit="{{$view_text['form_submit']}}" class="grid gap-6">
        @foreach($bids as $bid_index => $bid)
            <flux:card class="space-y-6">
                <div class="flex justify-between">
                    <flux:heading size="lg">{{$bid['name']}}</flux:heading>
                    @if(!$loop->first && $bid['has_estimate_sections'] != true)
                        <flux:button size="sm" wire:click="removeChangeOrder({{$bid_index}})">Remove</flux:button>
                    @endif
                </div>

                <flux:input.group>
                    <flux:input.group.prefix>Amount</flux:input.group.prefix>

                    <flux:input
                        wire:model.live.debounce.500ms="bids.{{$bid_index}}.amount"
                        x-bind:disabled="{{$bid['has_estimate_sections']}}"
                        icon="currency-dollar"
                        type="number"
                        size="lg"
                        inputmode="decimal"
                        pattern="[0-9]*"
                        step="0.01"
                        placeholder="123.45"
                    />
                </flux:input.group>
            </flux:card>
        @endforeach

        <div class="flex space-x-2">
            <flux:spacer />

            <flux:button type="submit" variant="primary">{{$view_text['button_text']}}</flux:button>
        </div>
    </form>
</flux:modal>



{{-- @foreach($bids as $bid_index => $bid)
<div
    class="mt-2 space-y-2"
    >

    <x-forms.row
        wire:model.live="bids.{{$bid_index}}.amount"
        errorName="bids.{{$bid_index}}.amount"
        name="bids.{{$bid_index}}.amount"
        text="{{$bid->name}}"
        type="number"
        hint="$"
        textSize="xl"
        placeholder="00.00"
        inputmode="numeric"
        step="0.01"
        x-bind:disabled="{{!$bid->estimate_sections->isEmpty()}}"
        radioHint="{{$loop->first ? '' : 'Remove'}}"
        >
        <x-slot name="radio">
            <input
                wire:click="removeChangeOrder({{$bid_index}})"
                id="remove{{$bid_index}}"
                name="remove"
                value="true"
                type="checkbox"
                x-bind:disabled="{{!$bid->estimate_sections->isEmpty()}}"
                class="w-4 h-4 ml-2 text-indigo-600 border-gray-300 rounded focus:ring-indigo-500"
                >
        </x-slot>

        if disabled, show a span: ""
    </x-forms.row>
</div>
@endforeach --}}
