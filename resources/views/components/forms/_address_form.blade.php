<flux:fieldset>
    <flux:legend>Address</flux:legend>
    <div class="space-y-3">
        <!-- Make the top row full-width like the groups below -->
        <div class="space-y-2">
            <flux:field>
                <flux:label>Lookup Address</flux:label>
                <div class="relative">
                    <flux:autocomplete
                        wire:model.live.debounce.300ms="address_query"
                        :filter="false"
                        loading="address_query,selectSuggestion"
                        placeholder="Start typing (e.g. 130 E Main Ave)">
                        @foreach ($address_suggestions as $address_suggestion)
                            <flux:autocomplete.item
                                wire:click="selectSuggestion('{{ $address_suggestion['place_id'] }}')"
                                value="{{ $address_suggestion['description'] }}">
                                {{ $address_suggestion['description'] }}
                            </flux:autocomplete.item>
                        @endforeach
                    </flux:autocomplete>

                    <div
                        wire:loading
                        wire:target="address_query,selectSuggestion"
                        class="pointer-events-none absolute right-3 top-1/2 z-10 -translate-y-1/2">
                        <flux:icon.loading class="size-4 animate-spin text-zinc-400" />
                    </div>
                </div>
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

