@php
    $task = $this->task;
@endphp

<div class="min-w-0 w-full">
@if($task)
    @php
        $taskUsers = $this->taskUsers;
        $taskVendor = $task->vendor;
        $taskTypeTextClasses = data_get($task->type_ui, 'text', '');
        $counter = $this->dayCounter;
        $arrivalTimeLabel = $this->arrivalTimeLabel;
        $showDayCounter = $counter !== null;
        $currentDay = $counter['current'] ?? 0;
        $totalDays = $counter['total'] ?? 0;
        $showAvatars = true;
    @endphp

    <flux:kanban.card
        as="button"
        class="min-w-0 w-full"
        wire:click="editTask"
        wire:loading.attr="disabled"
        wire:loading.class="opacity-60 cursor-wait"
    >
        @include('components.upcoming-tasks-list-card-content', [
            'task' => $task,
            'taskTypeTextClasses' => $taskTypeTextClasses,
            'arrivalTimeLabel' => $arrivalTimeLabel,
            'showDayCounter' => $showDayCounter,
            'currentDay' => $currentDay,
            'totalDays' => $totalDays,
            'showAvatars' => $showAvatars,
            'taskUsers' => $taskUsers,
            'taskVendor' => $taskVendor,
            'showProjectInfo' => false,
        ])
    </flux:kanban.card>
@endif
</div>
