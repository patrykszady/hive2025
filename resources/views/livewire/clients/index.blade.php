<div class="max-w-4xl space-y-2">
    <x-filter-card heading="Client Filters">
        {{-- single copy: the inline layout stacks below sm on its own --}}
        @include('livewire.clients.partials.filter-fields', ['layout' => 'inline'])
    </x-filter-card>

    <livewire:clients.clients-table :client-name-search="$client_name_search" lazy />

    <livewire:users.user-create />
    <livewire:clients.client-create />
</div>
