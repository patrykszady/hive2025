@php
    $task = $this->task;
@endphp

<div class="min-w-0 w-full">
@if($task)
    <flux:kanban.card
        as="button"
        class="min-w-0 w-full {{ $task->trashed() ? 'opacity-50' : '' }} {{ $this->isToday ? 'bg-white/70! dark:bg-zinc-700/70!' : '' }}"
        wire:click="editTask"
        wire:loading.attr="disabled"
        wire:loading.class="opacity-60 cursor-wait"
    >
        @include('components.upcoming-tasks-list-card-content', [
            'task' => $task,
            'date' => $this->dayFormat,
            'taskUsers' => $this->taskUsers,
            'arrivalTimeLabel' => $this->arrivalTimeLabel,
            'previousArrivalTimeLabel' => $this->previousArrivalTimeLabel,
            'isWeekend' => $this->isWeekend,
        ])
    </flux:kanban.card>
@endif
</div>
