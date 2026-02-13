<div
    class="rounded-md border border-zinc-200 dark:border-zinc-700 px-3 py-2 text-xs text-zinc-600 dark:text-zinc-300"
    x-show="{!! $showWhen !!}"
    x-cloak
>
    <div class="flex items-center gap-2">
        <span class="font-medium text-zinc-800 dark:text-zinc-100">{{ $thisLabel ?? 'This browser:' }}</span>
        <span x-text="{!! $currentStatus !!}"></span>
    </div>
    <template x-if="{!! $otherStatuses !!}.length">
        <div class="mt-1">
            <span class="font-medium text-zinc-800 dark:text-zinc-100">Other browsers:</span>
            <span x-text="{!! $otherStatuses !!}.join(', ')"></span>
        </div>
    </template>
</div>
