<div>
    <div class="grid grid-cols-4 gap-4 max-w-5xl lg:grid-cols-6">
        <div class="col-span-4 lg:col-span-2">
            {{-- CLIENT DETAILS --}}
            <x-details.card 
                :title="$client->name"
                :canEdit="auth()->user()->can('update', $client)"
                :expanded="!auth()->user()->is_browsing_as_client"
            >
                @if(!auth()->user()->is_browsing_as_client)
                    <x-slot:header_buttons>
                        <flux:button
                            wire:click="$dispatchTo('clients.client-create', 'editClient', { client: {{$client->id}}})"
                            size="sm"
                        >
                            Edit Client
                        </flux:button>
                    </x-slot:header_buttons>
                @endif
                
                <x-slot:details>
                    {{-- Client Name --}}
                    <x-details.row 
                        title="Name" 
                        :content="$client->name"
                        :noCloak="true"
                    />

                    {{-- Client Address with Link --}}
                    <x-details.row 
                        title="Billing Address" 
                        :content="$client->full_address" 
                        :href="$client->getAddressMapURI()"
                        :copyable="true"
                        :noCloak="true"
                    />

                    {{-- Client Phone --}}
                    @if($client->home_phone)
                        <x-details.row 
                            title="Phone" 
                            :content="$client->home_phone"
                            :copyable="true"
                            :noCloak="true"
                        />
                    @endif
                </x-slot:details>
            </x-details.card>
        </div>

        {{-- CLIENT USERS --}}
        <div class="col-span-4 lg:col-span-4">
            <livewire:users.users-index :client="$client" :view="'clients.show'"/>
        </div>

        {{-- CLIENT PROJECT --}}
        <div class="col-span-4 lg:col-span-3">
            <livewire:projects.projects-index :client="$client" :view="'clients.index'" />
        </div>

        {{-- CLIENT PROJECT TASKS --}}
        <div class="col-span-4 lg:col-span-3">
            <livewire:clients.upcoming-client-tasks :client="$client" lazy />
        </div>
    </div>
    @if(!auth()->user()->is_browsing_as_client)
        <livewire:clients.client-create />
        <livewire:tasks.task-create />
    @endif
</div>
