{{--  :filter="false" --}}
<flux:select wire:model="address_selection" variant="combobox">
    <x-slot name="input">
        <flux:select.input wire:model.live.debounce.300ms="address_query" placeholder="Address..."/>
    </x-slot>

    @if(!empty($address_query))
        @foreach ($address_suggestions as $address_suggestion)
            <flux:select.option value="{{$address_suggestion['place_id']}}" :key="$address_suggestion['place_id']">
                {{$address_suggestion['description']}}
            </flux:select.option>
        @endforeach
    @endif
</flux:select>

<div x-show="$wire.address_1">
    <flux:fieldset>
        <flux:legend>Address</flux:legend>
        <div class="space-y-2">
            <div class="grid grid-cols-2 gap-4">
                <flux:input wire:model.live.debounce.500ms="address_1" label="Street Address" placeholder="123 Main St" />
                <flux:input wire:model.live.debounce.500ms="address_2" label="Unit Number" placeholder="#1N" />
            </div>

            <div class="grid grid-cols-3 gap-4">
                <flux:input wire:model.live.debounce.500ms="city" label="City" placeholder="Chicago" />
                <flux:input wire:model.live.debounce.250ms="state" label="State" maxlength="2" minlength="2" placeholder="IL" />
                <flux:input wire:model.live.debounce.500ms="zip_code" label="Zip Code" maxlength="5" minlength="5" placeholder="60640" />
            </div>
        </div>
    </flux:fieldset>
</div>
