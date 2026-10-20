{{-- Same shape as the loaded component (wrapper div > card) so the swap is
     a patch, not a destroy-and-recreate — same convention as every other
     lazy card on this page. --}}
<div wire:transition>
<x-island-card :enter="true" heading="Communications" wire:transition>
    <x-slot:actions>
        <div class="flex gap-1 rounded-lg bg-zinc-100 dark:bg-zinc-800 p-0.5">
            @foreach (['w-10', 'w-12', 'w-12', 'w-14'] as $width)
                <flux:skeleton class="h-6 {{ $width }} rounded-md" />
            @endforeach
        </div>
    </x-slot:actions>

    <flux:separator variant="subtle" />

    <div class="space-y-1 py-2">
        <flux:skeleton.group animate="shimmer">
            @for ($i = 0; $i < 5; $i++)
                <div class="flex items-start gap-3 px-2 py-2">
                    <flux:skeleton class="size-4 rounded-full mt-0.5" />
                    <div class="min-w-0 flex-1 space-y-1.5">
                        <flux:skeleton.line class="w-1/3" />
                        <flux:skeleton.line class="w-2/3" />
                    </div>
                    <flux:skeleton class="h-3 w-10 mt-0.5" />
                </div>
            @endfor
        </flux:skeleton.group>
    </div>
</x-island-card>
</div>
