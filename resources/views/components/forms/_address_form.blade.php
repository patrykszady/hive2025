<flux:fieldset>
    <flux:legend>Address</flux:legend>
    {{--  :filter="false" --}}
    <div>
        <flux:select wire:model="address_selection" variant="combobox">
            <x-slot name="input">
                <div x-data>
                    <flux:select.input 
                        wire:model.live.debounce.300ms="address_query" 
                        placeholder="{{ $address_1 ? 'New Address...' : 'Enter Address...' }}"
                    />
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
    </div>
    
    <div x-show="$wire.address_1">
        <div class="space-y-2">            
            <div class="grid grid-cols-3 gap-4">
                <div class="col-span-2">
                    <flux:input wire:model.live.debounce.500ms="address_1" label="Street Address" placeholder="123 Main St"/>
                </div>
                <div class="col-span-1">
                    <flux:input wire:model.live.debounce.500ms="address_2" label="Unit" placeholder="#1N"/>
                </div>
            </div>

            <div class="grid grid-cols-6 gap-2">
                <div class="col-span-3">
                    <flux:input wire:model.live.debounce.500ms="city" label="City" placeholder="Chicago"/>
                </div>
                <div class="col-span-1">
                    <flux:input wire:model.live.debounce.250ms="state" label="State" maxlength="2" minlength="2" placeholder="IL"/>
                </div>
                <div class="col-span-2">
                    <flux:input wire:model.live.debounce.500ms="zip_code" label="Zip" maxlength="5" minlength="5" placeholder="60640"/>
                </div>
            </div>
        </div>
    </div>
</flux:fieldset>

