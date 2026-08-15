{{-- One collection's card — the photo album or a timelapse. Rendered twice on
     the studio page (albums above Message Images, sequences below it), so it
     lives here rather than inline. Expects $collection and $photoGrid. --}}
{{-- Blaze hoists a component's attribute expressions ABOVE any @php block
     that precedes it, so every value the <x-index-table> tag below needs is
     either a @php(...) one-liner (compiled in place) or inlined outright. --}}
@php($isTarget = $collection->id === $this->collection?->id)
@php($effectiveAnchorId = $collection->isTimelapse() ? ($collection->anchor_frame_id ?? $collection->frames->first()?->id) : null)
@php($isEditing = $collection->isTimelapse() && $this->canManageImages && $this->editingCollectionId === $collection->id)
<x-index-table
    :heading="$collection->title"
    :collapsible="true"
    {{-- ONE card open at a time on this page: $openCardKey picks the first
         paint, and the dispatch/listen pair below keeps it exclusive after
         that — expanding any card (or aiming the camera at one) collapses
         the rest. --}}
    :expanded="$openCardKey === 'collection-'.$collection->id"
    {{-- Expanding this card tells every other card to close. Collapsing it
         hands the camera back — but ONLY if the camera is still in this card
         RIGHT NOW (a live <video> child): when the camera moves to another
         card, this one collapses a tick after the morph took the video away,
         and a render-time flag would wrongly close the camera that just
         opened over there. --}}
    x-init="$watch('open', o => o
        ? $dispatch('images-card-opened', { key: 'collection-{{ $collection->id }}' })
        : ($el.querySelector('video') && $wire.closeCamera()))"
    x-on:images-card-opened.window="$event.detail.key !== 'collection-{{ $collection->id }}' && (open = false)"
    {{-- Keyed by collection ALONE: a frame count in the key made every
         capture replace the card, and the camera (wire:ignore, a live
         MediaStream) now lives inside it. Morphing keeps the stream alive;
         each thumb has its own wire:key so new frames still appear. --}}
    wire:key="collection-{{ $collection->id }}"
