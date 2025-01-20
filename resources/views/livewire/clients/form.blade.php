<flux:modal name="client_form_modal" class="space-y-2 min-w-2xl">
    <div class="flex justify-between">
        <flux:heading size="lg">{{$view_text['card_title']}}</flux:heading>
    </div>

    <flux:separator variant="subtle" />

    <form wire:submit="{{$view_text['form_submit']}}" class="grid gap-6">
        <flux:input
            wire:model.live.debounce.500ms="form.client_name"
            disabled
            label="Client User"
            type="text"
        />
        {{-- <div
            x-data="{ open: @entangle('client_name')}"
            x-show="open"
            x-transition
            class="my-4 space-y-4"
            >
            <flux:input
                wire:model="client_name"
                disabled
                label="Client Name"
                type="text"
            />
        </div> --}}

        <div
            x-data="{ open: @entangle('client_name'), address: @entangle('form.address')}"
            x-show="!open && !address"
            x-transition
            >
            @if(!empty($user_clients))
                <flux:radio.group wire:model.live="user_client_id" label="Existing Clients" variant="cards" class="flex-col" :indicator="false">
                    @foreach ($user_clients as $client)
                        <flux:radio
                            name="clients"
                            value="{{$client->id}}"
                            label="{{$client->address}}"
                            description="{!!$client->name!!}"
                        />
                    @endforeach

                    <flux:radio name="clients" value="NEW" label="New Client" />
                </flux:radio.group>
            @endif

            <flux:separator variant="subtle" />
        </div>

        <div
            x-data="{open: @entangle('user_client_id'), address: @entangle('form.address')}"
            x-show="open == 'NEW' || address"
            x-transition
            class="space-y-4"
            >

            <flux:input
                wire:model.live.debounce.500ms="form.business_name"
                label="Business Name"
                placeholder="Business Name"
                type="text"
            />

            {{-- ADDRESS --}}
            @include('components.forms._address_form')

            <flux:input
                wire:model.live.debounce.500ms="form.source"
                label="Referral"
                type="text"
                placeholder="Referral / Lead / Source"
            />
        </div>

        <div class="flex space-x-2 sticky bottom-0">
            <flux:spacer />

            <flux:button type="submit" variant="primary">{{$view_text['button_text']}}</flux:button>
        </div>
    </form>
</flux:modal>
