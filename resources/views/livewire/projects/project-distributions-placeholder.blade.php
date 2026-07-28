<x-island-card :enter="true" heading="Project Distributions" :separator="true" wire:transition>

    <flux:skeleton.group animate="shimmer" class="space-y-2">
        @for ($i = 0; $i < 2; $i++)
            <div class="flex justify-between items-center py-2">
                <flux:skeleton.line class="w-32" />
                <flux:skeleton.line class="w-28" />
            </div>
        @endfor
    </flux:skeleton.group>
</x-island-card>
