<div class="space-y-2 lg:min-h-[calc(100vh-13rem)]">
    {{-- Filter buttons skeleton --}}
    <div class="flex gap-1.5 mb-3">
        @for ($i = 0; $i < 4; $i++)
            <div class="h-7 w-16 rounded-md bg-zinc-200 dark:bg-zinc-700 animate-pulse"></div>
        @endfor
    </div>

    {{-- Call items skeleton --}}
    @for ($i = 0; $i < 6; $i++)
        <div class="flex items-start gap-3 p-3 rounded-lg animate-pulse">
            {{-- Icon skeleton --}}
            <div class="shrink-0 mt-0.5">
                <div class="size-5 rounded bg-zinc-200 dark:bg-zinc-700"></div>
            </div>

            {{-- Details skeleton --}}
            <div class="flex-1 min-w-0">
                <div class="flex items-center justify-between">
                    <div class="h-4 w-32 rounded bg-zinc-200 dark:bg-zinc-700"></div>
                    <div class="h-3 w-12 rounded bg-zinc-200 dark:bg-zinc-700"></div>
                </div>
                <div class="flex items-center gap-2 mt-1.5">
                    <div class="h-5 w-14 rounded-full bg-zinc-200 dark:bg-zinc-700"></div>
                </div>
            </div>
        </div>
    @endfor
</div>
