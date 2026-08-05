{{-- The project camera: live viewfinder with the previous frame ghosted on
     top (onion skin) so each shot lines up with the last — stop-motion-app
     style. Just pictures; the gs.construction site plays the sequence back.

     The capture block is wire:ignore (Livewire must never morph a live
     <video>), so the overlay updates from JS: after a successful upload the
     just-captured canvas becomes the new overlay — no round trip, never
     stale. --}}
@php
    // Desktop layout turns on whether the camera is out. Closed (the usual
    // case: you came to LOOK at photos) the collections take the whole width
    // and the grids go wide; open, the camera keeps its half and the grids
    // narrow to suit.
    $cameraOpen = (bool) $this->collection;
    $photoGrid = $cameraOpen
        ? 'grid grid-cols-3 gap-2 pt-1 sm:grid-cols-4 xl:grid-cols-5'
        : 'grid grid-cols-3 gap-2 pt-1 sm:grid-cols-5 lg:grid-cols-7 xl:grid-cols-8';
@endphp

<x-page.shell
    width="wide"
    :cols="4"
    :breadcrumbs="[
        ['label' => 'Projects', 'href' => route('projects.index')],
        ['label' => $project->short_address ?? $project->project_name, 'href' => route('projects.show', $project)],
        ['label' => 'Images'],
    ]"
