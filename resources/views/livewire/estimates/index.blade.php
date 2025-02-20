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
                        <flux:select.option value="{{$client->id}}">{{ $client->name }}</flux:select.option>
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
                <flux:table.columns>
                    <flux:table.column>Estimate</flux:table.column>
                    <flux:table.column>Amount</flux:table.column>
                    <flux:table.column>Date</flux:table.column>
                    @if($view === 'estimates.index')
                        <flux:table.column>Client</flux:table.column>
                    @endif
                    <flux:table.column>Status</flux:table.column>
                    <flux:table.column></flux:table.column>

                    {{-- <flux:table.column sortable :sorted="$sortBy === 'date'" :direction="$sortDirection" wire:click="sort('date')">Date</flux:table.column>
                    @if($view != 'checks.show')
                        <flux:table.column >Vendor</flux:table.column>
                    @endif

                    @if($view != 'projects.show')
                        <flux:table.column>Project</flux:table.column>
                    @endif --}}
                </flux:table.columns>

                <flux:table.rows>
                    @foreach($this->estimates as $estimate)
                        <flux:table.row :key="$estimate->id">
                            @if($estimate->status === 'Active')
                                <flux:table.cell
                                    wire:navigate.hover
                                    href="{{route('estimates.show', $estimate->id)}}"
                                    variant="strong"
                                    class="cursor-pointer"
                                    >
                                    # {{ $estimate->id }}
                                </flux:table.cell>
                            @else
                                <flux:table.cell>
                                    # {{ $estimate->id }}
                                </flux:table.cell>
                            @endif

                            <flux:table.cell>{{ money($estimate->estimate_sections->sum('total')) }}</flux:table.cell>
                            <flux:table.cell>{{ $estimate->created_at->format('m/d/Y') }}</flux:table.cell>
                            @if($view === 'estimates.index')
                                <flux:table.cell
                                    wire:navigate.hover
                                    href="{{route('clients.show', $estimate->project->client->id)}}"
                                    class="cursor-pointer"
                                    >
                                    {{ $estimate->project->client->name }}
                                </flux:table.cell>
                            @endif

                            {{-- <flux:table.cell
                                wire:click="$dispatchTo('projects.expense-create', 'editExpense', { expense: {{$project->id}}})"
                                variant="strong"
                                class="cursor-pointer"
                                >
                                {{ $project->address }}
                            </flux:table.cell>
                            <flux:table.cell>{{ $expense->date->format('m/d/Y') }}</flux:table.cell>
                            @if($view != 'checks.show')
                                <flux:table.cell><a wire:navigate.hover href="{{route('vendors.show', $expense->vendor->id)}}">{{Str::limit($expense->vendor->name, 20)}}</a></flux:table.cell>
                            @endif
                            @if($view != 'projects.show')
                                <flux:table.cell>{{ Str::limit($expense->project->name, 25) }}</flux:table.cell>
                            @endif --}}
                            <flux:table.cell>
                                {{-- :color="$estimate->project->last_status->title == 'Complete' ? 'green' : ($estimate->project->last_status->title == 'Active' ? 'blue' : ($estimate->project->last_status->title == 'Cancelled' ? 'red' : 'yellow'))" --}}
                                <flux:badge size="sm" :color="$estimate->status === 'Active' ? 'green' : 'red'" inset="top bottom">{{$estimate->status}}</flux:badge>
                            </flux:table.cell>

                            <flux:table.cell>
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
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>
    </flux:card>
</div>
