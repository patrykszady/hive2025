<flux:select wire:model.live="address_selection" variant="combobox" :filter="false">
    <x-slot name="input">
        <flux:select.input wire:model.live.debounce.500ms="address" placeholder="Address..."/>
    </x-slot>

    {{-- @foreach ($addresses as $key => $address)
        <flux:option value="{{$key}}" wire:key="{{$key}}">{{$address->formatted_address}}</flux:option>
    @endforeach --}}
</flux:select>

<flux:fieldset>
    {{-- <flux:legend>Address</flux:legend> --}}
    <div class="space-y-2">
        <div class="grid grid-cols-2 gap-4">
            <flux:input wire:model.live.debounce.500ms="form.address" label="Street Address" placeholder="123 Main St" />
            <flux:input wire:model.live.debounce.500ms="form.address_2" label="Unit Number" placeholder="#1N" />
        </div>

        <div class="grid grid-cols-3 gap-4">
            <flux:input wire:model.live.debounce.500ms="form.city" label="City" placeholder="Chicago" />
            <flux:input wire:model.live.debounce.250ms="form.state" label="State" maxlength="2" minlength="2" placeholder="IL" />
            <flux:input wire:model.live.debounce.500ms="form.zip_code" label="Zip Code" maxlength="5" minlength="5" placeholder="60640" />
        </div>
    </div>
</flux:fieldset>
