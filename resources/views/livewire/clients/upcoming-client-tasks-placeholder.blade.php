<div>
    <x-island-card heading="Upcoming Project Tasks" wire:transition>
        <x-slot:badge>
            <flux:skeleton class="h-5 w-6 rounded-full" animate="shimmer" />
        </x-slot:badge>
        <x-slot:actions>
            <flux:skeleton class="h-8 w-44 rounded-md" animate="shimmer" />
        </x-slot:actions>

        <flux:skeleton.group animate="shimmer" class="space-y-4">
            @for ($i = 0; $i < 3; $i++)
                <div class="space-y-2">
                    <div class="flex items-center gap-2">
                        <flux:skeleton.line class="w-32" />
                        @if ($i === 0)
                            <flux:skeleton class="h-5 w-12 rounded-full" />
                        @endif
                    </div>

                    @for ($j = 0; $j < ($i === 0 ? 2 : 1); $j++)
                        <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 p-3 space-y-2">
                            <flux:skeleton.line class="w-3/4" />
                            <div class="flex items-center gap-2">
                                <flux:skeleton class="size-5 rounded-full" />
                                <flux:skeleton.line class="w-24" />
                            </div>
                        </div>
                    @endfor
                </div>
            @endfor
        </flux:skeleton.group>
    </x-island-card>
</div>
