<flux:fieldset>
    <flux:legend>Address</flux:legend>
    <div class="space-y-3">
        <!-- Make the top row full-width like the groups below -->
        <div class="space-y-2">
            <flux:field>
                <flux:label>Lookup Address</flux:label>
                <flux:select wire:model.live="address_selection" variant="combobox">
                    <x-slot name="input">
                        <div>
                            <flux:select.input
                                wire:model.live.debounce.300ms="address_query"
                                placeholder="Start typing (e.g. 130 E Main Ave)" />
                        </div>
                    </x-slot>
                    @if(!empty($address_query))
                        @foreach ($address_suggestions as $address_suggestion)
                            <flux:select.option value="{{$address_suggestion['place_id']}}" :key="$address_suggestion['place_id']">
                                {{$address_suggestion['description']}}
                            </flux:select.option>
                        @endforeach
                    @endif
                </flux:select>
            </flux:field>

            <div class="grid grid-cols-5 gap-2">
                <div class="col-span-4">
                    <flux:input wire:model.live.debounce.500ms="address_1" label="Street Address (Manual)" placeholder="130 E Main Ave" />
                </div>
                <div class="col-span-1">
                    <flux:input wire:model.live.debounce.500ms="address_2" label="Unit" placeholder="#1N" />
                </div>
            </div>
        </div>

        <div class="grid grid-cols-6 gap-2">
            <div class="col-span-3">
                <flux:input wire:model.live.debounce.500ms="city" label="City" placeholder="Chicago" />
            </div>
            <div class="col-span-1">
                <flux:input wire:model.live.debounce.250ms="state" label="State" maxlength="2" minlength="2" placeholder="IL" />
            </div>
            <div class="col-span-2">
                <flux:input wire:model.live.debounce.500ms="zip_code" label="Zip" maxlength="5" minlength="5" placeholder="60640" />
            </div>
        </div>
    </div>
</flux:fieldset>

