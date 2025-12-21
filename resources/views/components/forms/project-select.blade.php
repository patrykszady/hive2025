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

        @foreach($projects as $project)
            <flux:select.option wire:key="{{$project->id}}" value="{{$project->id}}">
                <div class="flex flex-col gap-0 w-full">
                    <div class="flex items-center w-full">
                        <span class="flex-1 min-w-0">{{ $project->short_address }}</span>

                        @if($showStatusBadge && $project->latestStatus)
                            <flux:badge size="sm" :color="$project->latestStatus->badgeColor" inset="top bottom" class="shrink-0 ml-2">
                                {{ $project->latestStatus->title }}
                            </flux:badge>
                        @endif
                    </div>

                    <i class="font-normal block w-full leading-tight">{{$project->project_name}}</i>
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

            @foreach($projects as $project)
                <flux:select.option wire:key="{{$project->id}}" value="{{$project->id}}">
                    <div class="flex flex-col gap-0 w-full">
                        <div class="flex items-center w-full">
                            <span class="flex-1 min-w-0">{{ $project->short_address }}</span>

                            @if($showStatusBadge && $project->latestStatus)
                                <flux:badge size="sm" :color="$project->latestStatus->badgeColor" inset="top bottom" class="shrink-0 ml-2">
                                    {{ $project->latestStatus->title }}
                                </flux:badge>
                            @endif
                        </div>

                        <i class="font-normal block w-full leading-tight">{{$project->project_name}}</i>
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
