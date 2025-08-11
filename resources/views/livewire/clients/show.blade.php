<div>
    <div class="grid grid-cols-4 gap-4 lg:max-w-5xl">
        <div class="col-span-4 lg:col-span-2">
            {{-- CLIENT DETAILS --}}
            <x-details.card 
                :title="$client->name"
                :canEdit="auth()->user()->can('update', $client)"
            >
                <x-slot:header_buttons>
                    <flux:button
                        wire:click="$dispatchTo('clients.client-create', 'editClient', { client: {{$client->id}}})"
                        size="sm"
                    >
                        Edit Client
                    </flux:button>
                </x-slot:header_buttons>
                
                <x-slot:details>
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

                    {{-- Client Phone --}}
                    @if($client->home_phone)
                        <x-details.row 
                            title="Phone" 
                            :content="$client->home_phone"
                            :copyable="true"
                        />
                    @endif

                    {{-- Client Source --}}
                    <x-details.row 
                        title="Source" 
                        :content="$client->source"
                    />
                </x-slot:details>
            </x-details.card>
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
