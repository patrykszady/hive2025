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
    <div class="space-y-6">
        <div class="griddgrid-cols-3 gap-x-4 gap-y-6">
            <flux:input wire:model.live.debounce.500ms="form.address" label="Street Address" placeholder="123 Main St" class="max-w-sm" />
            <flux:input wire:model.live.debounce.500ms="form.address_2" label="Unit Number" placeholder="#1N" class="max-w-sm" />
        </div>

        <div class="grid grid-cols-3 gap-x-4 gap-y-6">
            <flux:input wire:model.live.debounce.500ms="form.city" label="City" placeholder="Chicago" />
            <flux:input wire:model.live.debounce.250ms="form.state" label="State" maxlength="2" minlength="2" placeholder="IL" />
            <flux:input wire:model.live.debounce.500ms="form.zip_code" label="Zip Code" maxlength="5" minlength="5" placeholder="60640" />
        </div>
    </div>
</flux:fieldset>
