<div wire:transition>
    {{-- Same shell + heading prop as the loaded card, so the title can't shift
         when the skeleton is replaced. --}}
    <x-island-card :enter="true" heading="Project Timeline">
        {{-- Same header control the loaded card renders once it has history. --}}
        <x-slot:actions>
            @if($showHistoryToggle ?? false)
                <div class="flex items-center p-1 text-zinc-400">
                    <flux:icon.chevron-down class="size-4" />
                </div>
            @endif
        </x-slot:actions>
        <div>
            <flux:skeleton.group animate="shimmer">
                <flux:timeline class="mt-4" align="start" style="--flux-timeline-indicator-size: 1.5rem;">
                    @if($showHistoryToggle ?? false)
                        {{-- The loaded card keeps collapsed history items in the
                             DOM ahead of the current status, so the visible item
                             is not :first-child and picks up an 8px leading gap.
                             Mirror that or the card grows 8px on load. --}}
                        <flux:timeline.item style="display:none"></flux:timeline.item>
                    @endif
                    <flux:timeline.item>
                        <flux:timeline.indicator variant="bare">
                            <div class="size-5 rounded-full border-2 border-gray-300 opacity-40"></div>
                        </flux:timeline.indicator>
                        <flux:timeline.content>
                            <div class="flex items-center gap-2">
                                <flux:skeleton class="h-6 w-16 rounded-md" />
                                <flux:skeleton.line class="w-14" />
                                <flux:skeleton.line class="ml-auto w-20" />
                            </div>
                            <flux:skeleton.line class="h-4 w-16" />
                        </flux:timeline.content>
                    </flux:timeline.item>

                    @if($canUpdateProject ?? true)
                    <flux:timeline.item>
                        <flux:timeline.indicator variant="bare">
                            <div class="size-1.5 rounded-full bg-gray-100 ring-1 ring-gray-300"></div>
                        </flux:timeline.indicator>
                        <flux:timeline.content>
                            <flux:skeleton class="h-8 w-full rounded" />
                        </flux:timeline.content>
                    </flux:timeline.item>
                    @endif
                </flux:timeline>
            </flux:skeleton.group>
        </div>
    </x-island-card>
</div>
