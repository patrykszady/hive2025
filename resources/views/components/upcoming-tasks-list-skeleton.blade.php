@props([
    'title' => 'Tasks',
    'showProjectInfo' => false,
    'actionsWidth' => 'w-32',
    'count' => null,
    'showHeaderSkeleton' => true,
])

<x-island-card :heading="$title" {{ $attributes }}>
    @if($showHeaderSkeleton)
        <x-slot:badge>
            <flux:skeleton class="h-5 w-6 rounded-full" animate="shimmer" />
        </x-slot:badge>
        <x-slot:actions>
            <flux:skeleton class="h-8 {{ $actionsWidth }} rounded-md" animate="shimmer" />
        </x-slot:actions>
    @endif

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
</x-island-card>
