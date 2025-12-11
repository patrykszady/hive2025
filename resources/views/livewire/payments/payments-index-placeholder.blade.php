<flux:card class="space-y-4">
    <div class="flex justify-between items-center">
        <flux:heading size="lg">Project Payments</flux:heading>
    </div>
    
    <flux:separator variant="subtle" />
    
    <div class="space-y-3">
        @for ($i = 0; $i < 5; $i++)
            <div class="animate-pulse flex items-center gap-4 p-3 rounded-lg bg-zinc-100 dark:bg-zinc-800">
                <div class="h-10 w-10 bg-zinc-300 dark:bg-zinc-700 rounded"></div>
                <div class="flex-1 space-y-2">
                    <div class="h-4 bg-zinc-300 dark:bg-zinc-700 rounded w-3/4"></div>
                    <div class="h-3 bg-zinc-300 dark:bg-zinc-700 rounded w-1/2"></div>
                </div>
                <div class="h-6 w-20 bg-zinc-300 dark:bg-zinc-700 rounded"></div>
            </div>
        @endfor
    </div>
</flux:card>
