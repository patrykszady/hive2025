{{--
    Shared sticky-left project sidebar cell used by Planner table & gantt views.
    Single source of truth for project name + status + add button + client info + pending badge.

    Required:
        $project          – Project model
        $projectId        – int (project id; also used as row id for actions)
        $title            – string (display title, usually $project->short_address)
        $undatedCount     – int (count of pending/undated tasks for project)
--}}
@php
    $latestStatus = $project->latestStatus;
@endphp

<div class="flex flex-col gap-1 w-full min-w-0">
<div class="flex items-center gap-2 min-w-0">
    <a
        href="{{ route('projects.show', $project) }}"
        target="_blank"
        rel="noopener noreferrer"
        class="flex-1 min-w-0 font-medium text-sm text-zinc-900 dark:text-zinc-100 truncate hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors"
        title="{{ $title }}"
    >
        {{ $title }}
    </a>
    @if ($latestStatus)
        <flux:badge :color="$latestStatus->badge_color" size="sm" inset="top bottom" class="shrink-0">
            {{ $latestStatus->title }}
        </flux:badge>
    @endif
    <flux:button
        variant="subtle"
        icon="plus"
        size="xs"
        class="shrink-0"
        wire:key="planner-add-task-{{ $projectId }}"
        wire:click="addTask({{ $projectId }})"
        aria-label="Add task"
    />
</div>

<div class="text-xs text-zinc-500 dark:text-zinc-400 truncate">
    {{ $project->client->last_names ?? '' }}{{ $project->project_name ? ' | ' . $project->project_name : '' }}
</div>

@if ($undatedCount > 0)
    <button
        type="button"
        wire:click="openUndatedTasksModal({{ $projectId }})"
        class="self-start inline-flex items-center px-2 py-0.5 text-xs font-medium rounded-md bg-amber-100 text-amber-700 hover:bg-amber-200 dark:bg-amber-900/30 dark:text-amber-400 dark:hover:bg-amber-900/50 transition-colors"
    >
        {{ $undatedCount }} pending
    </button>
@endif
</div>
