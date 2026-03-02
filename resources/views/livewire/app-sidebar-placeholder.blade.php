<div>
    {{-- Skeleton placeholder for sidebar navigation --}}
    <flux:sidebar.nav>
        {{-- Notification item skeleton --}}
        <div class="flex items-center gap-3 px-3 py-2">
            <div class="size-5 rounded bg-zinc-200 dark:bg-zinc-700 animate-pulse"></div>
            <div class="h-4 w-24 rounded bg-zinc-200 dark:bg-zinc-700 animate-pulse"></div>
        </div>

        {{-- Nav items skeleton (6 items) --}}
        @for($i = 0; $i < 6; $i++)
            <div class="flex items-center gap-3 px-3 py-2">
                <div class="size-5 rounded bg-zinc-200 dark:bg-zinc-700 animate-pulse"></div>
                <div class="h-4 rounded bg-zinc-200 dark:bg-zinc-700 animate-pulse" style="width: {{ [72, 56, 64, 80, 48, 60][$i] }}%"></div>
            </div>
        @endfor

        {{-- Expandable group skeleton --}}
        <div class="mt-3 px-3 py-2">
            <div class="flex items-center gap-3">
                <div class="size-5 rounded bg-zinc-200 dark:bg-zinc-700 animate-pulse"></div>
                <div class="h-4 w-20 rounded bg-zinc-200 dark:bg-zinc-700 animate-pulse"></div>
            </div>
            <div class="ml-8 mt-2 space-y-2">
                <div class="flex items-center gap-3">
                    <div class="size-4 rounded bg-zinc-200 dark:bg-zinc-700 animate-pulse"></div>
                    <div class="h-3.5 w-16 rounded bg-zinc-200 dark:bg-zinc-700 animate-pulse"></div>
                </div>
                <div class="flex items-center gap-3">
                    <div class="size-4 rounded bg-zinc-200 dark:bg-zinc-700 animate-pulse"></div>
                    <div class="h-3.5 w-20 rounded bg-zinc-200 dark:bg-zinc-700 animate-pulse"></div>
                </div>
            </div>
        </div>

        {{-- Another group skeleton --}}
        <div class="mt-3 px-3 py-2">
            <div class="flex items-center gap-3">
                <div class="size-5 rounded bg-zinc-200 dark:bg-zinc-700 animate-pulse"></div>
                <div class="h-4 w-24 rounded bg-zinc-200 dark:bg-zinc-700 animate-pulse"></div>
            </div>
            <div class="ml-8 mt-2 space-y-2">
                <div class="flex items-center gap-3">
                    <div class="size-4 rounded bg-zinc-200 dark:bg-zinc-700 animate-pulse"></div>
                    <div class="h-3.5 w-14 rounded bg-zinc-200 dark:bg-zinc-700 animate-pulse"></div>
                </div>
                <div class="flex items-center gap-3">
                    <div class="size-4 rounded bg-zinc-200 dark:bg-zinc-700 animate-pulse"></div>
                    <div class="h-3.5 w-24 rounded bg-zinc-200 dark:bg-zinc-700 animate-pulse"></div>
                </div>
            </div>
        </div>
    </flux:sidebar.nav>

    <flux:sidebar.spacer />
</div>