>
    {{-- Browser-specific "make camera access permanent" instructions. Shown
         until the browser reports the permission as durably granted (or the
         user dismisses it); each engine words it differently, so detect and
         say exactly where the setting lives. --}}
    <div class="lg:col-span-4" x-data="timelapsePermissionCallout()" x-show="visible" x-cloak>
        <flux:callout icon="video-camera" color="indigo" inline>
            <flux:callout.heading>Keep camera access allowed</flux:callout.heading>
            <flux:callout.text x-text="instructions"></flux:callout.text>
            <x-slot:controls>
                <flux:button size="sm" variant="ghost" x-on:click="dismiss()">Got it</flux:button>
            </x-slot:controls>
        </flux:callout>
    </div>

    {{-- The camera only exists once a collection is chosen: arriving here is
         usually about looking at photos, and an unrequested camera means an
         unrequested permission prompt. No collection, no column — an empty
         half-page placeholder helped nobody. --}}
    @if ($this->collection)
        <x-page.column :span="2" class="lg:sticky lg:top-4 lg:self-start">
            <x-island-card heading="Camera">
                <x-slot:badge>
                    <flux:badge color="indigo" size="sm" inset="top bottom">{{ $this->collection->title }}</flux:badge>
                    <flux:badge color="zinc" size="sm" inset="top bottom">{{ $this->frames->count() }}</flux:badge>
                </x-slot:badge>

                <x-slot:actions>
                    <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="closeCamera">Close</flux:button>
                </x-slot:actions>

            {{-- wire:key on the collection: switching target rebuilds the
                 Alpine root so the onion skin AND the guide's reference
                 thumbnail come from the new collection, never the old one. --}}
            <div
                wire:ignore
                wire:key="camera-{{ $this->collection?->id }}"
                x-data="timelapseCapture($wire, @js($this->latestFrame ? route('projects.timelapse.frame', [$this->latestFrame, 'v' => $this->latestFrame->version]) : ''), @js((bool) $this->collection?->isTimelapse()))"
                class="space-y-3"
                x-bind:class="fullscreen ? 'fixed inset-0 z-50 bg-black' : ''"
            >
                {{-- Viewfinder: live camera with the last frame ghosted on
                     top. The ring is the framing guide — the live image is
                     compared against the last frame a few times a second, and
                     the ring turns green when the shot lines up. --}}
                <div
                    class="relative overflow-hidden bg-zinc-950 ring-4 transition-[--tw-ring-color] duration-300"
                    x-show="cameraReady" x-cloak
                    x-bind:class="(fullscreen ? 'absolute inset-0 rounded-none' : 'aspect-[4/3] rounded-lg') + ' ' + (aligned ? 'ring-green-500 cursor-pointer' : (onionSrc ? 'ring-zinc-500/40' : 'ring-transparent'))"
                    x-on:click="aligned && !shutter && captureSharp()"
                >
                    {{-- object-cover: the box shows exactly what capture will
                         store — a portrait stream's centered landscape band.
                         Fullscreen letterboxes (contain) so nothing is hidden
                         while framing. --}}
                    <video x-ref="video" autoplay playsinline muted class="absolute inset-0 h-full w-full"
                        x-bind:class="fullscreen ? 'object-contain' : 'object-cover'"></video>
                    <img
                        x-show="onionOn && onionSrc"
                        x-bind:src="onionSrc || null"
                        class="pointer-events-none absolute inset-0 h-full w-full"
                        x-bind:class="fullscreen ? 'object-contain' : 'object-cover'"
                        x-bind:style="`opacity: ${onionOpacity / 100}`"
                        alt=""
                    />

                    {{-- Merge-the-crosshairs guide (the camera-app level
                         pattern): the faint cross is where you're pointing,
                         the colored cross is where the last frame's view is.
                         Pan toward the colored cross; it warms amber → green
                         as the shot closes in, and both crosses sit on top of
                         each other when it's right. RED pulsing dot = the
                         guide can't find the last frame's view at all. --}}
                    <div x-show="onionSrc && guideVisible" x-cloak class="pointer-events-none absolute inset-0">
                        <svg class="absolute left-1/2 top-1/2 size-10 -translate-x-1/2 -translate-y-1/2 text-white/60"
                            viewBox="0 0 40 40" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M20 6v9M20 25v9M6 20h9M25 20h9" />
                        </svg>
                        <svg x-show="!lost" class="absolute size-10 transition-transform duration-150"
                            viewBox="0 0 40 40" fill="none" stroke="currentColor" stroke-width="3"
                            x-bind:style="`left: calc(50% - 20px + ${bubbleX}px); top: calc(50% - 20px + ${bubbleY}px); color: ${guideColor}`">
                            <path d="M20 6v9M20 25v9M6 20h9M25 20h9" />
                            <circle cx="20" cy="20" r="3.5" fill="currentColor" stroke="none" />
                        </svg>
                        <div x-show="lost" class="absolute left-1/2 top-1/2 size-4 -translate-x-1/2 -translate-y-1/2 rounded-full bg-red-500 animate-pulse"></div>
                    </div>

                    {{-- state chip: says the actual move to make --}}
                    <div x-show="onionSrc" x-cloak class="absolute left-2 top-2 rounded px-2 py-0.5 text-xs font-medium"
                        x-bind:class="aligned ? 'bg-green-500/90 text-white' : (lost ? 'bg-red-500/90 text-white' : 'bg-black/60 text-white')"
                        x-text="aligned ? 'Lined up — take the frame' : (lost ? `Find the last frame’s view` : (guideVisible ? hint : ''))"></div>

                    {{-- Fullscreen chrome: exit up top, a camera-app shutter
                         floating at the bottom — the button row below the
                         viewfinder is hidden while the viewfinder IS the
                         screen. --}}
                    <button type="button" x-show="fullscreen" x-cloak x-on:click.stop="exitFullscreen()"
                        class="absolute right-2 top-2 z-20 rounded-full bg-black/60 p-2 text-white">
                        <flux:icon.x-mark class="size-5" />
                    </button>
                    <button type="button" x-show="fullscreen" x-cloak x-on:click.stop="captureSharp()" x-bind:disabled="shutter"
                        class="absolute bottom-3 left-1/2 z-20 -translate-x-1/2 rounded-full border-2 border-white/60 p-1"
                        x-bind:class="aligned ? 'bg-green-500' : 'bg-black/60'">
                        <span class="block size-10 rounded-full bg-white"></span>
                    </button>

                    {{-- Manual way into fullscreen — rotation is the usual
                         trigger, but a tap should work on any device. --}}
                    <button type="button" x-show="!fullscreen" x-cloak x-on:click.stop="enterFullscreen()"
                        class="absolute bottom-2 right-2 z-10 rounded bg-black/50 p-1.5 text-white">
                        <flux:icon.arrows-pointing-out class="size-4" />
                    </button>

                    {{-- steadying: between tap and shutter --}}
                    <div x-show="armingCapture" x-cloak class="absolute inset-x-0 bottom-2 z-10 text-center">
                        <span class="rounded bg-black/60 px-2 py-0.5 text-xs font-medium text-white">Hold still…</span>
                    </div>

                    {{-- Uploads ride along behind the shutter — a chip, not a
                         veil, so the next shot is never waiting on the last. --}}
                    <div x-show="uploading || failed" x-cloak class="absolute right-2 top-2 z-10 flex items-center gap-1 rounded bg-black/60 px-2 py-0.5 text-xs font-medium text-white">
                        <template x-if="uploading">
                            <span class="flex items-center gap-1">
                                <flux:icon.arrow-path class="size-3 animate-spin" />
                                <span x-text="uploading > 1 ? `Saving ${uploading}…` : 'Saving…'"></span>
                            </span>
                        </template>
                        <template x-if="!uploading && failed">
                            <span class="text-red-300" x-text="`${failed} failed to save`"></span>
                        </template>
                    </div>

                    {{-- shutter flash --}}
                    <div x-show="shutter" x-cloak class="pointer-events-none absolute inset-0 bg-white/70"></div>
                </div>

                {{-- Camera unavailable → explain, offer a real tap to retry
                     (Safari only honours getUserMedia inside a gesture), and
                     leave the file fallback. --}}
                <div x-show="!cameraReady" class="space-y-2 rounded-lg border border-dashed border-zinc-300 p-6 text-center dark:border-zinc-600">
                    <flux:icon.video-camera-slash class="mx-auto size-8 text-zinc-400" />
                    <flux:text class="text-sm" x-text="cameraError || 'Starting camera…'"></flux:text>

                    <div x-show="needsTap" x-cloak class="pt-1">
                        <flux:button size="sm" variant="primary" icon="video-camera" x-on:click="start()">
                            Enable camera
                        </flux:button>
                    </div>

                    <flux:text class="text-xs text-zinc-500">You can still add photos with the upload below.</flux:text>
                </div>

                <div class="flex items-center gap-3" x-show="cameraReady && !fullscreen" x-cloak>
                    <flux:button variant="primary" icon="camera" x-on:click="captureSharp()" x-bind:disabled="shutter" class="flex-1"
                        x-bind:class="aligned ? '!bg-green-600 hover:!bg-green-700' : ''">
                        Take Frame
                    </flux:button>
                </div>


                {{-- Onion-skin controls --}}
                <div class="flex items-center gap-3" x-show="cameraReady && onionSrc && !fullscreen" x-cloak>
                    <flux:switch x-model="onionOn" label="Overlay last frame" />
                    <input type="range" min="10" max="90" x-model="onionOpacity" class="flex-1 accent-indigo-500" x-show="onionOn" />
                </div>
            </div>

            {{-- Fallback / bulk add: plain upload, same pipeline. Folded away
                 behind a link — it's the exception, and the camera card is
                 tall enough already on a phone. --}}
            <div x-data="{ up: false }" class="pt-1">
                <flux:button variant="ghost" size="xs" icon="arrow-up-tray" x-on:click="up = !up">
                    Upload a photo instead
                </flux:button>
                <form wire:submit="uploadFile" x-show="up" x-cloak class="flex items-end gap-2 pt-2">
                    <div class="flex-1">
                        <flux:input type="file" wire:model="file" accept="image/jpeg,image/png" />
                    </div>
                    <flux:button type="submit" wire:loading.attr="disabled" wire:target="file, uploadFile">Add</flux:button>
                </form>
            </div>
        </x-island-card>
        </x-page.column>
    @endif

    <x-page.column :span="$cameraOpen ? 2 : 4">
        {{-- The project's own photos lead: the album first, then the texted
             ones. Timelapses are the specialist view, so they sit below both
             (further down this column). --}}
        @foreach ($this->collections->reject->isTimelapse() as $collection)
            @include('livewire.projects._collection-card')
        @endforeach

        {{-- Photos texted about this job. Read-only: they belong to the
             message thread, so they're shown here but managed there. --}}
        @if ($this->messageImages->isNotEmpty())
            <x-index-table heading="Message Images" :collapsible="true" :expanded="true">
                <x-slot:badge>
                    <flux:badge color="zinc" size="sm" inset="top bottom">{{ $this->messageImages->count() }}</flux:badge>
                </x-slot:badge>

                <x-slot:actions>
                    {{-- Texted photos can be selected and forwarded too. --}}
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
                    <x-card-collapse-toggle label="Toggle message images" />
                </x-slot:actions>

                {{-- One chip per person who has sent a photo — tap to see just
                     theirs, tap again to clear. --}}
                @if ($this->messageSenders->count() > 1)
                    <div class="flex flex-wrap gap-2 pb-1">
                        @foreach ($this->messageSenders as $sender => $count)
                            <button type="button" wire:click="filterMessageSender(@js($sender))" class="cursor-pointer">
                                <flux:badge size="sm" :color="$messageSender === $sender ? 'indigo' : 'zinc'">
                                    {{ $sender }} ({{ $count }})
                                </flux:badge>
                            </button>
                        @endforeach
                    </div>
                @endif

                {{-- Encoded once for the whole grid, not once per tile. The
                     wire:key ties the grid to the active filter: morphs leave
                     x-data alone, so without it a filter change would keep
                     serving the OLD payload to the lightbox. --}}
                <div x-data="photoRows({{ $this->messageImages->count() }})">
                <div class="{{ $photoGrid }}" data-photo-grid
                    wire:key="msg-grid-{{ $messageSender ?? 'all' }}"
                    x-data="{ lb: {{ Js::from($this->lightboxMessageImages()) }} }">
                    @foreach ($this->messageImages as $index => $image)
                        <button type="button"
                            wire:key="msg-image-{{ $index }}"
                            x-show="show({{ $index }})"
                            x-on:click="$store.picsel.on
                                ? $store.picsel.toggleMsg(@js($image['raw']))
                                : $dispatch('open-lightbox', { frames: lb, index: {{ $index }} })"
                            class="group relative aspect-square cursor-pointer overflow-hidden rounded-lg bg-zinc-100 dark:bg-zinc-800">
                            {{-- A failed load gets two retries before the tile
                                 dims: one dropped request on jobsite wifi must
                                 not blank a photo until the next full refresh.
                                 Never delete the tile — an expired session
                                 answers every image with login HTML, and
                                 removing on error would silently empty the
                                 whole grid while the count still said 45. --}}
                            <img src="{{ $image['thumb'] }}" alt=""
                                x-data="{ tries: 0 }"
                                x-on:error="if (tries < 2) { tries++; const b = $el.src.replace(/[?&]r=\d+$/, ''); setTimeout(() => $el.src = b + (b.includes('?') ? '&' : '?') + 'r=' + tries, 700 * tries) } else { $el.style.display = 'none'; $el.closest('button').classList.add('opacity-40', 'pointer-events-none') }"
                                class="h-full w-full object-cover transition group-hover:opacity-90" loading="lazy" />
                            <span class="absolute inset-x-0 bottom-0 truncate bg-black/50 px-1 py-0.5 text-[10px] text-white">
                                {{ $image['sender'] }} · {{ $image['sent_at']->format('n/j') }}
                            </span>
                            {{-- selection ring + check --}}
                            <span x-show="$store.picsel.on" x-cloak
                                class="absolute inset-0 rounded-lg"
                                x-bind:class="$store.picsel.hasMsg(@js($image['raw'])) ? 'ring-4 ring-indigo-500 ring-inset bg-indigo-500/20' : ''"></span>
                            <span x-show="$store.picsel.on" x-cloak
                                class="absolute right-1 top-1 grid size-5 place-items-center rounded-full text-white"
                                x-bind:class="$store.picsel.hasMsg(@js($image['raw'])) ? 'bg-indigo-500' : 'bg-black/40'">
                                <flux:icon.check class="size-3" x-show="$store.picsel.hasMsg(@js($image['raw']))" />
                            </span>
                        </button>
                    @endforeach
                </div>
                @include('livewire.projects._show-more')
                </div>
            </x-index-table>
        @endif

        {{-- The sequences, below both photo cards. Each shows its playback and
             its frames; the one being shot into is called out, and clicking
             any other switches the camera. --}}
        @foreach ($this->collections->filter->isTimelapse() as $collection)
            @include('livewire.projects._collection-card')
        @endforeach

        {{-- Start another room's timelapse, or an album for loose photos. --}}
        <x-island-card heading="New Timelapse">
            <x-slot:actions>
                <flux:button size="xs" variant="ghost" icon="plus" wire:click="$toggle('showNewCollection')">
                    Add
                </flux:button>
            </x-slot:actions>

            @if ($showNewCollection)
                <div class="space-y-3 pt-1">
                    {{-- No <flux:error> here: flux:input renders its own, and
                         adding one printed every message twice. --}}
                    <flux:input wire:model="newTitle" label="Name" placeholder="Kitchen Timelapse" />

                    <flux:description>One per room or view.</flux:description>

                    <div class="flex justify-end gap-2">
                        <flux:button size="sm" variant="ghost" wire:click="$set('showNewCollection', false)">Cancel</flux:button>
                        <flux:button size="sm" variant="primary" wire:click="createCollection">Create</flux:button>
                    </div>
                </div>
            @endif
        </x-island-card>
    </x-page.column>

    {{-- Selection mode lives in the card headers; this only clears the
         picked set after a successful send. --}}
    <div x-data x-on:text-images-sent.window="$store.picsel.stop()" class="hidden"></div>

    {{-- Text selected photos: pick the conversation, add an optional note. --}}
    <flux:modal
        wire:key="text-images-modal"
        name="text-images"
        @close="$wire.set('showTextImagesModal', false, false)"
        class="max-w-lg w-full max-h-[85vh] flex flex-col overflow-hidden !p-0"
    >
        @if($showTextImagesModal)
            @include('livewire.projects._text-images-modal')
        @endif
    </flux:modal>

    {{-- Photo lightbox — same behaviour as the gs.construction project
         gallery: teleported to <body> so no card can clip it, blurred
         backdrop, dot indicators, arrow keys, click-outside and Escape to
         close, and the page behind it locked from scrolling. Adds swipe for
         phones and a "Show original" the public site doesn't need. --}}
    <div x-data="{
        open: false,
        frames: [],
        index: 0,
        touchX: null,
        get current() { return this.frames[this.index] ?? {}; },
        show(detail) {
            this.frames = detail.frames || [];
            this.index = detail.index || 0;
            if (!this.frames.length) return;
            this.open = true;
            document.body.style.overflow = 'hidden';
        },
        close() {
            this.open = false;
            document.body.style.overflow = '';
        },
        next() { if (this.frames.length) this.index = (this.index + 1) % this.frames.length; },
        prev() { if (this.frames.length) this.index = (this.index - 1 + this.frames.length) % this.frames.length; },
        swipeEnd(endX) {
            if (this.touchX === null) return;
            const dx = endX - this.touchX;
            this.touchX = null;
            if (Math.abs(dx) < 45) return;
            dx < 0 ? this.next() : this.prev();
        },
    }"
        x-on:open-lightbox.window="show($event.detail)"
        x-on:keydown.escape.window="open && close()"
        x-on:keydown.arrow-right.window="open && next()"
        x-on:keydown.arrow-left.window="open && prev()"
    >
        <template x-teleport="body">
            <div x-show="open" x-cloak
                x-transition:enter="transition ease-out duration-200"
                x-transition:enter-start="opacity-0"
                x-transition:enter-end="opacity-100"
                x-transition:leave="transition ease-in duration-150"
                x-transition:leave-start="opacity-100"
                x-transition:leave-end="opacity-0"
                class="fixed inset-0 z-50 flex items-center justify-center overscroll-none bg-black/60 backdrop-blur-2xl"
                x-on:click.self="close()"
                x-on:touchstart="touchX = $event.changedTouches[0].clientX"
                x-on:touchend="swipeEnd($event.changedTouches[0].clientX)"
            >
                <div class="relative z-20 max-h-[85vh] max-w-[92vw] sm:max-h-[80vh] sm:max-w-[85vw]">
                    <img :src="current.url" :alt="current.label || ''"
                        class="max-h-[85vh] max-w-[92vw] rounded-lg object-contain shadow-2xl sm:max-h-[80vh] sm:max-w-[85vw]" />

                    {{-- caption --}}
                    <div x-show="current.label"
                        class="absolute inset-x-0 bottom-0 rounded-b-lg bg-gradient-to-t from-black/80 via-black/50 to-transparent p-4 pb-10 pt-10">
                        <p class="text-center text-sm text-white sm:text-base" x-text="current.label"></p>
                    </div>

                    {{-- dots --}}
                    <div class="absolute inset-x-0 bottom-2 z-20 flex justify-center gap-1.5 sm:bottom-4 sm:gap-2" x-show="frames.length > 1">
                        <template x-for="(f, i) in frames" :key="'dot-' + i">
                            <button type="button" x-on:click.stop="index = i"
                                :class="index === i ? 'bg-white w-8' : 'bg-white/50 w-3 hover:bg-white/70'"
                                class="h-3 cursor-pointer rounded-full shadow-lg transition-all duration-300"
                                :title="`Go to photo ${i + 1}`"></button>
                        </template>
                    </div>

                    {{-- top-left: opens the untouched full-resolution shot in
                         a new tab (not the registered copy shown here). No
                         `download` attribute — this SHOWS it; the browser's
                         own save is one step further. --}}
                    <div class="absolute left-4 top-4 z-10 flex items-center gap-3 text-sm">
                        <a :href="current.original" target="_blank" rel="noopener" x-on:click.stop
                            class="inline-flex items-center gap-1 rounded-full bg-black/50 px-3 py-1.5 text-white/80 transition-colors hover:text-white">
                            <flux:icon.arrow-top-right-on-square class="size-4" />
                            Show original
                        </a>
                        <a x-show="current.map" :href="current.map" target="_blank" rel="noopener" x-on:click.stop
                            class="inline-flex items-center gap-1 rounded-full bg-black/50 px-3 py-1.5 text-white/80 transition-colors hover:text-white">
                            <flux:icon.map-pin class="size-4" />
                            Map
                        </a>
                    </div>

                    {{-- counter --}}
                    <div class="absolute right-4 top-4 z-10 text-sm" x-show="frames.length > 1">
                        <div class="rounded-full bg-black/50 px-3 py-1.5 text-white/80">
                            <span x-text="index + 1"></span> / <span x-text="frames.length"></span>
                        </div>
                    </div>
                </div>

                {{-- arrows sit outside the image so they never cover it --}}
                <button type="button" x-show="frames.length > 1" x-on:click.stop="prev()"
                    class="absolute left-2 top-1/2 z-30 -translate-y-1/2 cursor-pointer rounded-full bg-black/50 p-3 text-white transition hover:bg-black/70 sm:left-6"
                    aria-label="Previous">
                    <flux:icon.chevron-left class="size-6" />
                </button>
                <button type="button" x-show="frames.length > 1" x-on:click.stop="next()"
                    class="absolute right-2 top-1/2 z-30 -translate-y-1/2 cursor-pointer rounded-full bg-black/50 p-3 text-white transition hover:bg-black/70 sm:right-6"
                    aria-label="Next">
                    <flux:icon.chevron-right class="size-6" />
                </button>

                <button type="button" x-on:click.stop="close()"
                    class="absolute right-2 top-2 z-30 cursor-pointer rounded-full bg-black/50 p-2 text-white transition hover:bg-black/70 sm:right-6 sm:top-6"
                    aria-label="Close">
                    <flux:icon.x-mark class="size-6" />
                </button>
            </div>
        </template>
    </div>

</x-page.shell>

@script
<script>
    Alpine.data('timelapseLightbox', (frames) => ({
        frames,
        index: null,

        open(i) { this.index = i; },
        close() { this.index = null; },
        current() { return this.index === null ? null : this.frames[this.index]; },
        prev() { if (this.index !== null && this.frames.length) this.index = (this.index - 1 + this.frames.length) % this.frames.length; },
        next() { if (this.index !== null && this.frames.length) this.index = (this.index + 1) % this.frames.length; },
    }));

    // Page-wide photo selection: one mode, one set of ids, so a selection
    // can span collections and the bar/modal see the same state.
    // One row on arrival, then a few more per click. Revealing EVERY tile at
    // once meant one click asked for every remaining photo at the same time —
    // the click felt stuck until they all landed. A bounded step keeps each
    // press cheap. The column count is a breakpoint decision, so read it off
    // the rendered grid rather than duplicating the breakpoints here — resize
    // and it re-measures.
    Alpine.data('photoRows', (total) => ({
        total,
        rows: 1,
        perRow: 3,
        STEP: 3,
        init() {
            this.measure();
            this.onResize = () => this.measure();
            window.addEventListener('resize', this.onResize);
        },
        destroy() { window.removeEventListener('resize', this.onResize); },
        measure() {
            const grid = this.$el.querySelector('[data-photo-grid]');
            if (!grid) return;
            const cols = getComputedStyle(grid).gridTemplateColumns.split(' ').filter(Boolean).length;
            if (cols) this.perRow = cols;
        },
        get shown() { return this.rows * this.perRow; },
        show(index) { return index < this.shown; },
        get more() { return this.total > this.shown; },
        get remaining() { return Math.max(0, this.total - this.shown); },
        get opened() { return this.rows > 1; },
        showMore() { this.rows += this.STEP; },
        showLess() { this.rows = 1; },
    }));

    Alpine.store('picsel', {
        on: false,
        ids: [],   // frame ids (the project's own photos)
        msgs: [],  // stored media values of texted photos
        start() { this.on = true; this.ids = []; this.msgs = []; },
        stop() { this.on = false; this.ids = []; this.msgs = []; },
        toggle(id) {
            this.ids = this.ids.includes(id) ? this.ids.filter((i) => i !== id) : [...this.ids, id];
        },
        has(id) { return this.ids.includes(id); },
        toggleMsg(raw) {
            this.msgs = this.msgs.includes(raw) ? this.msgs.filter((r) => r !== raw) : [...this.msgs, raw];
        },
        hasMsg(raw) { return this.msgs.includes(raw); },
        get count() { return this.ids.length + this.msgs.length; },
    });

    Alpine.data('timelapsePermissionCallout', () => ({
        visible: false,
        instructions: '',

        init() {
            if (localStorage.getItem('tlPermCalloutDismissed')) return;

            this.instructions = this.instructionsForBrowser();

            // If the browser says the grant is already durable, stay quiet.
            if (navigator.permissions && navigator.permissions.query) {
                navigator.permissions.query({ name: 'camera' })
                    .then((status) => {
                        this.visible = status.state !== 'granted';
                        status.onchange = () => { if (status.state === 'granted') this.visible = false; };
                    })
                    .catch(() => { this.visible = true; });
            } else {
                this.visible = true;
            }
        },

        instructionsForBrowser() {
            const ua = navigator.userAgent;
            const isIos = /iPad|iPhone|iPod/.test(ua)
                || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
            // The Home-Screen app has no address bar, so no in-Safari path
            // exists — and iOS never persists a web app's camera grant anyway
            // (it re-asks every launch; long-standing WebKit limitation).
            const isInstalledApp = navigator.standalone === true
                || window.matchMedia('(display-mode: standalone)').matches;

            if (isIos && isInstalledApp) {
                return 'iPhone asks again each time you reopen the Hive app — an Apple limit on Home Screen apps. Tap Allow when asked, or stop the prompts for good in Settings → Apps → Safari → Camera → Allow.';
            }
            if (isIos && /CriOS|FxiOS|EdgiOS/.test(ua)) {
                return 'Open iOS Settings → Apps → your browser → Camera and choose Allow, so it stops asking on every visit.';
            }
            if (isIos) {
                return 'Tap the page-menu icon in Safari’s address bar → Camera → Allow. Or in Settings → Apps → Safari → Settings for Websites → Camera, set this site to Allow.';
            }
            if (/Edg\//.test(ua)) {
                return 'Click the lock icon next to the address → Permissions for this site → Camera → Allow.';
            }
            if (/Firefox/.test(ua)) {
                return 'When Firefox asks for the camera, tick “Remember this decision” before clicking Allow — or click the camera icon in the address bar to change it.';
            }
            if (/Chrome\//.test(ua) && !/OPR|Brave/.test(ua)) {
                return 'Click the icon left of the address → Site settings → Camera → Allow (on Android, choose “While visiting the site”, not “Only this time”).';
            }
            if (/Safari/.test(ua)) {
                return 'In the Safari menu choose Settings → Websites → Camera and set this site to Allow.';
            }

            return 'Allow camera access for this site in your browser’s site settings so it stops asking each visit.';
        },

        dismiss() {
            this.visible = false;
            localStorage.setItem('tlPermCalloutDismissed', '1');
        },
    }));

    Alpine.data('timelapseCapture', ($wire, initialOnion, isSequence = true) => ({
        // A photo album isn't a sequence: no onion skin, no alignment guide —
        // just shoot. Only timelapses need a frame to line up against.
        isSequence,
        cameraReady: false,
        cameraError: '',
        needsTap: false,
        // Two different things, deliberately not one flag:
        //   shutter   — the instant the pixels are grabbed (a few hundred ms)
        //   uploading — how many shots are still in flight to the server
        // Only the first one blocks the button; the upload rides along behind.
        shutter: false,
        uploading: 0,
        failed: 0,
        queue: [],
        onionOn: true,
        onionOpacity: 45,
        onionSrc: initialOnion,
        stream: null,
        // Latest GPS fix while the camera is open — stamped onto every shot.
        // Null when the user declined or the device has no fix; frames still
        // save, just without a location.
        geo: null,
        geoWatch: null,

        // Live framing guide: the viewfinder is compared against the last
        // frame ~5×/s on tiny grayscale thumbnails. Cheap enough for any
        // phone; no depth sensor needed (and Safari exposes none anyway).
        aligned: false,
        alignedStreak: 0,
        lost: false,
        bestScore: 0,
        motion: 0,
        lastLiveThumb: null,
        armingCapture: false,
        MOTION_STILL: 6, // mean gray delta between ticks below this = holding steady
        fullscreen: false,
        // Tapping X in fullscreen means "stay small until I rotate again" —
        // without this flag the orientation watcher would slam it back open.
        manualExit: false,
        guideVisible: false,
        bubbleX: 0,
        bubbleY: 0,
        hint: 'Match the last frame',
        guideColor: '#fbbf24',
        refThumb: null,
        guideTimer: null,
        THUMB_W: 96,
        THUMB_H: 72,
        SEARCH: 10,   // ± thumbnail pixels searched (≈ ±10% of frame)
        OK_OFFSET: 1.6, // thumbnail px — inside this, the shot counts as lined up
        OK_SCORE: 0.55, // minimum normalized correlation (content must match)
        // Measured: pointing at a completely different scene still correlates
        // ~0.35 (smooth luminance regions match by accident). Red below 0.42;
        // genuinely matching views score well above 0.55.
        LOST_SCORE: 0.42,

        init() {
            this.start();
            if (!this.isSequence) { this.onionSrc = ''; this.onionOn = false; }
            this.$watch('onionSrc', () => this.prepareRefThumb());
            if (this.onionSrc) this.prepareRefThumb();
            this.guideTimer = setInterval(() => this.scoreAlignment(), 200);
            // iOS re-orients the stream on rotation, but not always mid-
            // stream — reacquire so the aspect is truthful.
            this.onRotate = () => setTimeout(() => this.start(), 400);
            window.addEventListener('orientationchange', this.onRotate);
            // Rotating a phone to landscape makes the viewfinder the whole
            // screen; back upright collapses it. Change-driven and gated on a
            // coarse pointer, so desktops (always landscape) never trigger it.
            this.landscapeMq = window.matchMedia('(orientation: landscape)');
            this.onOrient = () => {
                if (!this.landscapeMq.matches) {
                    this.fullscreen = false;
                    this.manualExit = false;
                } else if (this.cameraReady && !this.manualExit && window.matchMedia('(pointer: coarse)').matches) {
                    this.fullscreen = true;
                }
            };
            this.landscapeMq.addEventListener('change', this.onOrient);
        },

        enterFullscreen() { this.fullscreen = true; this.manualExit = false; },

        exitFullscreen() { this.fullscreen = false; this.manualExit = true; },

        // Largest centered LANDSCAPE 4:3 rectangle of the stream. A landscape
        // stream passes through whole; a portrait stream contributes its
        // middle band — the phone can stay upright and still produce a frame
        // that matches the sequence.
        cropRect(vw, vh) {
            const sw = Math.min(vw, Math.round(vh * 4 / 3));
            const sh = Math.round(sw * 3 / 4);
            return { sx: Math.round((vw - sw) / 2), sy: Math.round((vh - sh) / 2), sw, sh };
        },

        grayFrom(source, w, h) {
            const canvas = this.$refs.thumbCanvas || (this.$refs.thumbCanvas = document.createElement('canvas'));
            canvas.width = w; canvas.height = h;
            const ctx = canvas.getContext('2d', { willReadFrequently: true });
            // The live video is judged on the same centered band capture will
            // store, or the guide would chase pixels that never land in the
            // frame.
            if (source instanceof HTMLVideoElement && source.videoWidth) {
                const c = this.cropRect(source.videoWidth, source.videoHeight);
                ctx.drawImage(source, c.sx, c.sy, c.sw, c.sh, 0, 0, w, h);
            } else {
                ctx.drawImage(source, 0, 0, w, h);
            }
            const { data } = ctx.getImageData(0, 0, w, h);
            const gray = new Float32Array(w * h);
            let sum = 0;
            for (let i = 0; i < w * h; i++) {
                const v = 0.299 * data[i * 4] + 0.587 * data[i * 4 + 1] + 0.114 * data[i * 4 + 2];
                gray[i] = v; sum += v;
            }
            const mean = sum / (w * h);
            for (let i = 0; i < w * h; i++) gray[i] -= mean;
            return gray;
        },

        prepareRefThumb() {
            if (!this.isSequence || !this.onionSrc) { this.refThumb = null; this.guideVisible = false; return; }
            const img = new Image();
            img.onload = () => {
                try { this.refThumb = this.grayFrom(img, this.THUMB_W, this.THUMB_H); this.guideVisible = true; }
                catch (e) { this.refThumb = null; this.guideVisible = false; }
            };
            img.src = this.onionSrc;
        },

        // Normalized cross-correlation of ref vs live at offset (dx, dy).
        nccAt(ref, live, dx, dy) {
            const w = this.THUMB_W, h = this.THUMB_H;
            let dot = 0, refSq = 0, liveSq = 0;
            for (let y = Math.max(0, -dy); y < Math.min(h, h - dy); y++) {
                const rowR = y * w, rowL = (y + dy) * w;
                for (let x = Math.max(0, -dx); x < Math.min(w, w - dx); x++) {
                    const a = ref[rowR + x], b = live[rowL + x + dx];
                    dot += a * b; refSq += a * a; liveSq += b * b;
                }
            }
            return dot / (Math.sqrt(refSq * liveSq) || 1);
        },

        scoreAlignment() {
            const video = this.$refs.video;

            if (!this.refThumb || !this.cameraReady || !video || !video.videoWidth || this.shutter) {
                this.aligned = false;
                this.lost = false;
                return;
            }

            let live;
            try { live = this.grayFrom(video, this.THUMB_W, this.THUMB_H); }
            catch (e) { return; }

            // Frame-to-frame motion: high while panning/walking, near zero
            // when holding steady. This is what gates the actual capture —
            // a shot taken mid-motion is a blurry shot.
            if (this.lastLiveThumb) {
                let sum = 0;
                for (let i = 0; i < live.length; i++) sum += Math.abs(live[i] - this.lastLiveThumb[i]);
                this.motion = sum / live.length;
            }
            this.lastLiveThumb = live;

            // Coarse (step 2) then fine (±1) search for the best offset.
            let best = { score: -2, dx: 0, dy: 0 };
            for (let dy = -this.SEARCH; dy <= this.SEARCH; dy += 2) {
                for (let dx = -this.SEARCH; dx <= this.SEARCH; dx += 2) {
                    const score = this.nccAt(this.refThumb, live, dx, dy);
                    if (score > best.score) best = { score, dx, dy };
                }
            }
            for (let dy = best.dy - 1; dy <= best.dy + 1; dy++) {
                for (let dx = best.dx - 1; dx <= best.dx + 1; dx++) {
                    const score = this.nccAt(this.refThumb, live, dx, dy);
                    if (score > best.score) best = { score, dx, dy };
                }
            }

            // Nowhere near the saved view: no offset in the whole search
            // range makes the content match. Park a red dot in the center —
            // direction hints would be noise.
            this.bestScore = best.score;
            this.lost = best.score < this.LOST_SCORE;

            if (this.lost) {
                this.bubbleX = 0;
                this.bubbleY = 0;
                this.aligned = false;
                return;
            }

            // The floating cross sits where the last frame's view actually is
            // (+dx, not -dx: the old dot was mirrored, which made the correct
            // move "pan away from the dot" — hence never intuitive). Panning
            // toward the cross now converges: the offset shrinks and the
            // cross walks into the center.
            const px = 44 / this.SEARCH;
            this.bubbleX = Math.max(-44, Math.min(44, best.dx * px));
            this.bubbleY = Math.max(-44, Math.min(44, best.dy * px));

            const distance = Math.hypot(best.dx, best.dy);
            const hit = best.score >= this.OK_SCORE && distance <= this.OK_OFFSET;

            // Continuous feedback while closing in: the cross warms from
            // amber (hue 45) to green (hue 120) with proximity, and the chip
            // says the actual move — pan toward the cross.
            const proximity = Math.max(0, 1 - distance / this.SEARCH);
            this.guideColor = hit ? '#4ade80' : `hsl(${Math.round(45 + proximity * 75)} 90% 55%)`;

            const dirs = [];
            if (this.bubbleY < -6) dirs.push('up'); else if (this.bubbleY > 6) dirs.push('down');
            if (this.bubbleX < -6) dirs.push('left'); else if (this.bubbleX > 6) dirs.push('right');
            const arrows = {
                'up': '↑', 'down': '↓', 'left': '←', 'right': '→',
                'up left': '↖', 'up right': '↗', 'down left': '↙', 'down right': '↘',
            };
            const key = dirs.join(' ');
            this.hint = key ? `${arrows[key]} Pan ${key}` : 'Almost there — hold still';

            // Green only after TWO consecutive passing ticks: crossing the
            // target mid-swing shouldn't flash green and invite a moving tap.
            this.alignedStreak = hit ? this.alignedStreak + 1 : 0;
            this.aligned = this.alignedStreak >= 2;
        },

        async start() {
            this.stop();
            try {
                // ONE getUserMedia per page: the stream stays live and every
                // capture reads from it — nothing here re-prompts. Whether the
                // browser asks again on the NEXT visit is its permission
                // policy (iOS Safari defaults to per-visit; the tip below
                // shows the setting that makes it permanent).
                this.stream = await navigator.mediaDevices.getUserMedia({
                    // Ask for the most the sensor will give: the stored
                    // original is whatever resolution lands here, and a
                    // capped request would throw away detail we can never
                    // recover. Browsers clamp to the nearest supported mode.
                    video: {
                        facingMode: 'environment',
                        width: { ideal: 4096 },
                        height: { ideal: 3072 },
                    },
                    audio: false,
                });
                this.$refs.video.srcObject = this.stream;
                this.cameraReady = true;
                this.cameraError = '';
                // Already sideways when the camera came up → go fullscreen now.
                if (this.onOrient) this.onOrient();
                // A live fix beats per-shot lookups: no shutter latency, and
                // the freshest position is always at hand. Watching starts
                // inside the same gesture that started the camera.
                if (this.geoWatch === null && navigator.geolocation) {
                    this.geoWatch = navigator.geolocation.watchPosition(
                        (pos) => {
                            this.geo = {
                                lat: pos.coords.latitude,
                                lng: pos.coords.longitude,
                                accuracy: Math.round(pos.coords.accuracy || 0),
                            };
                        },
                        () => { this.geo = null; },
                        { enableHighAccuracy: true, maximumAge: 15000, timeout: 20000 },
                    );
                }
            } catch (e) {
                this.cameraReady = false;
                this.cameraError = this.describeCameraError(e);
                // iOS Safari refuses getUserMedia that isn't inside a user
                // gesture — and opening this card goes through a Livewire
                // round trip, so the tap is over by the time we ask. Offer a
                // button whose own tap IS the gesture.
                this.needsTap = !e || e.name === 'NotAllowedError';
            }
        },

        describeCameraError(e) {
            if (!window.isSecureContext) {
                return 'Cameras only work over HTTPS — open this page with https://';
            }

            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                return 'This browser has no camera support.';
            }

            switch (e && e.name) {
                case 'NotAllowedError':
                    return 'Camera access is blocked for this site. Tap Enable camera, and choose Allow.';
                case 'NotFoundError':
                case 'OverconstrainedError':
                    return 'No camera found on this device.';
                case 'NotReadableError':
                    return 'The camera is in use by another app — close it and try again.';
                default:
                    return 'Could not start the camera. Tap Enable camera to try again.';
            }
        },


        stop() {
            if (this.stream) {
                this.stream.getTracks().forEach((t) => t.stop());
                this.stream = null;
            }
            if (this.geoWatch !== null) {
                navigator.geolocation.clearWatch(this.geoWatch);
                this.geoWatch = null;
            }
        },

        // Wait out the tap's own shake: capture fires once the phone holds
        // still (or after 1s regardless — never eat the shot).
        captureSharp() {
            if (this.shutter || this.armingCapture) return;
            this.armingCapture = true;
            const started = performance.now();
            const attempt = () => {
                if (this.motion <= this.MOTION_STILL || performance.now() - started > 1000) {
                    this.armingCapture = false;
                    this.capture();
                    return;
                }
                setTimeout(attempt, 100);
            };
            setTimeout(attempt, 120);
        },

        capture() {
            const video = this.$refs.video;
            if (!video.videoWidth || this.shutter) return;

            // Always the centered landscape band — a portrait-held phone
            // still stores a landscape frame.
            const c = this.cropRect(video.videoWidth, video.videoHeight);
            const canvas = document.createElement('canvas');
            canvas.width = c.sw;
            canvas.height = c.sh;
            canvas.getContext('2d').drawImage(video, c.sx, c.sy, c.sw, c.sh, 0, 0, c.sw, c.sh);

            // Shutter is over the moment the pixels are in hand.
            this.shutter = true;
            setTimeout(() => { this.shutter = false; }, 250);

            // The shot just taken IS the next onion skin — local pixels, no
            // waiting for the server.
            this.onionSrc = canvas.toDataURL('image/jpeg', 0.6);

            // The shutter's own moment and the freshest GPS fix travel with
            // the pixels — a canvas JPEG has no EXIF to carry either.
            const meta = {
                takenAt: new Date().toISOString(),
                lat: this.geo?.lat ?? null,
                lng: this.geo?.lng ?? null,
                accuracy: this.geo?.accuracy ?? null,
            };

            canvas.toBlob((blob) => {
                this.queue.push({ blob, meta });
                this.pump();
                // 0.98: the archive copy is encoded once, here — anything
                // lost now is lost for good. The server derives the smaller
                // sequence copy from it.
            }, 'image/jpeg', 0.98);
        },

        /**
         * Sends queued shots one at a time in the background. Serial on
         * purpose: they all land on the same Livewire property, so two in
         * flight would overwrite each other.
         */
        pump() {
            if (this.uploading || !this.queue.length) return;

            const { blob, meta } = this.queue.shift();
            this.uploading++;

            // Deferred set (third arg false): no request of its own — it
            // rides the upload's finish commit, so the metadata is on the
            // component before updatedFrame() runs. Serial uploads keep each
            // meta paired with its own shot.
            $wire.set('captureMeta', meta, false);

            $wire.upload('frame', new File([blob], 'frame.jpg', { type: 'image/jpeg' }),
                () => { this.uploading--; this.pump(); },
                () => { this.uploading--; this.failed++; this.pump(); },
            );
        },

        destroy() {
            this.stop();
            clearInterval(this.guideTimer);
            window.removeEventListener('orientationchange', this.onRotate);
            if (this.landscapeMq) this.landscapeMq.removeEventListener('change', this.onOrient);
        },
    }));
</script>
@endscript
