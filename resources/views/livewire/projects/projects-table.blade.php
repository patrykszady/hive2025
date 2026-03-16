<x-island-card heading="Projects" wire:transition>
    <x-slot:actions>
    @can('create', App\Models\Project::class)
        <flux:button size="sm" wire:click="$dispatchTo('projects.project-create', 'newProject', { client_id: '{{ $clientId }}' })">Create Project</flux:button>
    @endcan
    </x-slot:actions>

    <div class="space-y-2 overflow-x-hidden">
        <flux:table
            :paginate="$this->projects->hasPages() ? $this->projects : null"
            wire:loading.class="opacity-50 text-opacity-50"
            class="table-fixed w-full"
        >
            <flux:table.columns>
                @if($view == 'clients.index')
                    <flux:table.column class="w-[30%] min-w-0">Name</flux:table.column>
                    <flux:table.column class="w-[30%] min-w-0">Address</flux:table.column>
                    @if(auth()->user()->is_client_user)
                        <flux:table.column class="w-[25%] min-w-0">Contractor</flux:table.column>
                    @endif
                @else
                    <flux:table.column class="w-[30%] min-w-0">Address</flux:table.column>
                    @if($view != 'clients.index' && !auth()->user()->is_client_user)
                        <flux:table.column class="w-[25%] min-w-0">Client</flux:table.column>
                    @endif
                    <flux:table.column class="w-[25%] min-w-0">Name</flux:table.column>
                    @if(auth()->user()->is_client_user)
                        <flux:table.column class="w-[25%] min-w-0">Contractor</flux:table.column>
                    @endif
                @endif
                <flux:table.column align="end" class="w-[30%] min-w-[5rem] shrink-0">Status</flux:table.column>
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
                                <div class="truncate whitespace-nowrap overflow-hidden text-ellipsis" title="{{ $project->project_name }}">{{ $project->project_name }}</div>
                            </flux:table.cell>
                            <flux:table.cell
                                wire:navigate.hover
                                href="{{route('projects.show', $project->id)}}"
                                class="cursor-pointer w-[35%] min-w-0 hover:text-indigo-600 dark:hover:text-indigo-400">
                                <div class="truncate whitespace-nowrap overflow-hidden text-ellipsis" title="{{ $project->address }}">{{ $project->short_address }}</div>
                            </flux:table.cell>
                            @if(auth()->user()->is_client_user)
                                <flux:table.cell class="w-[25%] min-w-0">
                                    <div class="truncate whitespace-nowrap overflow-hidden text-ellipsis" title="{{ $project->createdByVendor?->business_name ?? $project->createdByVendor?->name }}">
                                        {{ $project->createdByVendor?->business_name ?? $project->createdByVendor?->name ?? '—' }}
                                    </div>
                                </flux:table.cell>
                            @endif
                        @else
                            <flux:table.cell
                                wire:navigate.hover
                                href="{{route('projects.show', $project->id)}}"
                                variant="strong"
                                class="cursor-pointer w-[30%] min-w-0 hover:text-indigo-600 dark:hover:text-indigo-400"
                                >
                                <div class="truncate whitespace-nowrap overflow-hidden text-ellipsis" title="{{ $project->address }}">{{ $project->short_address }}</div>
                            </flux:table.cell>
                            @if($view != 'clients.index' && !auth()->user()->is_client_user)
                                <flux:table.cell
                                    wire:navigate.hover
                                    href="{{route('clients.show', $project->client->id)}}"
                                    class="cursor-pointer w-[25%] min-w-0 hover:text-indigo-600 dark:hover:text-indigo-400"
                                    >
                                    <div class="truncate whitespace-nowrap overflow-hidden text-ellipsis" title="{{ $project->client->last_names }}">{{ $project->client->last_names }}</div>
                                </flux:table.cell>
                            @endif
                            <flux:table.cell
                                wire:navigate.hover
                                href="{{route('projects.show', $project->id)}}"
                                class="cursor-pointer w-[25%] min-w-0 hover:text-indigo-600 dark:hover:text-indigo-400">
                                <div class="truncate whitespace-nowrap overflow-hidden text-ellipsis" title="{{ $project->project_name }}">{{ $project->project_name }}</div>
                            </flux:table.cell>
                            @if(auth()->user()->is_client_user)
                                <flux:table.cell class="w-[25%] min-w-0">
                                    <div class="truncate whitespace-nowrap overflow-hidden text-ellipsis" title="{{ $project->createdByVendor?->business_name ?? $project->createdByVendor?->name }}">
                                        {{ $project->createdByVendor?->business_name ?? $project->createdByVendor?->name ?? '—' }}
                                    </div>
                                </flux:table.cell>
                            @endif
                        @endif
                        <flux:table.cell align="end" class="w-[30%] min-w-[5rem] shrink-0">
                            @if(auth()->user()->is_client_user)
                                <flux:badge size="sm" :color="$project->latestStatus->badge_color" inset="top bottom">{{ $project->latestStatus->title }}</flux:badge>
                            @else
                                <div x-data="{ status: {{ $project->latestStatus->status_code }} }" x-init="$watch('status', value => $wire.updateProjectStatus({{ $project->id }}, value))">
                                    <flux:select
                                        x-model="status"
                                        variant="listbox"
                                        size="sm"
                                        class="!min-w-0"
                                    >
                                        @foreach($projectStatuses as $status)
                                            <flux:select.option :value="$status['code']">
                                                <flux:badge size="sm" inset="top bottom" :color="$status['color']">
                                                    {{ $status['label'] }}
                                                </flux:badge>
                                            </flux:select.option>
                                        @endforeach
                                    </flux:select>
                                </div>
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </div>

    <livewire:projects.project-create />
</x-island-card>
