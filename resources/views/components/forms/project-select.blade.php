@props([
    'projects',
    'model' => 'project_id',
    'placeholder' => 'Choose project...',
    'label' => 'Project',
    'showLabel' => true,
    'showStatusBadge' => true,
    'distributions' => null,
    'inInputGroup' => false,
])

@php
    // Normalize projects to objects in case they were serialized as arrays by Livewire
    $normalizedProjects = collect($projects)->map(fn ($p) => is_array($p) ? (object) $p : $p);
@endphp

@if($inInputGroup)
    <flux:select 
        wire:model.live="{{ $model }}" 
        variant="listbox" 
        placeholder="{{ $placeholder }}"
        searchable
        class="[&_[data-flux-select-trigger]]:min-h-[4rem] [&_[data-flux-select-trigger]]:py-3"
        {{ $attributes }}
    >
        <x-slot name="search">
            <flux:select.search placeholder="Search..." />
        </x-slot>

        @foreach($normalizedProjects as $project)
            <flux:select.option wire:key="{{ data_get($project, 'id') }}" value="{{ data_get($project, 'id') }}">
                <div class="flex flex-col gap-0 w-full">
                    <div class="flex items-center w-full">
                        <span class="flex-1 min-w-0">{{ data_get($project, 'short_address') }}</span>

                        @php
                            $latestStatus = data_get($project, 'latestStatus') ?? data_get($project, 'latest_status');
                            if (is_array($latestStatus)) $latestStatus = (object) $latestStatus;
                        @endphp
                        @if($showStatusBadge && $latestStatus)
                            <flux:badge size="sm" :color="data_get($latestStatus, 'badgeColor') ?? data_get($latestStatus, 'badge_color')" inset="top bottom" class="shrink-0 ml-2">
                                {{ data_get($latestStatus, 'title') }}
                            </flux:badge>
                        @endif
                    </div>

                    <i class="font-normal block w-full leading-tight">{{ data_get($project, 'project_name') }}</i>
                </div>
            </flux:select.option>
        @endforeach

        @if($distributions)
            <flux:select.option disabled>--------------</flux:select.option>

            @foreach($distributions as $distribution)
                <flux:select.option wire:key="D:{{$distribution->id}}" value="D:{{$distribution->id}}">{{$distribution->name}}</flux:select.option>
            @endforeach
        @endif
    </flux:select>
@else
    <flux:field>
        @if($showLabel)
            <flux:label>{{ $label }}</flux:label>
        @endif
        
        <flux:select 
            wire:model.live="{{ $model }}" 
            variant="listbox" 
            placeholder="{{ $placeholder }}"
            searchable
            class="[&_[data-flux-select-trigger]]:min-h-[4rem] [&_[data-flux-select-trigger]]:py-3"
            {{ $attributes }}
        >
            <x-slot name="search">
                <flux:select.search placeholder="Search..." />
            </x-slot>

            @foreach($normalizedProjects as $project)
                <flux:select.option wire:key="{{ data_get($project, 'id') }}" value="{{ data_get($project, 'id') }}">
                    <div class="flex flex-col gap-0 w-full">
                        <div class="flex items-center w-full">
                            <span class="flex-1 min-w-0">{{ data_get($project, 'short_address') }}</span>

                            @php
                                $latestStatus = data_get($project, 'latestStatus') ?? data_get($project, 'latest_status');
                                if (is_array($latestStatus)) $latestStatus = (object) $latestStatus;
                            @endphp
                            @if($showStatusBadge && $latestStatus)
                                <flux:badge size="sm" :color="data_get($latestStatus, 'badgeColor') ?? data_get($latestStatus, 'badge_color')" inset="top bottom" class="shrink-0 ml-2">
                                    {{ data_get($latestStatus, 'title') }}
                                </flux:badge>
                            @endif
                        </div>

                        <i class="font-normal block w-full leading-tight">{{ data_get($project, 'project_name') }}</i>
                    </div>
                </flux:select.option>
            @endforeach

            @if($distributions)
                <flux:select.option disabled>--------------</flux:select.option>

                @foreach($distributions as $distribution)
                    <flux:select.option wire:key="D:{{$distribution->id}}" value="D:{{$distribution->id}}">{{$distribution->name}}</flux:select.option>
                @endforeach
            @endif
        </flux:select>
    </flux:field>
@endif
