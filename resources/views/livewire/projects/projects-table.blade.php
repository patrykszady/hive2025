<x-index-table heading="Projects" :paginator="$this->projects" wire:transition>
    <x-slot:actions>
        @include('livewire.projects.partials.projects-table-actions')
    </x-slot:actions>

    {{-- Modal host BEFORE the flush wrapper: anything after it re-introduces
         bottom whitespace via the card's space-y gap. --}}
    <x-slot:before>
        <livewire:projects.project-create />
    </x-slot:before>

    {{-- No projects: show the card heading alone, without empty table headers. --}}
    @if($this->projects->isNotEmpty())
        <flux:table
            wire:loading.class="opacity-50 text-opacity-50"
            class="compact-table {{ $view == 'clients.index' ? 'table-fixed min-w-0 w-full' : 'index-table' }} [:where(&)]:p-0 [:where(&)]:space-y-0"
        >
            <flux:table.columns>
                @if($view == 'clients.index')
                    <flux:table.column class="w-[30%] min-w-0">Name</flux:table.column>
                    <flux:table.column class="w-[30%] min-w-0">Address</flux:table.column>
                    @if(auth()->user()->is_browsing_as_client)
                        <flux:table.column class="w-[25%] min-w-0">Contractor</flux:table.column>
                    @endif
                @else
                    <flux:table.column class="w-[30%] min-w-0">Address</flux:table.column>
                    @if($view != 'clients.index' && !auth()->user()->is_browsing_as_client)
                        <flux:table.column class="w-[25%] min-w-0">Client</flux:table.column>
                    @endif
                    <flux:table.column class="w-[25%] min-w-0">Name</flux:table.column>
                    @if(auth()->user()->is_browsing_as_client)
                        <flux:table.column class="w-[25%] min-w-0">Contractor</flux:table.column>
                    @endif
                @endif
                <flux:table.column class="w-[30%] min-w-[5rem] shrink-0">Status</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @php($projectStatuses = \App\Models\ProjectStatus::selectableStatuses())
                @foreach ($this->projects as $project)
                    <flux:table.row :key="$project->id">
                        @if($view == 'clients.index')
                            <flux:table.cell
                                wire:navigate.hover
                                href="{{route('projects.show', $project->id)}}"
                                variant="strong" 
                                class="cursor-pointer w-[35%] min-w-0 hover:text-indigo-600 dark:hover:text-indigo-400">
                                <x-truncate-tooltip :content="$project->project_name"><div class="truncate">{{ $project->project_name }}</div></x-truncate-tooltip>
                            </flux:table.cell>
                            <flux:table.cell
                                wire:navigate.hover
                                href="{{route('projects.show', $project->id)}}"
                                class="cursor-pointer w-[35%] min-w-0 hover:text-indigo-600 dark:hover:text-indigo-400">
                                <x-truncate-tooltip :content="$project->address"><div class="truncate">{{ $project->short_address }}</div></x-truncate-tooltip>
                            </flux:table.cell>
                            @if(auth()->user()->is_browsing_as_client)
                                <flux:table.cell class="w-[25%] min-w-0">
                                    <x-truncate-tooltip :content="$project->createdByVendor?->business_name ?? $project->createdByVendor?->name ?? ''"><div class="truncate">{{ $project->createdByVendor?->business_name ?? $project->createdByVendor?->name ?? '—' }}</div></x-truncate-tooltip>
                                </flux:table.cell>
                            @endif
                        @else
                            <flux:table.cell
                                wire:navigate.hover
                                href="{{route('projects.show', $project->id)}}"
                                variant="strong"
                                class="cursor-pointer w-[30%] min-w-0 hover:text-indigo-600 dark:hover:text-indigo-400"
                                >
                                <x-truncate-tooltip :content="$project->address"><div class="truncate">{{ $project->short_address }}</div></x-truncate-tooltip>
                            </flux:table.cell>
                            @if($view != 'clients.index' && !auth()->user()->is_browsing_as_client)
                                @if($project->client)
                                    <flux:table.cell
                                        wire:navigate.hover
                                        href="{{route('clients.show', $project->client->id)}}"
                                        class="cursor-pointer w-[25%] min-w-0 hover:text-indigo-600 dark:hover:text-indigo-400"
                                    >
                                        <x-truncate-tooltip :content="$project->client->last_names"><div class="truncate">{{ $project->client->last_names }}</div></x-truncate-tooltip>
                                    </flux:table.cell>
                                @else
                                    <flux:table.cell class="w-[25%] min-w-0">
                                        <div class="truncate whitespace-nowrap overflow-hidden text-ellipsis">&mdash;</div>
                                    </flux:table.cell>
                                @endif
                            @endif
                            <flux:table.cell
                                wire:navigate.hover
                                href="{{route('projects.show', $project->id)}}"
                                class="cursor-pointer w-[25%] min-w-0 hover:text-indigo-600 dark:hover:text-indigo-400">
                                <x-truncate-tooltip :content="$project->project_name"><div class="truncate">{{ $project->project_name }}</div></x-truncate-tooltip>
                            </flux:table.cell>
                            @if(auth()->user()->is_browsing_as_client)
                                <flux:table.cell class="w-[25%] min-w-0">
                                    <x-truncate-tooltip :content="$project->createdByVendor?->business_name ?? $project->createdByVendor?->name ?? ''"><div class="truncate">{{ $project->createdByVendor?->business_name ?? $project->createdByVendor?->name ?? '—' }}</div></x-truncate-tooltip>
                                </flux:table.cell>
                            @endif
                        @endif
                        <flux:table.cell class="w-[30%] min-w-[5rem] shrink-0">
                            @php($vendorStatus = $project->latestVendorStatus())
                            @if(auth()->user()->is_browsing_as_client)
                                <flux:badge size="sm" :color="$vendorStatus->badge_color" inset="top bottom">{{ $vendorStatus->title }}</flux:badge>
                            @else
                                <x-status-select
                                    :value="$vendorStatus->status_code"
                                    :options="$projectStatuses"
                                    method="updateProjectStatus"
                                    :model-id="$project->id"
                                />
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif
</x-index-table>
