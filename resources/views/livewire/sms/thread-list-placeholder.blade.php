<div class="space-y-1 overflow-y-auto lg:max-h-[calc(100vh-13rem)] lg:min-h-[calc(100vh-13rem)] px-1">
    @for ($i = 0; $i < 8; $i++)
        <div class="px-3 py-2.5 rounded-lg bg-zinc-100 dark:bg-zinc-800 animate-pulse">
            <div class="flex items-center justify-between gap-2">
                <div class="min-w-0 flex-1 space-y-2">
                    <div class="h-4 w-40 max-w-full rounded bg-zinc-200 dark:bg-zinc-700"></div>
                    <div class="h-3 w-52 max-w-full rounded bg-zinc-200 dark:bg-zinc-700"></div>
                </div>

                <div class="shrink-0 space-y-1 flex flex-col items-end">
                    <div class="h-3 w-10 rounded bg-zinc-200 dark:bg-zinc-700"></div>
                    <div class="h-2 w-2 rounded-full bg-zinc-200 dark:bg-zinc-700"></div>
                </div>
            </div>
        </div>
    @endfor
</div>
