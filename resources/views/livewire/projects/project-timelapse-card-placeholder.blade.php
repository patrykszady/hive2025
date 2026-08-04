{{-- Same chrome as the loaded card: real heading + real (disabled) button;
     the image area shimmers only when frames actually exist. --}}
<x-island-card heading="Project Images" :enter="true">
    <x-slot:actions>
        <flux:button size="sm" icon="camera" disabled>{{ $hasFrames ? 'Open Camera' : 'Start' }}</flux:button>
    </x-slot:actions>

    @if ($hasFrames)
        <flux:skeleton.group animate="shimmer">
            <flux:skeleton class="aspect-[4/3] w-full rounded-lg" />
            <flux:skeleton.line class="mt-2 w-40" />
        </flux:skeleton.group>
    @else
        <flux:text class="text-sm text-zinc-500">
            Shoot the same view as the job progresses — each shot lines up over the last.
        </flux:text>
    @endif
</x-island-card>
