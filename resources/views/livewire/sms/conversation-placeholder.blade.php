<div class="flex-1 min-h-0 flex flex-col">
    <div class="border-b border-zinc-200 dark:border-zinc-700 px-4 py-2">
        <div class="h-6 w-40 bg-zinc-200/60 dark:bg-zinc-700/40 rounded animate-pulse"></div>
    </div>

    <div class="flex-1 min-h-0 px-2 py-4 space-y-3">
        @for ($i = 0; $i < 8; $i++)
            <div class="{{ $i % 2 === 0 ? 'flex justify-start' : 'flex justify-end' }}">
                <div class="h-14 w-56 max-w-[75%] bg-zinc-200/60 dark:bg-zinc-700/40 rounded-2xl animate-pulse"></div>
            </div>
        @endfor
    </div>

    <div class="shrink-0 px-1 pb-1">
        <div class="h-20 bg-zinc-200/60 dark:bg-zinc-700/40 rounded-lg animate-pulse"></div>
    </div>
</div>
