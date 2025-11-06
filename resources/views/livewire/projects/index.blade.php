<div class="max-w-3xl">
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

                <flux:select wire:model.live="project_status_title" label="Status" placeholder="Status...">
                    @include('livewire.projects._status_options')
                </flux:select>
            </div>
        </flux:card>
    @endif

    <flux:card class="space-y-2">
        <div class="flex justify-between items-center">
            <flux:heading size="lg">Projects</flux:heading>
            @can('create', App\Models\Project::class)
                <flux:button wire:click="$dispatchTo('projects.project-create', 'newProject', { client_id: '{{$view === NULL ? $client_id : $client->id}}' })">Create Project</flux:button>
            @endcan
        </div>

        @if($view === NULL && $project_status_title && $project_status_title !== 'ALL')
            @php
                $currentStat = collect($this->stats)->firstWhere(function($stat) {
                    $statusMap = [
                        'Active' => 'Active',
                        'Estimate' => 'Estimate',
                        'Awaiting Response' => 'Response',
                        'Scheduled' => 'Scheduled',
                    ];
                    return ($statusMap[$this->project_status_title] ?? null) === $stat['title'];
                });
            @endphp
            @if($currentStat)
                <flux:separator variant="subtle" />
                <div wire:key="stat-{{ $project_status_title }}" class="relative h-32 rounded-lg border border-zinc-200 dark:border-zinc-700 overflow-hidden">
                    <div class="absolute inset-0 flex items-end -mx-2 -mb-2">
                        <flux:chart class="w-full h-full" :value="$currentStat['chartData']">
                            <flux:chart.svg gutter="8">
                                <flux:chart.line class="text-sky-200 dark:text-sky-400" />
                                <flux:chart.area class="text-sky-100 dark:text-sky-400/30" />
                            </flux:chart.svg>
                        </flux:chart>
                    </div>
                    <div class="relative z-10 flex flex-col justify-start pt-4 h-full px-6 pointer-events-none">
                        <div class="text-sm font-medium text-zinc-600 dark:text-zinc-400">{{ $currentStat['title'] }}</div>
                        <div class="text-4xl font-semibold text-zinc-900 dark:text-zinc-100 tabular-nums">{{ $currentStat['value'] }}</div>
                    </div>
                </div>
            @endif
        @endif

        <flux:separator variant="subtle" />

        <div class="space-y-2">
            <flux:table :paginate="$this->projects">
                <flux:table.columns>
                    @if($view == 'clients.index')
                        {{-- Order for client view: Name, Address, Status --}}
                        <flux:table.column>Name</flux:table.column>
                        <flux:table.column>Address</flux:table.column>
                    @else
                        {{-- Original order: Address, Client, Name, Status --}}
                        <flux:table.column>Address</flux:table.column>
                        @if($view != 'clients.index')
                            <flux:table.column>Client</flux:table.column>
                        @endif
                        <flux:table.column>Name</flux:table.column>
                    @endif
                    <flux:table.column>Status</flux:table.column>
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
                                    class="cursor-pointer">
                                    {{ $project->project_name }}
                                </flux:table.cell>
                                <flux:table.cell>
                                    {{ $project->address }}
                                </flux:table.cell>
                            @else
                                {{-- Original order: Address (bold), Client, Name, Status --}}
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
                            @endif
                            <flux:table.cell>
                                <flux:badge size="sm" :color="$project->latestStatus->title == 'Complete' ? 'green' : ($project->latestStatus->title == 'Active' ? 'blue' : ($project->latestStatus->title == 'Cancelled' ? 'red' : 'yellow'))" inset="top bottom">{{ $project->latestStatus->title }}</flux:badge>
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
