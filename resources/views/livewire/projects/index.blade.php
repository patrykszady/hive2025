@php
    $projectStatuses = \App\Models\ProjectStatus::selectableStatuses();
@endphp

<div class="max-w-3xl space-y-2">
    @if($view === NULL)
        <x-island-card heading="Filters" :separator="true" class="mb-4">

            {{-- Filter inputs: skeleton until Alpine hydrates Flux components --}}
            <div
                x-data="{ ready: false }"
                x-init="$nextTick(() => ready = true)"
                class="grid grid-cols-1 sm:grid-cols-{{ auth()->user()->is_client_user ? '2' : '3' }} gap-4"
            >
                {{-- Labels always visible --}}
                <flux:field>
                    <flux:label>Project</flux:label>
                    <div x-show="!ready" class="h-10 w-full"></div>
                    <div x-show="ready" x-cloak x-transition.opacity.duration.150ms>
                        <flux:input
                            wire:model.live.debounce.400ms="project_name_search"
                            wire:input.debounce.400ms="$set('project_name_search', $event.target.value)"
                            icon="magnifying-glass"
                            placeholder="Search projects..."
                        />
                    </div>
                </flux:field>

                @if(!auth()->user()->is_client_user)
                    <flux:field>
                        <flux:label>Client</flux:label>
                        <div x-show="!ready" class="h-10 w-full"></div>
                        <div x-show="ready" x-cloak x-transition.opacity.duration.150ms>
                            <flux:select wire:model.live="client_id" variant="listbox" searchable clearable placeholder="All Clients...">
                                <x-slot name="search">
                                    <flux:select.search placeholder="Search..." />
                                </x-slot>
                                @foreach ($clients as $client)
                                    <flux:select.option value="{{$client->id}}">{{ $client->name }}</flux:select.option>
                                @endforeach
                            </flux:select>
                        </div>
                    </flux:field>
                @endif

                <flux:field>
                    <flux:label>Status</flux:label>
                    <div x-show="!ready" class="h-10 w-full"></div>
                    <div x-show="ready" x-cloak x-transition.opacity.duration.150ms>
                        <flux:select variant="listbox" multiple clearable placeholder="Choose status..." wire:model.live="project_status_title">
                            @foreach($projectStatuses as $status)
                                <flux:select.option :value="$status['code']">
                                    <flux:badge size="md" inset="top bottom" :color="$status['color']">
                                        {{ $status['label'] }}
                                    </flux:badge>
                                </flux:select.option>
                            @endforeach
                        </flux:select>
                    </div>
                </flux:field>
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
