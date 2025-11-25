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

    {{-- EMAIL TRACKING CARD --}}
    <flux:card class="space-y-2">
        <div class="flex justify-between items-center">
            <flux:heading size="lg">Email Tracking</flux:heading>
            <flux:badge size="sm" color="zinc">{{ $this->emailTrackingEvents->total() }} events</flux:badge>
        </div>

        <flux:separator variant="subtle" />

        <div class="space-y-2 overflow-x-auto">
            <flux:table :paginate="$this->emailTrackingEvents">
                <flux:table.columns>
                    <flux:table.column>Event</flux:table.column>
                    <flux:table.column>Template</flux:table.column>
                    <flux:table.column>Project</flux:table.column>
                    <flux:table.column>Recipients</flux:table.column>
                    <flux:table.column>Link</flux:table.column>
                    <flux:table.column>Date</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @forelse ($this->emailTrackingEvents as $event)
                        <flux:table.row :key="$event->id">
                            <flux:table.cell>
                                <flux:badge 
                                    size="sm" 
                                    :color="match($event->event_type) {
                                        'opened' => 'blue',
                                        'clicked' => 'green',
                                        'replied' => 'purple',
                                        default => 'zinc'
                                    }"
                                    inset="top bottom">
                                    {{ ucfirst($event->event_type) }}
                                </flux:badge>
                            </flux:table.cell>
                            <flux:table.cell>
                                @if($event->email_template_name)
                                    <flux:badge size="sm" color="zinc" variant="outline">
                                        {{ $event->email_template_name }}
                                    </flux:badge>
                                @else
                                    <span class="text-gray-400">-</span>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>
                                @if($event->project)
                                    <a wire:navigate.hover href="{{ route('projects.show', $event->project_id) }}" class="text-blue-600 hover:underline">
                                        {{ $event->project->project_name }}
                                    </a>
                                @else
                                    <span class="text-gray-400">-</span>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>
                                @if($event->recipient_users && $event->recipient_users->isNotEmpty())
                                    <div class="text-sm">
                                        @foreach($event->recipient_users->take(2) as $index => $user)
                                            <span 
                                                class="cursor-help" 
                                                title="{{ $user->email }}">
                                                {{ $user->first_name }} {{ $user->last_name }}{{ $index < min(1, $event->recipient_users->count() - 1) ? ',' : '' }}
                                            </span>
                                        @endforeach
                                        @if($event->recipient_users->count() > 2)
                                            <span class="text-gray-500 cursor-help" title="{{ implode(', ', $event->all_recipient_emails) }}">
                                                +{{ $event->recipient_users->count() - 2 }} more
                                            </span>
                                        @endif
                                    </div>
                                @elseif($event->all_recipient_emails && count($event->all_recipient_emails) > 0)
                                    <div class="text-sm text-gray-500 cursor-help" title="{{ implode(', ', $event->all_recipient_emails) }}">
                                        {{ implode(', ', array_slice($event->all_recipient_emails, 0, 2)) }}
                                        @if(count($event->all_recipient_emails) > 2)
                                            <span>+{{ count($event->all_recipient_emails) - 2 }} more</span>
                                        @endif
                                    </div>
                                @else
                                    <span class="text-gray-400">-</span>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>
                                @if($event->link_url)
                                    <a href="{{ $event->link_url }}" target="_blank" class="text-blue-600 hover:underline text-sm truncate block max-w-xs" title="{{ $event->link_url }}">
                                        {{ Str::limit($event->link_url, 40) }}
                                    </a>
                                @else
                                    <span class="text-gray-400">-</span>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>
                                <span class="text-sm text-gray-600" title="{{ $event->event_at->format('M d, Y g:i A') }}">
                                    {{ $event->event_at->diffForHumans() }}
                                </span>
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="5" class="text-center text-gray-500 py-8">
                                No email tracking events found.
                            </flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </div>
    </flux:card>
</div>
