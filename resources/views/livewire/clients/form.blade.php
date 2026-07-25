<div>
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
        @if ($view_text['form_submit'] === 'edit' && isset($client) && $client->exists && auth()->user()->can('delete', $client))
            <flux:button
                wire:click="confirmDeleteClient"
                variant="danger"
            >
                Delete
            </flux:button>
        @endif
        <flux:spacer />
        <flux:button type="submit" form="client_form_modal_form" variant="primary">{{$view_text['button_text']}}</flux:button>
    </x-slot>
</x-form-modal>

{{-- DELETE CLIENT CONFIRMATION --}}
<flux:modal wire:model.self="showClientDelete" name="client-delete-confirm" class="max-w-md">
    @if(isset($client) && $client->exists)
        <div class="space-y-4">
            <flux:heading size="lg">Delete {{ $client->name }}?</flux:heading>

            <flux:text>This permanently removes the client:</flux:text>

            <ul class="list-disc pl-5 space-y-1 text-sm text-zinc-600 dark:text-zinc-300">
                <li>The client and their portal access are removed.</li>
                <li>Contact people keep their account if they're connected to another client or company; otherwise it's removed too.</li>
                <li>Links to your team and vendors are removed.</li>
                <li>Clients with projects can't be deleted — this one has none.</li>
            </ul>

            <div class="flex justify-end gap-2 pt-2">
                <flux:button variant="ghost" wire:click="$set('showClientDelete', false)">Cancel</flux:button>
                <flux:button variant="danger" icon="trash" wire:click="deleteClient">Delete Client</flux:button>
            </div>
        </div>
    @endif
</flux:modal>

</div>
