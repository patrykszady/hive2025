{{--
    THE Planner project column — table, gantt AND the cards view all render it,
    so name + status + add button + client info + pending badge can't drift.

    Required:
        $project          – Project model
        $projectId        – int (project id; also used as row id for actions)
        $title            – string (display title, usually $project->short_address)
        $undatedCount     – int (count of pending/undated tasks for project)
    Optional (the cards view repeats the column per day):
        $date             – Y-m-d; the add button prefills this day
        $revealOnHover    – add button appears on cell hover (needs group/cell)
        $showStatus       – hide the status badge (narrow weekend columns)
        $showPending      – hide the chip (cards view shows the shared
                            pending-tasks CARD inside the column instead)
        $showActions      – same for the "from calls, texts & email" chip
                            (the cards view shows the shared action-items
                            CARD instead)
        $actionCount      – open action items from calls, texts & email
                            (defaults to the component's per-project count)
--}}
@php
    $latestStatus = $project->latestStatus;
    $date = $date ?? null;
    $revealOnHover = $revealOnHover ?? false;
    $showStatus = $showStatus ?? true;
    $showPending = $showPending ?? true;
    $showActions = $showActions ?? true;
    $actionCount = $actionCount ?? ($this->actionItemCounts[(int) $projectId] ?? 0);
    // Built here: quotes inside a {{ }} attribute survive as entities, and the
    // per-day columns need the date in both the payload and the wire:key.
    $addPayload = '{ project_id: ' . (int) $projectId . ($date ? ", date: '" . $date . "'" : '') . ' }';
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
    @if ($showStatus && $latestStatus)
        <flux:badge :color="$latestStatus->badge_color" size="sm" inset="top bottom" class="shrink-0">
            {{ $latestStatus->title }}
        </flux:badge>
    @endif
    <flux:button
        variant="subtle"
        icon="plus"
        size="xs"
        class="shrink-0 {{ $revealOnHover ? 'opacity-0 group-hover/cell:opacity-100 transition-opacity' : '' }}"
        wire:key="planner-add-task-{{ $projectId }}{{ $date ? '-' . $date : '' }}"
        wire:click="$dispatchTo('tasks.task-create', 'addTask', {{ $addPayload }})"
        aria-label="Add task"
    />
</div>

{{-- Two lines, never three: the client on the left, the two counts as
     icon chips on the right (their meaning in the tooltip). The third line of
     "1 pending · 93 from calls & texts" overflowed the timeline's rows, so
     the chip itself stays a bare count — only the tooltip spells out
     "calls, texts & email". --}}
<div class="flex items-center gap-1.5 min-w-0">
    <div class="flex-1 min-w-0 text-xs text-zinc-500 dark:text-zinc-400 truncate">
        {{ $project->client->last_names ?? '' }}{{ $project->project_name ? ' | ' . $project->project_name : '' }}
    </div>
    @if ($showPending && $undatedCount > 0)
        <flux:tooltip :content="$undatedCount . ' ' . \Illuminate\Support\Str::plural('task', $undatedCount) . ' to schedule'" position="top">
            <button
                type="button"
                x-on:click="window.dispatchEvent(new CustomEvent('open-undated-tasks', { detail: { projectId: {{ (int) $projectId }} } }))"
                class="shrink-0 inline-flex items-center gap-0.5 px-1.5 py-0.5 text-xs font-medium tabular-nums rounded-md bg-amber-100 text-amber-700 hover:bg-amber-200 dark:bg-amber-900/30 dark:text-amber-400 dark:hover:bg-amber-900/50 transition-colors"
                aria-label="{{ $undatedCount }} {{ \Illuminate\Support\Str::plural('task', $undatedCount) }} to schedule"
            >
                <flux:icon.calendar variant="micro" class="size-3" />{{ $undatedCount }}
            </button>
        </flux:tooltip>
    @endif
    @if ($showActions && $actionCount > 0)
        {{-- What the client's calls, texts and email asked for (ProjectActionItem). --}}
        <flux:tooltip :content="$actionCount . ' ' . \Illuminate\Support\Str::plural('to-do', $actionCount) . ' from calls, texts & email'" position="top">
            <button
                type="button"
                x-on:click="window.dispatchEvent(new CustomEvent('open-action-items', { detail: { projectId: {{ (int) $projectId }} } }))"
                class="shrink-0 inline-flex items-center gap-0.5 px-1.5 py-0.5 text-xs font-medium tabular-nums rounded-md bg-sky-100 text-sky-700 hover:bg-sky-200 dark:bg-sky-900/30 dark:text-sky-300 dark:hover:bg-sky-900/50 transition-colors"
                aria-label="{{ $actionCount }} {{ \Illuminate\Support\Str::plural('to-do', $actionCount) }} from calls, texts and email"
            >
                <flux:icon.chat-bubble-left-right variant="micro" class="size-3" />{{ $actionCount }}
            </button>
        </flux:tooltip>
    @endif
</div>
</div>
