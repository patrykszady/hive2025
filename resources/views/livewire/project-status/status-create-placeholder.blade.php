<flux:card class="space-y-6" wire:transition>
    <flux:accordion transition>
        <flux:accordion.item expanded>
            <flux:accordion.heading>
                <flux:heading size="lg" class="mb-0">Project Timeline</flux:heading>
            </flux:accordion.heading>

            <flux:accordion.content>
                <flux:skeleton.group animate="shimmer">
                    <flux:timeline class="mt-6">
                        @for ($i = 0; $i < 4; $i++)
                            <flux:timeline.item>
                                <flux:timeline.indicator />
                                <flux:timeline.content>
                                    <div class="flex items-center gap-2">
                                        <flux:skeleton class="h-5 w-16 rounded-full" />
                                        <flux:skeleton.line class="w-14" />
                                        <flux:skeleton.line class="ml-auto w-20" />
                                    </div>
                                    <flux:skeleton.line class="w-16 mt-1" />
                                </flux:timeline.content>
                            </flux:timeline.item>
                        @endfor
                    </flux:timeline>
                </flux:skeleton.group>
            </flux:accordion.content>
        </flux:accordion.item>
    </flux:accordion>
</flux:card>
