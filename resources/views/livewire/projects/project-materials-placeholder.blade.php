<div>
    <flux:card class="!px-5 !py-2">
        <div class="flex items-center justify-between">
            <flux:heading size="lg">Materials</flux:heading>
        </div>

        <div class="mt-3">
            <flux:skeleton.group animate="shimmer" class="space-y-0 -mt-2">
                @for ($i = 0; $i < 2; $i++)
                    <div class="border-b border-zinc-800/10 py-4 first:pt-0 last:border-b-0 last:pb-0 dark:border-white/10">
                        <div class="flex w-full items-center justify-between">
                            <div class="flex items-center gap-2">
                                <flux:skeleton class="size-5 rounded" />
                                <flux:skeleton.line class="w-40" />
                                <flux:skeleton class="h-5 w-14 rounded-md" />
                            </div>
                            <flux:skeleton class="size-5 rounded" />
                        </div>
                    </div>
                @endfor
            </flux:skeleton.group>
        </div>
    </flux:card>
</div>
