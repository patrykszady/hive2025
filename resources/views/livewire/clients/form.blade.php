<x-form-modal name="client_form_modal" :title="$view_text['card_title']">
    <form id="client_form_modal_form" wire:submit="{{$view_text['form_submit']}}" class="space-y-4">
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

        <!-- Client selection (only when no client chosen yet) -->
        <div
            x-data="{ userClientId: @entangle('user_client_id') }"
            x-show="!userClientId"
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

        <!-- Form details (always show for NEW or existing client, regardless of address fields) -->
        <div
            x-data="{ userClientId: @entangle('user_client_id') }"
            x-show="userClientId === 'NEW' || !isNaN(parseInt(userClientId))"
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
            @include('components.forms._address_form', ['address_suggestions' => $address_suggestions])

            <flux:input
                wire:model="form.source"
                label="Referral"
                type="text"
                placeholder="Referral / Lead / Source"
            />
        </div>
    </form>

    <x-slot name="footer">
        <flux:spacer />
        <flux:button type="submit" form="client_form_modal_form" variant="primary">{{$view_text['button_text']}}</flux:button>
    </x-slot>
</x-form-modal>
