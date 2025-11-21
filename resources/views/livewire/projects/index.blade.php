@php($projectStatuses = \App\Models\ProjectStatus::selectableStatuses())

<div class="max-w-3xl space-y-2">
    @if($view === NULL)
        <flux:card class="space-y-2 mb-4">
            <div class="flex justify-between">
                <flux:heading size="lg">Filters</flux:heading>
            </div>

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

                <flux:select variant="listbox" label="Status" multiple placeholder="Choose status..." wire:model.live="project_status_title">
                    @foreach($projectStatuses as $status)
                        <flux:select.option :value="$status['code']">
                            <flux:badge size="md" inset="top bottom" :color="$status['color']">
                                {{ $status['label'] }}
                            </flux:badge>
                        </flux:select.option>
                    @endforeach
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

        <div class="space-y-2 overflow-x-hidden">
            <flux:table :paginate="$this->projects" class="table-fixed w-full">
                <flux:table.columns>
                    @if($view == 'clients.index')
                        {{-- Order for client view: Name, Address, Status --}}
                        <flux:table.column class="w-[35%] min-w-0">Name</flux:table.column>
                        <flux:table.column class="w-[35%] min-w-0">Address</flux:table.column>
                    @else
                        {{-- Original order: Address, Client, Name, Status --}}
                        <flux:table.column class="w-[30%] min-w-0">Address</flux:table.column>
                        @if($view != 'clients.index')
                            <flux:table.column class="w-[25%] min-w-0">Client</flux:table.column>
                        @endif
                        <flux:table.column class="w-[25%] min-w-0">Name</flux:table.column>
                    @endif
                    <flux:table.column align="end" class="w-[30%] min-w-[5rem] shrink-0">Status</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->projects as $project)
                        <flux:table.row :key="$project->id">
                            @if($view == 'clients.index')
                                {{-- Order for client view: Name (bold & clickable), Address (regular), Status --}}
                                <flux:table.cell
                                    wire:navigate.hover
                                    href="{{route('projects.show', $project->id)}}"
                                    variant="strong" 
                                    class="cursor-pointer w-[35%] min-w-0">
                                    <div class="truncate whitespace-nowrap overflow-hidden text-ellipsis" title="{{ $project->project_name }}">{{ $project->project_name }}</div>
                                </flux:table.cell>
                                <flux:table.cell class="w-[35%] min-w-0">
                                    <div class="truncate whitespace-nowrap overflow-hidden text-ellipsis" title="{{ $project->address }}">{{ $project->address }}</div>
                                </flux:table.cell>
                            @else
                                {{-- Original order: Address (bold), Client, Name, Status --}}
                                <flux:table.cell
                                    wire:navigate.hover
                                    href="{{route('projects.show', $project->id)}}"
                                    variant="strong"
                                    class="cursor-pointer w-[30%] min-w-0"
                                    >
                                    <div class="truncate whitespace-nowrap overflow-hidden text-ellipsis" title="{{ $project->address }}">{{ $project->address }}</div>
                                </flux:table.cell>
                                @if($view != 'clients.index')
                                    <flux:table.cell
                                        wire:navigate.hover
                                        href="{{route('clients.show', $project->client->id)}}"
                                        class="cursor-pointer w-[25%] min-w-0"
                                        >
                                        <div class="truncate whitespace-nowrap overflow-hidden text-ellipsis" title="{{ $project->client->last_names }}">{{ $project->client->last_names }}</div>
                                    </flux:table.cell>
                                @endif
                                  <flux:table.cell class="w-[25%] min-w-0">
                                      <div class="truncate whitespace-nowrap overflow-hidden text-ellipsis" title="{{ $project->project_name }}">{{ $project->project_name }}</div>
                                  </flux:table.cell>
                            @endif
                            <flux:table.cell align="end" class="w-[30%] min-w-[5rem] shrink-0">
                                <flux:badge size="sm" :color="$project->latestStatus->badge_color" inset="top bottom">{{ $project->latestStatus->title }}</flux:badge>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>

        {{-- NEW PROJECT MODAL --}}
        <livewire:projects.project-create />
    </flux:card>
</div>
