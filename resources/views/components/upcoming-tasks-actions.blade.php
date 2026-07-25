{{-- Header buttons for tasks cards — included by BOTH the real
     x-upcoming-tasks-list and its loading skeleton, so the header is real and
     usable from the first paint (never skeleton pills). The "+" dispatches to
     the tasks.task-create component, which is mounted separately on the page,
     so it works even while this card's rows are still loading. --}}
@props([
    'projectId' => null,
    'clientId' => null,
    'showAddTask' => false,
    'clickable' => true,
    'showNotifications' => true,
])
<div class="flex items-center gap-2">
    @if($projectId && $clickable)
        <flux:button
            size="sm"
            variant="ghost"
            icon="plus"
            wire:click="$dispatchTo('tasks.task-create', 'addTask', { project_id: {{ $projectId }} })"
        />
    @elseif($showAddTask && $clickable)
        <flux:button
            size="sm"
            variant="ghost"
            icon="plus"
            wire:click="$dispatchTo('tasks.task-create', 'addTask', { {{ $clientId ? 'client_id: ' . $clientId . ', ' : '' }}user_ids: [{{ auth()->id() }}] })"
        />
    @endif
    @if($showNotifications)
        @auth
            <flux:button
                size="sm"
                variant="filled"
                :href="route('users.show', auth()->id())"
                icon="bell"
                class="!bg-indigo-500 hover:!bg-indigo-600 !text-white"
            >
                Notifications
            </flux:button>
        @endauth
    @endif
</div>
