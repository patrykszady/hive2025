{{--
    Shared "Calls, texts & email" card for the planner cards view: the open
    action items a project's calls, texts and email asked for
    (ProjectActionItem), opening the same modal as the sidebar chip.

    Required: $projectId (int), $count (int)
    Optional: $dayIndex (int|null) — when set, card is x-show'd only on the
                                     first visible day (as the pending card).
--}}
@php
    $dayIndex = $dayIndex ?? null;
    $hasDayIndex = ! is_null($dayIndex);
    $wireKey = 'action-items-' . $projectId . ($hasDayIndex ? '-idx' . $dayIndex : '');
    $xShow = $hasDayIndex ? 'firstVisibleDayIndex === ' . (int) $dayIndex : 'true';
@endphp

@if ($count > 0)
    <flux:kanban.card
        x-show="{{ $xShow }}"
        as="button"
        class="min-w-0 w-full {{ $hasDayIndex ? '[x-cloak]' : '' }}"
        wire:key="{{ $wireKey }}"
        x-on:click="window.dispatchEvent(new CustomEvent('open-action-items', { detail: { projectId: {{ (int) $projectId }} } }))"
    >
        <div class="flex items-center justify-between gap-2 min-w-0">
            <flux:heading size="sm" class="min-w-0 truncate text-sky-700 dark:text-sky-400">
                Calls, texts &amp; email
            </flux:heading>
            <span class="text-xs text-zinc-500 dark:text-zinc-400 whitespace-nowrap">
                {{ $count }}
            </span>
        </div>
    </flux:kanban.card>
@endif
