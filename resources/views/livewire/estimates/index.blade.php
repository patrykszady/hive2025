<div class="max-w-3xl">
    @if($view === NULL)
        <flux:card class="space-y-2 mb-4">
            <div class="flex justify-between">
                <flux:heading size="lg">Filters</flux:heading>
            </div>
            {{-- NEW PROJECT MODAL --}}
            {{-- <livewire:projects.project-create :$clients />
            <flux:separator variant="subtle" />

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <flux:input wire:model.live="project_name_search" label="Project" icon="magnifying-glass" placeholder="Search projects..." />

                <flux:select wire:model.live="client_id" label="Client" variant="listbox" searchable placeholder="All Clients...">
                    <x-slot name="search">
                        <flux:select.search placeholder="Search..." />
                    </x-slot>
                    @foreach ($clients as $client)
                        <flux:option value="{{$client->id}}">{{ $client->name }}</flux:option>
                    @endforeach
                </flux:select>

                <flux:select wire:model.live="project_status_title" label="Status" placeholder="Status...">
                    @include('livewire.projects._status_options')
                </flux:select> --}}
            {{-- </div> --}}
        </flux:card>
    @endif

    <flux:card class="space-y-2">
        <div class="flex justify-between">
            <flux:heading size="lg">Estimates</flux:heading>
            @if($view !== 'estimates.index')
                @can('create', [App\Models\Estimate::class, $project])
                    <flux:button
                        href="{{route('estimates.create', $project->id)}}"
                        size="sm"
                        >
                        Add Estimate
                    </flux:button>
                @endcan
            @endif
        </div>

        <div class="space-y-2">
            <flux:table :paginate="$this->estimates">
                <flux:columns>
                    <flux:column>Estimate</flux:column>
                    <flux:column>Amount</flux:column>
                    <flux:column>Date</flux:column>
                    @if($view === 'estimates.index')
                        <flux:column>Client</flux:column>
                    @endif
                    <flux:column>Status</flux:column>
                    <flux:column></flux:column>

                    {{-- <flux:column sortable :sorted="$sortBy === 'date'" :direction="$sortDirection" wire:click="sort('date')">Date</flux:column>
                    @if($view != 'checks.show')
                        <flux:column >Vendor</flux:column>
                    @endif

                    @if($view != 'projects.show')
                        <flux:column>Project</flux:column>
                    @endif --}}
                </flux:columns>

                <flux:rows>
                    @foreach($this->estimates as $estimate)
                        <flux:row :key="$estimate->id">
                            @if($estimate->status === 'Active')
                                <flux:cell
                                    wire:navigate.hover
                                    href="{{route('estimates.show', $estimate->id)}}"
                                    variant="strong"
                                    class="cursor-pointer"
                                    >
                                    # {{ $estimate->id }}
                                </flux:cell>
                            @else
                                <flux:cell>
                                    # {{ $estimate->id }}
                                </flux:cell>
                            @endif

                            <flux:cell>{{ money($estimate->estimate_sections->sum('total')) }}</flux:cell>
                            <flux:cell>{{ $estimate->created_at->format('m/d/Y') }}</flux:cell>
                            @if($view === 'estimates.index')
                                <flux:cell
                                    wire:navigate.hover
                                    href="{{route('clients.show', $estimate->project->client->id)}}"
                                    class="cursor-pointer"
                                    >
                                    {{ $estimate->project->client->name }}
                                </flux:cell>
                            @endif

                            {{-- <flux:cell
                                wire:click="$dispatchTo('projects.expense-create', 'editExpense', { expense: {{$project->id}}})"
                                variant="strong"
                                class="cursor-pointer"
                                >
                                {{ $project->address }}
                            </flux:cell>
                            <flux:cell>{{ $expense->date->format('m/d/Y') }}</flux:cell>
                            @if($view != 'checks.show')
                                <flux:cell><a wire:navigate.hover href="{{route('vendors.show', $expense->vendor->id)}}">{{Str::limit($expense->vendor->name, 20)}}</a></flux:cell>
                            @endif
                            @if($view != 'projects.show')
                                <flux:cell>{{ Str::limit($expense->project->name, 25) }}</flux:cell>
                            @endif --}}
                            <flux:cell>
                                {{-- :color="$estimate->project->last_status->title == 'Complete' ? 'green' : ($estimate->project->last_status->title == 'Active' ? 'blue' : ($estimate->project->last_status->title == 'Cancelled' ? 'red' : 'yellow'))" --}}
                                <flux:badge size="sm" :color="$estimate->status === 'Active' ? 'green' : 'red'" inset="top bottom">{{$estimate->status}}</flux:badge>
                            </flux:cell>

                            <flux:cell>
                                <flux:dropdown position="bottom" align="end">
                                    <flux:button square inset="top bottom" size="sm">
                                        <flux:icon.ellipsis-horizontal variant="solid" size="sm" />
                                    </flux:button>

                                    <flux:menu>
                                        @if($estimate->status === 'Active')
                                            <flux:menu.item href="{{route('estimates.show', $estimate->id)}}">Open</flux:menu.item>
                                            {{-- wire:click="$dispatchTo('projects.project-show', 'deleteEstimate', { estimate_id: {{$expense}} })"  --}}
                                            <flux:menu.item wire:click="deleteEstimate({{$estimate->id}})" variant="danger">Delete</flux:menu.item>
                                        @else
                                            <flux:menu.item wire:click="activateEstimate({{$estimate->id}})">Restore</flux:menu.item>
                                        @endif
                                    </flux:menu>
                                </flux:dropdown>
                            </flux:cell>
                        </flux:row>
                    @endforeach
                </flux:rows>
            </flux:table>
        </div>
    </flux:card>
</div>
