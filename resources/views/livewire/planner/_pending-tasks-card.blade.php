{{--
    Shared "Pending Tasks" card used by Planner cards & gantt views.
    Single source of truth for the orange undated-tasks badge.

    Required: $projectId (int), $count (int)
    Optional: $dayIndex (int|null) — when set, card is x-show'd only on the
                                     first visible day (used by cards view).
--}}
@php
    $dayIndex = $dayIndex ?? null;
    $hasDayIndex = ! is_null($dayIndex);
    $wireKey = 'undated-tasks-' . $projectId . ($hasDayIndex ? '-idx' . $dayIndex : '');
    $xShow = $hasDayIndex ? 'firstVisibleDayIndex === ' . (int) $dayIndex : 'true';
@endphp

@if ($count > 0)
    <flux:kanban.card
        x-show="{{ $xShow }}"
        as="button"
        class="min-w-0 w-full {{ $hasDayIndex ? '[x-cloak]' : '' }}"
        wire:key="{{ $wireKey }}"
        wire:click="openUndatedTasksModal({{ $projectId }})"
        wire:target="openUndatedTasksModal({{ $projectId }})"
        wire:loading.attr="disabled"
        wire:loading.class="opacity-60 cursor-wait"
    >
        <div class="flex items-center justify-between gap-2 min-w-0">
            <flux:heading size="sm" class="min-w-0 truncate text-orange-600 dark:text-orange-400">
                Pending Tasks
            </flux:heading>
            <span class="text-xs text-zinc-500 dark:text-zinc-400 whitespace-nowrap">
                {{ $count }}
            </span>
        </div>
    </flux:kanban.card>
@endif
