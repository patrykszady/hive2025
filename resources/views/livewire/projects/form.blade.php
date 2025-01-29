<flux:modal name="project_form_modal" class="space-y-2 min-w-2xl">
    <div class="flex justify-between">
        <flux:heading size="lg">{{$view_text['card_title']}}</flux:heading>
    </div>

    <flux:separator variant="subtle" />

    <form wire:submit="{{$view_text['form_submit']}}" class="grid gap-6">
        {{-- CLIENT ID --}}
        <div
            x-data="{ existing_client: @entangle('existing_client') }"
            >
            <flux:select label="Client" wire:model.live="form.client_id" x-bind:disabled="existing_client" variant="listbox" searchable placeholder="Choose client...">
                <x-slot name="search">
                    <flux:select.search placeholder="Search..." />
                </x-slot>

                @foreach($this->clients as $client)
                    <flux:option value="{{$client->id}}">{{$client->name}}</flux:option>
                @endforeach
            </flux:select>
        </div>

        <div
            x-data="{ client: @entangle('form.client_id') }"
            x-show="client"
            x-transition
            class="my-4 space-y-4"
            >

            {{-- ADDRESS --}}
            <flux:fieldset>
                <flux:legend>Address</flux:legend>

                <flux:radio.group wire:model.live="form.project_existing_address" variant="cards" class="flex-col" :indicator="false">
                    @foreach($client_addresses as $project_address)
                        @if(isset($project_address->id))
                            <flux:radio
                                value="{{$project_address->id}}"
                                label="{{$project_address->address}}"
                                description="{{$project_address->city . ', ' . $project_address->state . ' ' . $project_address->zip_code}}"
                                {{-- @if($loop->first)
                                    checked
                                @endif --}}
                            />
                        @else
                            <flux:radio
                                value="CLIENT_PROJECT"
                                label="{{$project_address['address']}}"
                                description="{{$project_address['city'] . ', ' . $project_address['state'] . ' ' . $project_address['zip_code']}}"
                                {{-- checked --}}
                            />
                        @endif
                    @endforeach

                    <flux:radio
                        value="NEW"
                        label="New Address"
                    />
                </flux:radio.group>
            </flux:fieldset>

            {{-- only show if new address --}}
            <div
                x-data="{ new_address: @entangle('form.project_existing_address') }"
                x-show="new_address == 'NEW'"
                x-transition
                class="my-4 space-y-4"
                >
                @include('components.forms._address_form', ['model' => 'form'])
            </div>

            {{-- PROJECT NAME --}}
            <flux:input
                wire:model.live.debounce.500ms="form.project_name"
                label="Project Name"
                type="text"
            />
        </div>

        <div class="flex space-x-2 sticky bottom-0">
            <flux:spacer />

            <flux:button type="submit" variant="primary">{{$view_text['button_text']}}</flux:button>
        </div>
    </form>
</flux:modal>
