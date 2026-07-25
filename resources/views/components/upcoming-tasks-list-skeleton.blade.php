@props([
    'title' => 'Tasks',
    'showProjectInfo' => false,
    'actionsWidth' => 'w-32', {{-- kept for BC; no longer renders anything --}}
    'count' => null,
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

<x-island-card :heading="$title" {{ $attributes }}>
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
    @if(($count ?? 3) > 0)
    <flux:skeleton.group animate="shimmer" class="space-y-4">
        @for ($i = 0; $i < ($count ?? 3); $i++)
            <div class="space-y-2">
                {{-- Date header --}}
                <div class="flex items-center gap-2 min-h-6">
                    <flux:skeleton.line class="w-32" />
                    @if ($i === 0)
                        <flux:skeleton class="h-5 w-12 rounded-full" />
                    @endif
                </div>

                @if ($showProjectInfo)
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
                    {{-- Flat task card --}}
                    <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 p-3 space-y-2">
                        <flux:skeleton.line class="w-3/4" />
                        <div class="flex items-center gap-2">
                            <flux:skeleton class="size-5 rounded-full" />
                            <flux:skeleton.line class="w-24" />
                        </div>
                    </div>
                @endif
            </div>
        @endfor
    </flux:skeleton.group>
    @endif
</x-island-card>
