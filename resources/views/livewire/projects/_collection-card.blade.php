{{-- One collection's card — the photo album or a timelapse. Rendered twice on
     the studio page (albums above Message Images, sequences below it), so it
     lives here rather than inline. Expects $collection and $photoGrid. --}}
@php($isTarget = $collection->id === $this->collection?->id)
<x-index-table
    :heading="$collection->title"
    :collapsible="true"
    {{-- The photo album opens on arrival (clipped to one row) — unless it
         has nothing to show; a timelapse stays shut until asked for. --}}
    :expanded="! $collection->isTimelapse() && $collection->frames->isNotEmpty()"
    wire:key="collection-{{ $collection->id }}-{{ $collection->frames->count() }}"
>
    <x-slot:badge>
        <flux:badge :color="$collection->isTimelapse() ? 'indigo' : 'zinc'" size="sm" inset="top bottom">
            {{ $collection->isTimelapse() ? 'Timelapse' : 'Photos' }}
        </flux:badge>
        <flux:badge color="zinc" size="sm" inset="top bottom">
            {{ $collection->frames->count() }}
        </flux:badge>
        @if ($isTarget)
            <flux:badge color="green" size="sm" inset="top bottom">Shooting into</flux:badge>
        @endif
    </x-slot:badge>

    <x-slot:actions>
        @unless ($isTarget)
            <flux:button size="xs" variant="ghost" icon="camera" wire:click="selectCollection({{ $collection->id }})">
                {{ $collection->isTimelapse() ? 'Shoot frame' : 'Add photo' }}
            </flux:button>
        @endunless
        @if (auth()->user()->vendor_role === 'Admin' && $collection->title !== 'Project Images')
            {{-- Destructive, so only offered once the card is open
                 and the owner can see what they'd be deleting. --}}
            <flux:button size="xs" variant="ghost" icon="trash"
                x-show="open" x-cloak
                wire:click="deleteCollection({{ $collection->id }})"
                wire:confirm="Delete “{{ $collection->title }}” and all {{ $collection->frames->count() }} of its images?" />
        @endif
        @if ($collection->frames->isNotEmpty())
            {{-- Pick photos to text — mode is page-wide, so a
                 selection can span collections. While picking,
                 the button swaps for the send/cancel pair right
                 here in the header. --}}
            <span x-show="open && $store.picsel.on" x-cloak class="flex items-center gap-1">
                <flux:button size="xs" variant="primary" color="indigo" icon="chat-bubble-left-right"
                    x-on:click="$wire.openTextImagesModal($store.picsel.ids, $store.picsel.msgs)"
                    x-bind:disabled="$store.picsel.count === 0">
                    <span x-text="$store.picsel.count ? `Text ${$store.picsel.count}` : 'Text'"></span>
                </flux:button>
                <flux:button size="xs" variant="ghost" x-on:click="$store.picsel.stop()">Cancel</flux:button>
            </span>
            <flux:button size="xs" variant="ghost" x-show="open && !$store.picsel.on" x-cloak
                x-on:click="$store.picsel.start()">
                Select
            </flux:button>
        @endif
        {{-- The shared chevron every collapsible card uses; sits
             last so it's always the rightmost control. --}}
        <x-card-collapse-toggle :label="'Toggle '.$collection->title" />
    </x-slot:actions>

    {{-- Playback: sequences only, and only once there's something
         to play. --}}
    @if ($collection->isTimelapse() && $collection->frames->count() >= 2)
        @include('livewire.projects._timelapse-viewer', [
            'frameUrls' => $collection->frames->map(fn ($f) => route('projects.timelapse.frame', [$f, 'v' => $f->version]))->values()->all(),
        ])
    @endif

    @if ($collection->frames->isEmpty())
        <flux:text class="py-6 text-center text-sm text-zinc-500">
            {{ $collection->isTimelapse() ? 'No frames yet — line up the camera and take the first one.' : 'No photos yet.' }}
        </flux:text>
    @else
        {{-- The lightbox payload is the WHOLE collection, so build
             it once here rather than re-encoding it into every
             tile's click handler. In select mode a tap picks the
             photo instead of opening it. --}}
        <div x-data="photoRows({{ $collection->frames->count() }}, {{ $collection->isTimelapse() ? 'true' : 'false' }})">
        <div class="{{ $photoGrid }}" data-photo-grid
            x-data="{ lb: {{ Js::from($this->lightboxFrames($collection)) }} }">
            @foreach ($collection->frames as $index => $frame)
                <button type="button"
                    wire:key="thumb-{{ $frame->id }}"
                    x-show="show({{ $index }})"
                    x-transition.duration.200ms
                    x-on:click="$store.picsel.on
                        ? $store.picsel.toggle({{ $frame->id }})
                        : $dispatch('open-lightbox', { frames: lb, index: {{ $index }} })"
                    class="group relative aspect-square overflow-hidden rounded-lg bg-zinc-100 dark:bg-zinc-800 cursor-pointer">
                    {{-- Blur-up: tiny inlined preview under the real thumb. --}}
                    @php($micro = $this->frameMicro($frame))
                    @if ($micro)
                        <img aria-hidden="true" src="{{ $micro }}" alt=""
                            class="absolute inset-0 h-full w-full object-cover"
                            style="filter: blur(10px); transform: scale(1.08)" />
                    @endif
                    <img src="{{ route('projects.timelapse.frame', [$frame, 'thumb' => 1, 'v' => $frame->version]) }}" alt=""
                        x-data="{ loaded: false }"
                        x-init="loaded = $el.complete && $el.naturalWidth > 0"
                        x-on:load="loaded = true"
                        x-bind:style="loaded ? 'opacity:1;transition:opacity .35s' : 'opacity:0'"
                        class="relative h-full w-full object-cover transition group-hover:opacity-90" loading="lazy" />
                    <span class="absolute inset-x-0 bottom-0 truncate bg-black/50 px-1 py-0.5 text-[10px] text-white">
                        {{ $this->frameCaptions[$collection->id][$frame->id] ?? '' }}
                    </span>
                    {{-- Per-frame delete (soft — recoverable). Members remove
                         their own shots, Admins any; deleteFrame() enforces
                         the same rule server-side. Hidden in selection mode. --}}
                    @if (auth()->user()->vendor_role === 'Admin' || $frame->taken_by_user_id === auth()->id())
                        <span role="button" aria-label="Delete image" x-cloak
                            x-show="!$store.picsel.on"
                            x-on:click.stop="if (confirm('Delete this image?')) $wire.deleteFrame({{ $frame->id }})"
                            class="absolute right-1 top-1 grid size-6 place-items-center rounded-full bg-black/55 text-white transition hover:bg-red-600">
                            <flux:icon.trash variant="micro" class="size-3.5" />
                        </span>
                    @endif
                    {{-- selection ring + check --}}
                    <span x-show="$store.picsel.on" x-cloak
                        class="absolute inset-0 rounded-lg"
                        x-bind:class="$store.picsel.has({{ $frame->id }}) ? 'ring-4 ring-indigo-500 ring-inset bg-indigo-500/20' : ''"></span>
                    <span x-show="$store.picsel.on" x-cloak
                        class="absolute right-1 top-1 grid size-5 place-items-center rounded-full text-white"
                        x-bind:class="$store.picsel.has({{ $frame->id }}) ? 'bg-indigo-500' : 'bg-black/40'">
                        <flux:icon.check class="size-3" x-show="$store.picsel.has({{ $frame->id }})" />
                    </span>
                </button>
            @endforeach
        </div>
        @include('livewire.projects._show-more')
        </div>
    @endif
</x-index-table>
