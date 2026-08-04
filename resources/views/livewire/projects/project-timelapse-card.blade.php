{{-- Project images: the six newest shots across every collection (the album
     and each timelapse), a way straight into the camera, and the way in to
     the full page. --}}
<x-island-card heading="Project Images">
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

    @if ($this->recentFrames->isNotEmpty())
        <div class="grid grid-cols-3 gap-2">
            @foreach ($this->recentFrames as $frame)
                <a href="{{ route('projects.images', $project) }}" wire:navigate.hover
                    wire:key="recent-frame-{{ $frame->id }}"
                    class="block aspect-square overflow-hidden rounded-lg bg-zinc-100 dark:bg-zinc-800">
                    <img
                        src="{{ route('projects.timelapse.frame', $frame) }}"
                        loading="lazy"
                        class="h-full w-full object-cover transition hover:opacity-90"
                        alt="Project photo from {{ $frame->created_at->format('M j') }}"
                    />
                </a>
            @endforeach
        </div>
        <flux:text class="text-xs text-zinc-500">
            Last photo {{ $this->recentFrames->first()->created_at->diffForHumans() }}{{ $this->recentFrames->first()->takenBy ? ' by '.($this->recentFrames->first()->takenBy->nickname ?: $this->recentFrames->first()->takenBy->first_name) : '' }}
        </flux:text>
    @else
        <flux:text class="text-sm text-zinc-500">
            Progress photos and timelapses for this project — each timelapse shot lines up over the last.
        </flux:text>
    @endif
</x-island-card>
