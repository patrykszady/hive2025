<div>
    <div class="grid grid-cols-4 gap-4 lg:max-w-5xl">
        <div class="col-span-4 lg:col-span-2">
            {{-- CLIENT DETAILS --}}
            <flux:card>
                {{-- HEADER - Keep outside accordion --}}
                <div class="flex justify-between">
                    <flux:heading size="lg" class="mb-0 truncate">{{ $client->name }}</flux:heading>

                    @can('update', $client)
                        <flux:button
                            wire:click="$dispatchTo('clients.client-create', 'editClient', { client: {{$client->id}}})"
                            size="sm"
                            >
                            Edit Client
                        </flux:button>
                    @endcan
                </div>

                {{-- SUBHEADING --}}
                <flux:subheading>Client Information</flux:subheading>

                <flux:separator class="my-2"/>

                {{-- DETAILS LIST wrapped in accordion --}}
                <flux:accordion transition>
                    <flux:accordion.item expanded>
                        <flux:accordion.heading>
                            Details
                        </flux:accordion.heading>
                        <flux:accordion.content>
                            {{-- Client Name --}}
                            <x-details.row 
                                title="Name" 
                                :content="$client->name"
                            />

                            {{-- Client Address with Link --}}
                            <x-details.row 
                                title="Billing Address" 
                                :content="$client->full_address" 
                                :href="$client->getAddressMapURI()"
                                :copyable="true"
                            />

                            {{-- Client Source --}}
                            <x-details.row 
                                title="Source" 
                                :content="$client->source"
                            />

                            {{-- Client Phone --}}
                            @if($client->home_phone)
                                <x-details.row 
                                    title="Phone" 
                                    :content="$client->home_phone"
                                    :copyable="true"
                                />
                            @endif
                        </flux:accordion.content>
                    </flux:accordion.item>
                </flux:accordion>
            </flux:card>
        </div>

        {{-- CLIENT USERS --}}
        <div class="col-span-4 lg:col-span-2">
            <livewire:users.users-index :client="$client" :view="'clients.show'"/>
        </div>

        {{-- CLIENT PROJECT --}}
        <div class="col-span-4 lg:col-span-2">
            <livewire:projects.projects-index :client="$client" :view="'clients.index'" />
        </div>
    </div>
    <livewire:clients.client-create />
</div>
