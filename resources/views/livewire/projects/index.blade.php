<div class="max-w-3xl">
    @if($view === NULL)
        <flux:card class="space-y-2 mb-4">
            <div class="flex justify-between">
                <flux:heading size="lg">Filters</flux:heading>
            </div>
            {{-- NEW PROJECT MODAL --}}
            <livewire:projects.project-create :$clients />
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
                </flux:select>
            </div>
        </flux:card>
    @endif

    <flux:card class="space-y-2">
        <div class="flex justify-between">
            <flux:heading size="lg">Projects</flux:heading>
            @can('create', App\Models\Project::class)
                <flux:button wire:click="$dispatchTo('projects.project-create', 'newProject', { client_id: '{{$view === NULL ? $client_id : $client->id}}' })">Create Project</flux:button>
            @endcan
        </div>

        <div class="space-y-2">
            <flux:table :paginate="$this->projects">
                <flux:table.columns>
                    <flux:table.column>Address</flux:table.column>
                    @if($view != 'clients.index')
                        <flux:table.column>Client</flux:table.column>
                    @endif
                    <flux:table.column>Name</flux:table.column>
                    {{-- <flux:table.column sortable :sorted="$sortBy === 'date'" :direction="$sortDirection" wire:click="sort('date')">Date</flux:table.column>
                    @if($view != 'checks.show')
                        <flux:table.column >Vendor</flux:table.column>
                    @endif

                    @if($view != 'projects.show')
                        <flux:table.column>Project</flux:table.column>
                    @endif --}}
                    <flux:table.column>Status</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->projects as $project)
                        <flux:table.row :key="$project->id">
                            <flux:table.cell
                                wire:navigate.hover
                                href="{{route('projects.show', $project->id)}}"
                                variant="strong"
                                class="cursor-pointer"
                                >
                                {{ $project->address }}
                            </flux:table.cell>
                            @if($view != 'clients.index')
                                <flux:table.cell
                                    wire:navigate.hover
                                    href="{{route('clients.show', $project->client->id)}}"
                                    class="cursor-pointer"
                                    >
                                    {{ $project->client->name }}
                                </flux:table.cell>
                            @endif
                            <flux:table.cell>{{ $project->project_name }}</flux:table.cell>
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
                                <flux:badge size="sm" :color="$project->last_status->title == 'Complete' ? 'green' : ($project->last_status->title == 'Active' ? 'blue' : ($project->last_status->title == 'Cantable.celled' ? 'red' : 'yellow'))" inset="top bottom">{{ $project->last_status->title }}</flux:badge>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>
    </flux:card>
</div>
