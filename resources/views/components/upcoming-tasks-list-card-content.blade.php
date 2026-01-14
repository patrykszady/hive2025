<div class="flex items-start justify-between gap-2 min-w-0">
    <div class="flex items-center gap-2 min-w-0">
        <flux:heading size="sm" class="min-w-0 truncate {{ $taskTypeTextClasses }}">
            {{ $task->title }}
        </flux:heading>
        @if ($arrivalTimeLabel)
            <span class="shrink-0 text-xs text-zinc-500 dark:text-zinc-400 whitespace-nowrap">
                {{ $arrivalTimeLabel }}
            </span>
        @endif
    </div>
    @if($showDayCounter)
        <span class="text-xs text-zinc-500 dark:text-zinc-400 whitespace-nowrap">
            {{ $currentDay }}/{{ $totalDays }}
        </span>
    @endif
</div>

@if($showAvatars && ($taskUsers->count() > 0 || $taskVendor))
    <div class="flex items-center gap-2 mt-2 min-w-0">
        @if($taskUsers->count() > 0)
            <flux:avatar.group>
                @foreach($taskUsers->take(3) as $user)
                    <flux:avatar
                        circle
                        size="xs"
                        name="{{ $user->full_name }}"
                        color="auto"
                        color:seed="{{ $user->id }}"
                        title="{{ $user->full_name }}"
                    />
                @endforeach
                @if($taskUsers->count() > 3)
                    <flux:avatar circle size="xs">{{ $taskUsers->count() - 3 }}+</flux:avatar>
                @endif
            </flux:avatar.group>
        @endif

        @if($taskVendor)
            <flux:avatar
                circle
                size="xs"
                name="{{ $taskVendor->name }}"
                color="auto"
                color:seed="{{ $taskVendor->id }}"
                title="{{ $taskVendor->name }}"
            />
            <span class="flex-1 min-w-0 truncate text-xs text-zinc-600 dark:text-zinc-400">{{ $taskVendor->name }}</span>
            
            @if($task->vendor_status)
                @php $statusUi = $task->vendor_status_ui; @endphp
                <flux:badge 
                    size="sm" 
                    :color="$statusUi['flux'] ?? 'zinc'"
                    :icon="$statusUi['icon'] ?? null"
                >
                    {{ $statusUi['label'] ?? ucfirst($task->vendor_status) }}
                </flux:badge>
            @endif
        @endif
    </div>
@endif
