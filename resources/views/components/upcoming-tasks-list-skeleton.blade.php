@props([
    'title' => 'Tasks',
    'showProjectInfo' => false,
    'actionsWidth' => 'w-32', {{-- kept for BC; no longer renders anything --}}
    'count' => null,
    {{-- Per-day task-card counts, one entry per date block the loaded card will
         render (its window is padded to a fixed 8 days, so empty days count
         too). Supersedes `count`, which only sets the number of blocks. --}}
    'taskCounts' => null,
    {{-- Task cards in the expanded "Pending Tasks" disclosure (unscheduled
         tasks) the loaded card renders above the day blocks; each bool marks a
         card carrying the avatar/vendor row. --}}
    'pendingCards' => [],
    'showHeaderSkeleton' => true, {{-- kept for BC; header is always real now --}}
    {{-- Real header buttons (same partial as the loaded card). Header content
         is NEVER skeletonized: buttons don't depend on the rows, so they render
         usable from the first paint. The count badge IS row-derived, so the
         skeleton simply omits it rather than faking a pill. --}}
    'projectId' => null,
    'clientId' => null,
    'showAddTask' => false,
    'clickable' => true,
    'showNotifications' => true,
])

<x-island-card :enter="true" :heading="$title" {{ $attributes }}>
    <x-slot:actions>
        <x-upcoming-tasks-actions
            :project-id="$projectId"
            :client-id="$clientId"
            :show-add-task="$showAddTask"
            :clickable="$clickable"
            :show-notifications="$showNotifications"
        />
    </x-slot:actions>

    {{-- count=0 means the caller already knows there is nothing to load: render
         the card chrome alone instead of rows that shimmer and then vanish. --}}
    @php
        // taskCounts (from Task::skeletonDayCounts) is {past: bool, days: [[bool,...], ...]}
        // — one entry per day block, one bool per task card (true = the card
        // carries the avatar/vendor row). Falls back to `count` uniform blocks.
        $hasPastRow = is_array($taskCounts) && ($taskCounts['past'] ?? false);
        $blocks = is_array($taskCounts) && isset($taskCounts['days'])
            ? $taskCounts['days']
            : array_fill(0, (int) ($count ?? 3), [false]);
    @endphp
    @if(count($blocks) > 0 || $hasPastRow)
    <flux:skeleton.group animate="shimmer" class="space-y-4">
        @if(count($pendingCards ?? []) > 0)
            {{-- Expanded "Pending Tasks" disclosure: heading row + one card each. --}}
            <div class="space-y-2">
                <div class="flex items-center gap-2 min-h-6">
                    <flux:skeleton.line class="w-28" />
                    <flux:skeleton class="h-5 w-6 rounded-full" />
                </div>
                @foreach($pendingCards as $hasPeople)
                    <div class="rounded-lg shadow-xs ring-1 ring-zinc-200 dark:ring-zinc-700 p-3">
                        <flux:skeleton.line class="w-3/4" />
                        @if($hasPeople)
                            <div class="flex items-center gap-2 mt-2 min-w-0">
                                <flux:skeleton class="size-6 rounded-full" />
                                <flux:skeleton.line class="w-24" />
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
        @if($hasPastRow)
            {{-- Collapsed "Past Tasks" accordion row (24px), same as loaded. --}}
            <div class="flex items-center gap-2 min-h-6">
                <flux:skeleton.line class="w-24" />
                <flux:skeleton class="h-5 w-8 rounded-full" />
            </div>
        @endif
        @foreach($blocks as $i => $dayCards)
            <div class="space-y-2">
                {{-- Date header --}}
                <div class="flex items-center gap-2 min-h-6">
                    <flux:skeleton.line class="w-32" />
                    @if ($i === 0)
                        <flux:skeleton class="h-5 w-12 rounded-full" />
                    @endif
                </div>

                @if ($showProjectInfo && count($dayCards) > 0)
                    {{-- Project-grouped card (matches kanban column style) --}}
                    <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 p-3 space-y-3">
                        {{-- Project header --}}
                        <div class="flex items-center justify-between gap-2">
                            <div class="flex items-center gap-2 min-w-0 flex-1">
                                <flux:skeleton.line class="w-28" />
                                <flux:skeleton class="size-2 rounded-full" />
                            </div>
                            <flux:skeleton class="size-6 rounded" />
                        </div>
                        {{-- Project subheading --}}
                        <flux:skeleton.line class="w-36" />

                        {{-- Nested task card --}}
                        <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 p-3 space-y-2">
                            <flux:skeleton.line class="w-3/4" />
                            <div class="flex items-center gap-2">
                                <flux:skeleton class="size-5 rounded-full" />
                                <flux:skeleton class="size-5 rounded-full" />
                                <flux:skeleton.line class="w-20" />
                            </div>
                        </div>
                    </div>
                @else
                    {{-- One flat task card per task on that day; an empty day is
                         just its header, exactly like the loaded card. --}}
                    {{-- Measured against the real card (flux:kanban.card): ring-1
                         (no layout height, unlike border) + p-3 + a 20px title
                         row = 44px, plus a 24px people row at mt-2 = 76px. --}}
                    @foreach($dayCards as $hasPeople)
                        <div class="rounded-lg shadow-xs ring-1 ring-zinc-200 dark:ring-zinc-700 p-3">
                            <flux:skeleton.line class="w-3/4" />
                            @if($hasPeople)
                                <div class="flex items-center gap-2 mt-2 min-w-0">
                                    <flux:skeleton class="size-6 rounded-full" />
                                    <flux:skeleton.line class="w-24" />
                                </div>
                            @endif
                        </div>
                    @endforeach
                @endif
            </div>
        @endforeach
    </flux:skeleton.group>
    @endif
</x-island-card>
