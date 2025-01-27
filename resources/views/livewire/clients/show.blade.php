<div>
    <div class="grid grid-cols-4 gap-4 lg:max-w-5xl">
		<div class="col-span-4 lg:col-span-2">
			{{-- CLIENT DETAILS --}}
            <x-lists.details_card>
                {{-- HEADING --}}
                <x-slot:heading>
                    <div>
                        <flux:heading size="lg" class="mb-0">Client Details</flux:heading>
                    </div>

                    @can('update', $client)
                        <flux:button
                            wire:click="$dispatchTo('clients.client-create', 'editClient', { client: {{$client->id}}})"
                            size="sm"
                            >
                            Edit Client
                        </flux:button>
                    @endcan
                </x-slot>

                {{-- DETAILS --}}
                <x-lists.details_list>
                    <x-lists.details_item title="Client Name" detail="{{$client->name}}" />
                    <x-lists.details_item title="Billing Address" detail="{!!$client->full_address!!}" />
                    <x-lists.details_item title="Client Source" detail="{{$client->source}}" />
                </x-lists.details_list>
            </x-lists.details_card>
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

    <livewire:projects.project-create />
    <livewire:users.user-create />
    <livewire:clients.client-create />
</div>
