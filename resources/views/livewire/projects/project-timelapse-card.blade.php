{{-- Images: a compact entry point — how many shots this job has, when the last
     one landed, a way straight into the camera, and the way in to the full
     page. Browsing the photos themselves happens there. --}}
<x-island-card heading="Images">
    @if ($this->frameCount > 0)
        <x-slot:badge>
            <flux:badge color="zinc" size="sm" inset="top bottom">{{ $this->frameCount }}</flux:badge>
        </x-slot:badge>
    @endif
    <x-slot:actions>
        {{-- Capture lands on the images page with the camera already open on
             the general album; Open just goes there to browse. --}}
        <flux:button size="sm" variant="primary" color="indigo" icon="camera"
            href="{{ route('projects.images', $project) }}?capture=1" wire:navigate.hover>
            Capture
        </flux:button>
        <flux:button size="sm" href="{{ route('projects.images', $project) }}" wire:navigate.hover>
            Open
        </flux:button>
    </x-slot:actions>

    @if ($this->latestFrame)
        <flux:text class="text-sm text-zinc-500">
            Last photo {{ $this->latestFrame->created_at->diffForHumans() }}{{ $this->latestFrame->taker_name ? ' by '.$this->latestFrame->taker_name : '' }}
        </flux:text>
    @else
        <flux:text class="text-sm text-zinc-500">
            Progress photos and timelapses for this project — each timelapse shot lines up over the last.
        </flux:text>
    @endif
</x-island-card>
