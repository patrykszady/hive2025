@props([
    'task',
    'date',
    'clickable' => false,
    'showAvatars' => true,
    'showVendorInfo' => true,
    'taskUsers' => null,
    'arrivalTimeLabel' => null,
])

@if($clickable)
    <flux:kanban.card
        as="button"
        class="min-w-0 w-full"
        wire:key="upcoming-task-{{ $task->id }}-{{ $date }}"
        wire:click="$dispatchTo('tasks.task-create', 'editTask', { task: {{ $task->id }} })"
    >
        @include('components.upcoming-tasks-list-card-content', [
            'task' => $task,
            'date' => $date,
            'showAvatars' => $showAvatars,
            'showVendorInfo' => $showVendorInfo,
            'taskUsers' => $taskUsers,
            'arrivalTimeLabel' => $arrivalTimeLabel,
        ])
    </flux:kanban.card>
@else
    <flux:kanban.card
        class="min-w-0 w-full transition hover:bg-zinc-50 dark:hover:bg-zinc-800 hover:shadow-sm hover:border-zinc-300 dark:hover:border-zinc-600"
        wire:key="upcoming-task-{{ $task->id }}-{{ $date }}"
    >
        @include('components.upcoming-tasks-list-card-content', [
            'task' => $task,
            'date' => $date,
            'showAvatars' => $showAvatars,
            'showVendorInfo' => $showVendorInfo,
            'taskUsers' => $taskUsers,
            'arrivalTimeLabel' => $arrivalTimeLabel,
        ])
    </flux:kanban.card>
@endif