>
    <x-slot:badge>
        <flux:badge :color="$collection->isTimelapse() ? 'indigo' : 'zinc'" size="sm" inset="top bottom">
            {{ $collection->isTimelapse() ? 'Timelapse' : 'Photos' }}
        </flux:badge>
        <flux:badge color="zinc" size="sm" inset="top bottom">
            {{ $collection->frames->count() }}
        </flux:badge>
    </x-slot:badge>

    <x-slot:actions>
        {{-- The camera opens INSIDE this card — no separate camera panel,
             so there is never a question of where the shot lands. --}}
        @if ($isTarget)
            <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="closeCamera">
                Close camera
            </flux:button>
        @else
            <flux:button size="xs" variant="ghost" icon="camera" wire:click="selectCollection({{ $collection->id }})">
                {{ $collection->isTimelapse() ? 'Shoot frame' : 'Add photo' }}
            </flux:button>
        @endif
        @if ($collection->isTimelapse() && $collection->frames->isNotEmpty() && $this->canManageImages)
            {{-- Edit mode: the ONE place frames can be reordered, deleted,
                 re-anchored or manually aligned. Off = clean thumbnails. --}}
            <flux:button size="xs" variant="ghost" icon="{{ $isEditing ? 'check' : 'pencil-square' }}"
                x-show="open" x-cloak
                wire:click="toggleEditCollection({{ $collection->id }})"
                title="{{ $isEditing ? 'Done editing' : 'Edit timelapse' }}">
                {{ $isEditing ? 'Done' : 'Edit' }}
            </flux:button>
        @endif
        @if ($this->canManageImages && $collection->title !== 'Project Images' && (! $collection->isTimelapse() || $isEditing))
            {{-- Timelapse deletion lives in edit mode and is SOFT — the
                 collection and its frames are restorable wholesale. --}}
            <flux:button size="xs" variant="ghost" icon="trash"
                x-show="open" x-cloak
                wire:click="deleteCollection({{ $collection->id }})"
                wire:confirm="Delete “{{ $collection->title }}”? It can be restored later." />
        @endif
        @if ($collection->frames->isNotEmpty() && (! $collection->isTimelapse() || $isEditing))
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
                @if ($this->canManageImages)
                    {{-- Build a sequence out of the picked photos. Needs two,
                         and only the project's own frames (texted photos live
                         on the message thread and have no frame row). --}}
                    <flux:button size="xs" variant="filled" icon="film"
                        x-on:click="$wire.createTimelapseFromSelection($store.picsel.ids)"
                        x-bind:disabled="$store.picsel.count < 2"
                        title="Create a timelapse from the selected photos">
                        <span x-text="$store.picsel.count > 1 ? `Timelapse ${$store.picsel.count}` : 'Timelapse'"></span>
                    </flux:button>
                @endif
                <flux:button size="xs" variant="ghost" x-on:click="$store.picsel.stop()">Cancel</flux:button>
            </span>
            <flux:button size="xs" variant="ghost" x-show="open && !$store.picsel.on" x-cloak
                aria-label="Select photos in {{ $collection->title }}"
                x-on:click="$store.picsel.start()">
                Select
            </flux:button>
        @endif
        {{-- The shared chevron every collapsible card uses; sits
             last so it's always the rightmost control. --}}
        <x-card-collapse-toggle :label="'Toggle '.$collection->title" />
    </x-slot:actions>

    {{-- The camera, right here in the card it shoots into. --}}
    @if ($isTarget)
        @include('livewire.projects._camera')
    @endif

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
                    {{-- Photo albums keep per-thumbnail delete (soft —
                         recoverable), for owning-vendor Admins only;
                         deleteFrame() enforces the same rule server-side.
                         Timelapse frames get NO chrome unless the card is
                         in edit mode. --}}
                    @if (! $collection->isTimelapse() && $this->canManageImages)
                        <span role="button" aria-label="Delete image" x-cloak
                            x-show="!$store.picsel.on"
                            x-on:click.stop="if (confirm('Delete this image?')) $wire.deleteFrame({{ $frame->id }})"
                            class="absolute right-1 top-1 grid size-6 place-items-center rounded-full bg-black/55 text-white transition hover:bg-red-600">
                            <flux:icon.trash variant="micro" class="size-3.5" />
                        </span>
                    @endif
                    @if ($isEditing)
                        {{-- Reorder: carousel-style arrows on the side edges. --}}
                        <span role="button" aria-label="Move frame earlier" x-cloak
                            x-show="!$store.picsel.on"
                            x-on:click.stop="$wire.moveFrame({{ $frame->id }}, -1)"
                            title="Move earlier"
                            class="absolute left-1 top-1/2 -translate-y-1/2 grid size-5 place-items-center rounded-full bg-black/55 text-white transition hover:bg-indigo-600">
                            <flux:icon.chevron-left variant="micro" class="size-3" />
                        </span>
                        <span role="button" aria-label="Move frame later" x-cloak
                            x-show="!$store.picsel.on"
                            x-on:click.stop="$wire.moveFrame({{ $frame->id }}, 1)"
                            title="Move later"
                            class="absolute right-1 top-1/2 -translate-y-1/2 grid size-5 place-items-center rounded-full bg-black/55 text-white transition hover:bg-indigo-600">
                            <flux:icon.chevron-right variant="micro" class="size-3" />
                        </span>
                        {{-- Soft delete — recoverable until force-deleted. --}}
                        <span role="button" aria-label="Delete image" x-cloak
                            x-show="!$store.picsel.on"
                            x-on:click.stop="if (confirm('Delete this frame?')) $wire.deleteFrame({{ $frame->id }})"
                            class="absolute right-1 top-1 grid size-5 place-items-center rounded-full bg-black/55 text-white transition hover:bg-red-600">
                            <flux:icon.trash variant="micro" class="size-3" />
                        </span>
                        {{-- Choosing the anchor lives INSIDE the frame's own
                             aligner (open the frame, then "Use as anchor"),
                             not as chrome on the thumbnail. --}}
                        {{-- Manual align: pan/zoom over the anchor. The anchor
                             itself is skipped — it IS the canvas. --}}
                        @if ($frame->id !== $effectiveAnchorId)
                            <span role="button" aria-label="Align frame manually" x-cloak
                                x-show="!$store.picsel.on"
                                x-on:click.stop="$wire.openFrameAligner({{ $frame->id }})"
                                title="Align manually — pan/zoom over the anchor"
                                class="absolute right-13 top-1 grid size-5 place-items-center rounded-full bg-black/55 text-white transition hover:bg-indigo-600">
                                <flux:icon.arrows-pointing-out variant="micro" class="size-3" />
                            </span>
                        @endif
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
