<div wire:transition>
    <x-island-card>
        <div>
            <div class="flex w-full items-center justify-between">
                <flux:heading size="lg" class="mb-0">Project Timeline</flux:heading>
            </div>

            <flux:skeleton.group animate="shimmer">
                <flux:timeline class="mt-4" align="start" style="--flux-timeline-indicator-size: 1.5rem;">
                    <flux:timeline.item>
                        <flux:timeline.indicator variant="bare">
                            <div class="size-3 rounded-full bg-gray-100 ring-2 ring-gray-300"></div>
                        </flux:timeline.indicator>
                        <flux:timeline.content>
                            <div class="flex items-center gap-2">
                                <flux:skeleton class="h-5 w-16 rounded-full" />
                                <flux:skeleton.line class="w-14" />
                                <flux:skeleton.line class="ml-auto w-20" />
                            </div>
                            <flux:skeleton.line class="w-16 mt-1" />
                        </flux:timeline.content>
                    </flux:timeline.item>

                    <flux:timeline.item>
                        <flux:timeline.indicator variant="bare">
                            <div class="size-1.5 rounded-full bg-gray-200"></div>
                        </flux:timeline.indicator>
                        <flux:timeline.content>
                            <flux:skeleton class="h-8 w-full rounded" />
                        </flux:timeline.content>
                    </flux:timeline.item>
                </flux:timeline>
            </flux:skeleton.group>
        </div>
    </x-island-card>
</div>
