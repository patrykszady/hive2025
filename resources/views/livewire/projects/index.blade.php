@php
    $projectStatuses = \App\Models\ProjectStatus::selectableStatuses();
@endphp

<div class="max-w-3xl space-y-2">
    @if($view === NULL && !auth()->user()->is_client_user)
        {{-- Mobile: flux:accordion collapsed by default --}}
        <flux:card class="!px-5 !py-2 mb-4 sm:hidden">
            <flux:accordion transition>
                <flux:accordion.item>
                    <flux:accordion.heading>
                        <flux:heading size="lg">Filters</flux:heading>
                    </flux:accordion.heading>
                    <flux:accordion.content>
                        <div class="flex flex-col gap-4">
                            <div class="flex-1 min-w-0">
                                <flux:field>
                                    <flux:label>Project</flux:label>
                                    <flux:input
                                        wire:model.live.debounce.400ms="project_name_search"
                                        icon="magnifying-glass"
                                        placeholder="Search projects..."
                                    />
                                </flux:field>
                            </div>

                            <div class="flex-1 min-w-0">
                                <flux:field>
                                    <flux:label>Client</flux:label>
                                    <flux:select wire:model.live="client_id" variant="listbox" searchable clearable placeholder="All Clients...">
                                        <x-slot name="search">
                                            <flux:select.search placeholder="Search..." />
                                        </x-slot>
                                        @foreach ($clients as $client)
                                            <flux:select.option value="{{$client->id}}">{{ $client->name }}</flux:select.option>
                                        @endforeach
                                    </flux:select>
                                </flux:field>
                            </div>

                            <div class="flex-1 min-w-0">
                                <flux:field>
                                    <flux:label>Status</flux:label>
                                    <flux:select variant="listbox" multiple clearable placeholder="Choose status..." wire:model.live="project_status_title">
                                        @foreach($projectStatuses as $status)
                                            <flux:select.option :value="$status['code']">
                                                <flux:badge size="md" inset="top bottom" :color="$status['color']">
                                                    {{ $status['label'] }}
                                                </flux:badge>
                                            </flux:select.option>
                                        @endforeach
                                    </flux:select>
                                </flux:field>
                            </div>
                        </div>
                    </flux:accordion.content>
                </flux:accordion.item>
            </flux:accordion>
        </flux:card>

        {{-- Desktop: always expanded, no accordion --}}
        <x-island-card heading="Filters" :separator="true" class="mb-4 hidden sm:block">
            <div class="flex flex-row items-end gap-4">
                <div class="flex-1 min-w-0">
                    <flux:field>
                        <flux:label>Project</flux:label>
                        <flux:input
                            wire:model.live.debounce.400ms="project_name_search"
                            icon="magnifying-glass"
                            placeholder="Search projects..."
                        />
                    </flux:field>
                </div>

                <div class="flex-1 min-w-0">
                    <flux:field>
                        <flux:label>Client</flux:label>
                        <flux:select wire:model.live="client_id" variant="listbox" searchable clearable placeholder="All Clients...">
                            <x-slot name="search">
                                <flux:select.search placeholder="Search..." />
                            </x-slot>
                            @foreach ($clients as $client)
                                <flux:select.option value="{{$client->id}}">{{ $client->name }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </flux:field>
                </div>

                <div class="flex-1 min-w-0">
                    <flux:field>
                        <flux:label>Status</flux:label>
                        <flux:select variant="listbox" multiple clearable placeholder="Choose status..." wire:model.live="project_status_title">
                            @foreach($projectStatuses as $status)
                                <flux:select.option :value="$status['code']">
                                    <flux:badge size="md" inset="top bottom" :color="$status['color']">
                                        {{ $status['label'] }}
                                    </flux:badge>
                                </flux:select.option>
                            @endforeach
                        </flux:select>
                    </flux:field>
                </div>
            </div>
        </x-island-card>
    @endif

    <livewire:projects.projects-table
        :project-name-search="$project_name_search"
        :client-id="$client_id"
        :client-vendor-id="$client?->vendor_id"
        :project-status-title="$project_status_title"
        :view="$view"
        lazy
    />

    @if(!auth()->user()->is_client_user)
        <livewire:projects.email-tracking-table
            :client-id="$client_id"
            lazy
        />
    @endif
</div>
