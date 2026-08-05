{{-- Same chrome as the loaded card: real heading + real (disabled) buttons;
     one shimmering line stands in for the "last photo …" note, and only when
     frames actually exist. --}}
<x-island-card heading="Images" :enter="true">
    <x-slot:actions>
        <flux:button size="sm" variant="primary" color="indigo" icon="camera" disabled>Capture</flux:button>
        <flux:button size="sm" disabled>Open</flux:button>
    </x-slot:actions>

    @if ($hasFrames)
        <flux:skeleton.group animate="shimmer">
            <flux:skeleton.line class="w-48" />
        </flux:skeleton.group>
    @else
        <flux:text class="text-sm text-zinc-500">
            Progress photos and timelapses for this project — each timelapse shot lines up over the last.
        </flux:text>
    @endif
</x-island-card>
