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
                x-data="timelapseCapture($wire, @js($this->latestFrame ? route('projects.timelapse.frame', [$this->latestFrame, 'v' => $this->latestFrame->version]) : ''), @js((bool) $this->collection?->isTimelapse()), @js('tl-lens-'.$this->collection?->id), @js($this->collection?->id))"
                class="space-y-3"
                x-on:keydown.escape.window="fullscreen && exitFullscreen()"
            >
                {{-- Viewfinder: live camera with the last frame ghosted on
                     top. The ring is the framing guide — the live image is
                     compared against the last frame a few times a second, and
                     the ring turns green when the shot lines up.

                     Fullscreen PORTALS this node to <body> (openFullscreen):
                     fixed inset-0 only fills the real screen from there — from
                     inside the card, any transformed ancestor becomes the
                     containing block and swallows the overlay, which is why
                     fullscreen used to show nothing. --}}
                {{-- Position class lives ONLY in the binding: a static
                     `relative` would tie with the bound `fixed` and win on
                     stylesheet order (Tailwind emits .fixed before .relative),
                     collapsing fullscreen to a zero-height block. --}}
                <div
                    x-ref="viewfinder"
                    class="overflow-hidden bg-zinc-950 ring-4 transition-[--tw-ring-color] duration-300"
                    x-show="cameraReady" x-cloak
                    x-bind:class="(fullscreen ? 'fixed left-0 top-0 z-50 w-full h-[100dvh] rounded-none bg-black' : 'relative aspect-[4/3] rounded-lg') + ' ' + (aligned ? 'ring-green-500 cursor-pointer' : (onionSrc ? 'ring-zinc-500/40' : 'ring-transparent'))"
                    x-on:click="aligned && !shutter && captureSharp()"
                >
                    {{-- object-cover: the box shows exactly what capture will
                         store — a portrait stream's centered landscape band.
                         Fullscreen letterboxes (contain) so nothing is hidden
                         while framing. --}}
                    {{-- Explicit z on video and every overlay: iOS composites a
                         playing <video> into its own layer, and positioned
                         siblings with no z-index can paint UNDERNEATH it —
                         the onion ghost was invisible on iPhones (and again
                         after every lens switch re-promoted the layer) while
                         desktops looked fine. --}}
                    <video x-ref="video" autoplay playsinline muted
                        class="absolute inset-0 z-0 h-full w-full"
                        x-bind:class="fullscreen ? 'object-contain' : 'object-cover'"></video>

                    {{-- The lens-change transition, native-camera style: iOS
                         permits ONE live camera stream — the OS kills the old
                         one the instant a new request is granted, so any
                         "keep the old lens playing during the handover" plan
                         shows a dead black stream on an iPhone. Instead the
                         last live frame is frozen BEFORE the stream is
                         touched, shown slightly blurred while the sensor
                         swaps, and faded out once the new lens has actually
                         painted. Nothing black is ever on screen. --}}
                    <img x-show="switching && frozenSrc" x-cloak
                        x-bind:src="frozenSrc || null"
                        class="pointer-events-none absolute inset-0 z-[1] h-full w-full blur-[2px] transition-opacity duration-300"
                        x-bind:class="(fullscreen ? 'object-contain' : 'object-cover') + ' ' + (stillFading ? 'opacity-0' : 'opacity-100')"
                        alt=""
                    />
                    <img
                        x-show="onionOn && onionSrc"
                        x-bind:src="onionSrc || null"
                        class="pointer-events-none absolute inset-0 z-[5] h-full w-full"
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
                    <div x-show="onionSrc && guideVisible" x-cloak class="pointer-events-none absolute inset-0 z-[6]">
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

                    {{-- state chip: says the actual move to make. Fullscreen
                         clamps to the safe areas — landscape (the auto-
                         fullscreen orientation) puts the notch on a SIDE. --}}
                    <div x-show="onionSrc" x-cloak class="absolute z-10 rounded px-2 py-0.5 text-xs font-medium"
                        x-bind:class="(fullscreen ? 'left-[max(0.5rem,env(safe-area-inset-left))] top-[max(0.5rem,env(safe-area-inset-top))]' : 'left-2 top-2') + ' ' + (aligned ? 'bg-green-500/90 text-white' : (lost ? 'bg-red-500/90 text-white' : (scaleHint ? 'bg-amber-500/90 text-white' : 'bg-black/60 text-white')))"
                        x-text="aligned ? 'Lined up — take the frame' : (lost ? `Find the last frame’s view` : (guideVisible ? hint : ''))"></div>

                    {{-- Fullscreen chrome: exit up top, a camera-app shutter
                         floating at the bottom — the button row below the
                         viewfinder is hidden while the viewfinder IS the
                         screen. --}}
                    {{-- Safe-area margins: the notch and the home indicator sit
                         exactly where these controls live once the viewfinder
                         IS the screen. --}}
                    <button type="button" x-show="fullscreen" x-cloak x-on:click.stop="exitFullscreen()"
                        class="absolute right-[max(0.5rem,env(safe-area-inset-right))] top-[max(0.5rem,env(safe-area-inset-top))] z-20 rounded-full bg-black/60 p-2 text-white">
                        <flux:icon.x-mark class="size-5" />
                    </button>
                    <button type="button" x-show="fullscreen" x-cloak x-on:click.stop="captureSharp()" x-bind:disabled="shutter"
                        class="absolute bottom-[max(0.75rem,env(safe-area-inset-bottom))] left-1/2 z-20 -translate-x-1/2 rounded-full border-2 border-white/60 p-1"
                        x-bind:class="aligned ? 'bg-green-500' : 'bg-black/60'">
                        <span class="block size-10 rounded-full bg-white"></span>
                    </button>

                    {{-- Manual way into fullscreen — rotation is the usual
                         trigger, but a tap should work on any device. --}}
                    <button type="button" x-show="!fullscreen" x-cloak x-on:click.stop="enterFullscreen()"
                        class="absolute bottom-2 right-2 z-10 rounded bg-black/50 p-1.5 text-white">
                        <flux:icon.arrows-pointing-out class="size-4" />
                    </button>

                    {{-- Lens switcher (0.5× / 1× / 2×), camera-app style. A
                         sequence lives on ONE lens: a first frame shot at 0.5×
                         can never be matched through the 1× lens. The pick is
                         remembered per collection, so the next visit reopens
                         on the lens the sequence was started with. --}}
                    {{-- Fullscreen stacks the chrome in bands measured off the
                         bottom safe area, camera-app style: shutter on the
                         floor, zoom pills directly above it, steadying note
                         above those. Corner-stacking them collided on a
                         phone. --}}
                    <div x-show="lenses.length > 1" x-cloak x-on:click.stop
                        class="absolute z-30 flex gap-1"
                        x-bind:class="fullscreen ? 'bottom-[calc(env(safe-area-inset-bottom)+5rem)] left-1/2 -translate-x-1/2' : 'bottom-2 left-2'">
                        <template x-for="lens in lenses" :key="lens.id">
                            <button type="button" x-on:click="selectLens(lens)"
                                class="min-w-9 cursor-pointer rounded-full px-2 py-1.5 text-xs font-semibold transition"
                                x-bind:class="(lens.zoom != null ? lens.zoom === activeZoom : lens.id === activeLensId) ? 'bg-white text-black' : 'bg-black/50 text-white'"
                                x-text="lens.label"></button>
                        </template>
                    </div>

                    {{-- Why a pill just disappeared ("0.5× isn't available in
                         this browser") — shows briefly, then clears. --}}
                    <div x-show="lensNote" x-cloak class="absolute inset-x-0 z-30 text-center"
                        x-bind:class="fullscreen ? 'bottom-[calc(env(safe-area-inset-bottom)+8.5rem)]' : 'top-8'">
                        <span class="rounded bg-black/70 px-2 py-0.5 text-xs font-medium text-amber-300" x-text="lensNote"></span>
                    </div>

                    {{-- steadying: between tap and shutter. Rides above the
                         fullscreen shutter button, which owns bottom-center. --}}
                    <div x-show="armingCapture" x-cloak class="absolute inset-x-0 z-10 text-center"
                        x-bind:class="fullscreen ? 'bottom-[calc(env(safe-area-inset-bottom)+8.5rem)]' : 'bottom-2'">
                        <span class="rounded bg-black/60 px-2 py-0.5 text-xs font-medium text-white">Hold still…</span>
                    </div>

                    {{-- Uploads ride along behind the shutter — a chip, not a
                         veil, so the next shot is never waiting on the last. --}}
                    {{-- In fullscreen this steps left so the X button (same
                         corner, higher z) can't paint over a "failed to save". --}}
                    <div x-show="$store.tlUploads.pending || $store.tlUploads.failed" x-cloak
                        class="absolute z-10 flex items-center gap-1 rounded bg-black/60 px-2 py-0.5 text-xs font-medium text-white"
                        x-bind:class="fullscreen ? 'right-[calc(env(safe-area-inset-right)+3.5rem)] top-[max(0.5rem,env(safe-area-inset-top))]' : 'right-2 top-2'">
                        <span x-show="$store.tlUploads.pending" class="flex items-center gap-1">
                            <flux:icon.arrow-path class="size-3 animate-spin" />
                            {{-- Percent while the bytes move — a multi-MB
                                 photo on jobsite signal takes long enough
                                 that a bare "Saving…" reads as stuck. --}}
                            <span x-text="($store.tlUploads.pending > 1 ? `Saving ${$store.tlUploads.pending}` : 'Saving')
                                + ($store.tlUploads.progress > 0 ? ` · ${$store.tlUploads.progress}%` : '…')"></span>
                        </span>
                        {{-- Not just a count: the pixels are still here, so
                             offer the way to send them again. --}}
                        <button type="button" x-show="$store.tlUploads.failed" x-on:click.stop="$store.tlUploads.retryFailed()"
                            class="flex cursor-pointer items-center gap-1 text-red-300 underline underline-offset-2">
                            <span x-text="`${$store.tlUploads.failed} didn’t save — retry`"></span>
                        </button>
                    </div>

                    {{-- shutter flash --}}
                    <div x-show="shutter" x-cloak class="pointer-events-none absolute inset-0 z-20 bg-white/70"></div>
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

    /**
     * The outbox for captured frames — a STORE, not component state, because
     * it has to outlive the camera. Closing the camera or switching
     * collections tears the Alpine component down, and a queue living there
     * took the unsent pixels with it: exactly the frames the retry exists to
     * save. The store belongs to the page, so shots keep flying either way.
     *
     * Every shot carries its own collectionId (stamped into the upload
     * filename, resolved server-side in updatedFrame), so a frame that lands
     * after the camera moved on is still filed where it was SHOT.
     *
     * Serial on purpose: uploads all land on the same Livewire property, so
     * two in flight would overwrite each other.
     */
    // Guarded: re-registering would hand the page a fresh empty store and
    // drop whatever was still in flight.
    if (!Alpine.store('tlUploads')) Alpine.store('tlUploads', {
        // A CLOSURE over the raw $wire, never $wire itself: the store is
        // Vue-reactive, and calling a Livewire proxy through the reactive
        // wrapper leaks Vue's __v_raw probe into Livewire's method dispatch —
        // the server 500s trying to call a component method named "__v_raw".
        refresh: null,
        // The direct frame-upload endpoint (set at camera init) and the
        // request currently on the wire, so the watchdog can abort it.
        uploadUrl: null,
        activeXhr: null,
        queue: [],
        failedShots: [],
        uploading: false,
        // 0-100 while the file is on the wire — the chip shows it, because a
        // multi-MB photo on a jobsite uplink takes long enough that a bare
        // "Saving…" reads as stuck.
        progress: 0,
        timer: null,
        stallTimer: null,
        MAX_TRIES: 4,
        // A Livewire upload is three round-trips, and a failure in the LAST
        // one calls back to nobody — the queue would sit "Saving…" forever.
        // No progress for this long → cancel the upload ourselves and let the
        // retry/backoff path deal with it. Once the file has FULLY uploaded
        // (100%) the server is doing its part — give that phase far more
        // grace: cancelling there throws away a completed upload and re-sends
        // the whole file, which is exactly the "Saving in a loop" shape.
        STALL_MS: 30000,
        STALL_AFTER_UPLOADED_MS: 120000,

        /** Shots still trying, including the one in flight. */
        get pending() { return this.queue.length; },
        /** Shots that gave up — pixels still here, one tap from another go. */
        get failed() { return this.failedShots.length; },

        /**
         * The phone's side of the story, written into the timelapse log
         * channel — matched by timestamp with the server lines, one test
         * shot tells exactly which hop misbehaved. keepalive: survives
         * navigation. Never lets logging break uploading.
         */
        report(event, detail = {}) {
            try {
                fetch('/timelapse/client-log', {
                    method: 'POST',
                    keepalive: true,
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                    },
                    body: JSON.stringify({ event, detail }),
                }).catch(() => {});
            } catch (e) {}
        },

        add(shot) {
            shot.bytes = shot.blob?.size ?? null;
            this.queue.push(shot);
            this.report('shot queued', { bytes: shot.bytes, collection: shot.collectionId, queued: this.queue.length });
            this.pump();
        },

        retryFailed() {
            if (!this.failedShots.length) return;

            this.report('manual retry', { count: this.failedShots.length });
            this.failedShots.forEach((shot) => {
                shot.tries = 0;
                this.queue.push(shot);
            });
            this.failedShots = [];
            this.pump();
        },

        feedStallWatchdog(ms = this.STALL_MS) {
            clearTimeout(this.stallTimer);
            this.stallTimer = setTimeout(() => {
                this.report('stalled — aborting', { progress: this.progress, after_ms: ms });
                // Aborting fires the XHR's abort handler, which routes into
                // the same retry path as an explicit error.
                try { this.activeXhr?.abort(); } catch (e) {}
            }, ms);
        },

        settle() {
            clearTimeout(this.stallTimer);
            this.uploading = false;
            this.progress = 0;
        },

        /**
         * ONE request per shot: a plain authed POST straight to our own
         * endpoint. This replaced Livewire's $wire.upload, whose three-leg
         * handshake (commit → signed URL → file POST → finish commit) stalled
         * silently on iPhones — progress 0, no error, no server line, nothing
         * to debug. A single XHR has a status code or it has an abort; every
         * outcome lands in the retry path and the log.
         */
        pump() {
            if (this.uploading || !this.queue.length || !this.uploadUrl) return;

            // Peek, don't shift: the shot stays at the head until it actually
            // lands, so a dropped request on jobsite wifi retries the same
            // pixels instead of discarding them and ticking a counter.
            const shot = this.queue[0];
            this.uploading = true;
            this.progress = 0;
            this.feedStallWatchdog();

            const startedAt = Date.now();
            this.report('upload start', { bytes: shot.bytes, collection: shot.collectionId, try: (shot.tries || 0) + 1 });

            let settled = false;

            const done = () => {
                this.report('upload finished', { ms: Date.now() - startedAt });
                this.settle();
                this.activeXhr = null;
                this.queue.shift();
                // One cheap refresh once the queue drains, so the grid and
                // counts show the new frames without a per-shot re-render.
                if (!this.queue.length) { try { this.refresh?.(); } catch (e) {} }
                this.pump();
            };

            const failedOnce = (why) => {
                this.settle();
                this.activeXhr = null;
                shot.tries = (shot.tries || 0) + 1;
                this.report('upload ' + why, { try: shot.tries, ms: Date.now() - startedAt });

                if (shot.tries >= this.MAX_TRIES) {
                    this.report('gave up', { tries: shot.tries });
                    this.failedShots.push(this.queue.shift());
                    this.pump();

                    return;
                }

                clearTimeout(this.timer);
                this.timer = setTimeout(() => this.pump(), 900 * shot.tries);
            };

            const form = new FormData();
            form.append('frame', new File([shot.blob], 'frame.jpg', { type: 'image/jpeg' }));
            if (shot.collectionId) form.append('collection_id', shot.collectionId);
            const meta = shot.meta || {};
            if (meta.takenAt) form.append('taken_at', meta.takenAt);
            if (meta.lat != null) {
                form.append('lat', meta.lat);
                form.append('lng', meta.lng);
                if (meta.accuracy != null) form.append('accuracy', meta.accuracy);
            }

            const xhr = new XMLHttpRequest();
            this.activeXhr = xhr;
            xhr.open('POST', this.uploadUrl);
            xhr.setRequestHeader('X-CSRF-TOKEN', document.querySelector('meta[name="csrf-token"]')?.content ?? '');
            xhr.setRequestHeader('Accept', 'application/json');

            xhr.upload.addEventListener('progress', (e) => {
                if (!e.lengthComputable) return;
                this.progress = Math.floor(e.loaded * 100 / e.total);
                // File fully out the door → the remaining wait is the server
                // writing it. Long grace there: cancelling would throw away
                // a completed upload and re-send the whole file.
                this.feedStallWatchdog(this.progress >= 100 ? this.STALL_AFTER_UPLOADED_MS : this.STALL_MS);
            });
            xhr.addEventListener('load', () => {
                if (settled) return;
                settled = true;
                String(xhr.status)[0] === '2' ? done() : failedOnce('http ' + xhr.status);
            });
            xhr.addEventListener('error', () => {
                if (settled) return;
                settled = true;
                failedOnce('network error');
            });
            xhr.addEventListener('abort', () => {
                if (settled) return;
                settled = true;
                failedOnce('aborted');
            });

            xhr.send(form);
        },
    });

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

    Alpine.data('timelapseCapture', ($wire, initialOnion, isSequence = true, lensKey = 'tl-lens', collectionId = null) => ({
        // A photo album isn't a sequence: no onion skin, no alignment guide —
        // just shoot. Only timelapses need a frame to line up against.
        isSequence,
        // Stamped into each upload's filename so the server files the frame
        // where it was SHOT — a queued upload can land after the camera has
        // moved to another collection.
        collectionId,
        cameraReady: false,
        cameraError: '',
        needsTap: false,
        // Back lenses, camera-app style. A sequence must be shot through the
        // SAME optic every time — a 0.5× first frame can never line up
        // through the 1× lens. The pick is remembered per collection.
        //
        // TWO mechanisms, best one wins at runtime:
        //  - zoom: iPhones expose a virtual multi-lens camera whose zoom
        //    capability reaches below 1; applyConstraints({zoom}) switches
        //    the PHYSICAL lens inside the running stream — native-app
        //    seamless, nothing to restart, nothing to go black. Preferred
        //    wherever capabilities report min < 1.
        //  - deviceId: open the other lens as its own camera. The fallback,
        //    because on iOS the running stream dies with the request and on
        //    some phones the second stream is permanently black.
        lensKey,
        lenses: [],
        lensId: null,
        activeLensId: null,
        zoomMode: false,
        zoomCaps: null,
        activeZoom: 1,
        // A zoom-capable virtual camera is only tried once per component —
        // if it comes up black we stay on what works.
        virtualTried: false,
        // deviceId lenses that produced a black stream this session: dropped
        // from the pills instead of being offered again.
        lensUnavailable: [],
        // One-line explanation when a lens gets dropped ("0.5× isn't
        // available here") — cleared after a moment.
        lensNote: '',
        lensNoteTimer: null,
        savedZoom: null,
        // Lens change in progress: the frozen last-live-frame is on screen.
        switching: false,
        frozenSrc: '',
        stillFading: false,
        freezeTimer: null,

        get videoEl() { return this.$refs.video; },

        /**
         * Resolves once this element is actually showing pixels — not merely
         * "has a stream". requestVideoFrameCallback is exact where it exists
         * (Chrome/Safari 15.4+); the events are the fallback, and the timeout
         * guarantees a switch can never hang on a silent browser. Resolves
         * `true` only when frames are confirmed — a timeout resolves `false`
         * so the caller can treat "granted a stream that never paints" (a
         * real WebKit failure mode when camera switches race) as a failure.
         */
        firstFrame(el) {
            return new Promise((resolve) => {
                let settled = false;
                const done = (painted) => { if (!settled) { settled = true; resolve(painted); } };

                if (el.requestVideoFrameCallback) {
                    el.requestVideoFrameCallback(() => done(true));
                } else {
                    el.addEventListener('loadeddata', () => done(true), { once: true });
                    el.addEventListener('playing', () => done(true), { once: true });
                }

                setTimeout(() => done(false), 2500);
            });
        },

        /**
         * Are actual pixels flowing? Samples the video into a tiny canvas a
         * few times (~1s worst case) and calls it live as soon as any frame
         * is not essentially black. A genuinely dark room can fail this —
         * that's why the caller restarts at most twice and then shows the
         * feed as-is instead of looping.
         */
        async looksLive(video, gen) {
            const w = 32, h = 24;
            const canvas = document.createElement('canvas');
            canvas.width = w;
            canvas.height = h;
            const ctx = canvas.getContext('2d', { willReadFrequently: true });

            for (let i = 0; i < 5; i++) {
                if (gen !== this.startGen) return false;

                try {
                    ctx.drawImage(video, 0, 0, w, h);
                    const { data } = ctx.getImageData(0, 0, w, h);
                    let max = 0;
                    for (let p = 0; p < data.length; p += 4) {
                        const v = Math.max(data[p], data[p + 1], data[p + 2]);
                        if (v > max) max = v;
                    }

                    // Any real scene — even a dim one — clears this easily;
                    // a dead stream is a flat 0.
                    if (max > 16) return true;
                } catch (e) { /* frame not readable yet — try again */ }

                await new Promise((r) => setTimeout(r, 200));
            }

            return false;
        },

        /** Fade the frozen still out over the now-live viewfinder. */
        unfreeze() {
            if (!this.switching) return;
            this.stillFading = true;
            clearTimeout(this.freezeTimer);
            this.freezeTimer = setTimeout(() => {
                this.switching = false;
                this.frozenSrc = '';
                this.stillFading = false;
            }, 320);
        },
        // Only the shutter (the few hundred ms of grabbing pixels) blocks the
        // button. Sending lives in the $store.tlUploads queue, which outlives
        // this component on purpose — see the store.
        shutter: false,
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
        thumbCanvas: null,
        armingCapture: false,
        MOTION_STILL: 6, // mean gray delta between ticks below this = holding steady
        fullscreen: false,
        // Where the viewfinder goes back to after fullscreen — a comment node
        // left in its place while the node itself visits <body>.
        vfHome: null,
        viewportWas: undefined,
        // True only when the browser granted REAL fullscreen (Android). iPhone
        // Safari never does, so its absence must not be read as "user exited".
        nativeFs: false,
        // Generation counter for start(): each call supersedes the previous,
        // so a slow getUserMedia resolving late stops ITS OWN stream instead
        // of leaking it or clobbering a newer one.
        startGen: 0,
        rotateTimer: null,
        // Tapping X in fullscreen means "stay small until I rotate again" —
        // without this flag the orientation watcher would slam it back open.
        manualExit: false,
        guideVisible: false,
        bubbleX: 0,
        bubbleY: 0,
        hint: 'Match the last frame',
        guideColor: '#fbbf24',
        refThumb: null,
        // Zoomed copy of the reference + the distance verdict it produces.
        refThumbZoomed: null,
        scaleHint: '',
        scaleLast: '',
        scaleStreak: 0,
        probeTick: 0,
        // How much closer the probe simulates (~1 step forward in a room) and
        // how much better it must score before the guide says so.
        ZOOM_PROBE: 1.18,
        ZOOM_MARGIN: 0.05,
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
            // Hand the outbox a live $wire and let it drain anything a
            // previous camera (other collection, or one that was closed) left
            // unsent. $wire is the STUDIO component, which outlives the camera.
            // The closure keeps $wire RAW — see the store's `refresh` note.
            Alpine.store('tlUploads').refresh = () => $wire.$refresh();
            Alpine.store('tlUploads').uploadUrl = @js(route('projects.timelapse.frame.store', $project));
            Alpine.store('tlUploads').pump();

            // Remembered pick: {"zoom":0.5} or {"device":"..."} (older saves
            // were a bare device id).
            try {
                const saved = localStorage.getItem(this.lensKey);
                if (saved?.startsWith('{')) {
                    const parsed = JSON.parse(saved);
                    this.lensId = parsed.device ?? null;
                    this.savedZoom = parsed.zoom ?? null;
                } else {
                    this.lensId = saved || null;
                }
            } catch (e) {}
            this.start();
            if (!this.isSequence) { this.onionSrc = ''; this.onionOn = false; }
            this.$watch('onionSrc', () => this.prepareRefThumb());
            if (this.onionSrc) this.prepareRefThumb();
            this.guideTimer = setInterval(() => this.scoreAlignment(), 200);
            // iOS re-orients the stream on rotation, but not always mid-
            // stream — reacquire so the aspect is truthful. Tracked so a
            // rapid double-rotate collapses to one restart and destroy() can
            // cancel a pending one.
            this.onRotate = () => {
                clearTimeout(this.rotateTimer);
                this.rotateTimer = setTimeout(() => this.start(), 400);
            };
            window.addEventListener('orientationchange', this.onRotate);
            // Rotating a phone to landscape makes the viewfinder the whole
            // screen; back upright collapses it. Change-driven and gated on a
            // coarse pointer, so desktops (always landscape) never trigger it.
            this.landscapeMq = window.matchMedia('(orientation: landscape)');
            this.onOrient = () => {
                if (!this.landscapeMq.matches) {
                    this.closeFullscreen();
                    this.manualExit = false;
                } else if (this.cameraReady && !this.manualExit && window.matchMedia('(pointer: coarse)').matches) {
                    this.openFullscreen();
                }
            };
            this.landscapeMq.addEventListener('change', this.onOrient);

            // The browser can drop out of real fullscreen without telling us
            // (Android back gesture, Esc) — follow it rather than leaving the
            // overlay believing it is still up. Only meaningful where native
            // fullscreen was actually granted.
            this.onFsChange = () => {
                if (this.fullscreen && this.nativeFs && !document.fullscreenElement) {
                    this.exitFullscreen();
                }
            };
            document.addEventListener('fullscreenchange', this.onFsChange);
        },

        enterFullscreen() { this.openFullscreen(); this.manualExit = false; },

        exitFullscreen() { this.closeFullscreen(); this.manualExit = true; },

        /**
         * Fullscreen = move the viewfinder node itself to <body> and let its
         * fixed-inset-0 class fill the real viewport. Same-node move: the
         * stream, Alpine bindings, and listeners all ride along untouched.
         * Alpine.mutateDom keeps Alpine's mutation observer from trying to
         * re-initialize the (already live) moved tree; wire:ignore on the
         * camera root means Livewire never notices the node is gone.
         */
        openFullscreen() {
            if (this.fullscreen) return;
            const vf = this.$refs.viewfinder;
            if (!vf) return;
            // Pin the component's data stack onto the viewfinder itself:
            // <template x-for>/<template x-if> clones inside it resolve scope
            // by walking parentNode AT CLONE TIME, and from <body> that walk
            // never reaches the x-data root left behind in the card — lens
            // pills cloned while ported would be dead, upload chips blank.
            if (!vf._x_dataStack) Alpine.addScopeToNode(vf, {}, this.$el);
            this.vfHome = document.createComment('viewfinder-home');
            Alpine.mutateDom(() => {
                vf.before(this.vfHome);
                document.body.appendChild(vf);
            });
            this.fullscreen = true;
            document.body.style.overflow = 'hidden';
            // env(safe-area-inset-*) reads 0 unless the viewport is cover, but
            // cover shoves every OTHER page under the notch — so it is on only
            // while the overlay is, and restored on the way out.
            this.setViewportCover(true);

            // Real fullscreen where the browser offers it (Android Chrome):
            // that genuinely removes the address and tool bars. iPhone Safari
            // has no element fullscreen, which is why the sizing below is not
            // optional.
            vf.requestFullscreen?.({ navigationUI: 'hide' })
                .then(() => { this.nativeFs = true; })
                .catch(() => {});

            // A `fixed` overlay covers the LAYOUT viewport, which on a phone
            // continues underneath the browser's own toolbar — that is why
            // Safari's nav buttons sat on top of the camera. Size to the
            // VISUAL viewport instead, and follow it as the chrome slides in
            // and out.
            this.onViewportResize = () => this.sizeToViewport();
            window.visualViewport?.addEventListener('resize', this.onViewportResize);
            window.visualViewport?.addEventListener('scroll', this.onViewportResize);
            this.sizeToViewport();

            // Moving a <video> node can pause it (iOS) — nudge it back.
            this.$nextTick(() => this.videoEl?.play?.().catch(() => {}));
        },

        /** Match the overlay to what is actually on screen, chrome excluded. */
        sizeToViewport() {
            const vf = this.$refs.viewfinder;
            const vv = window.visualViewport;
            if (!vf || !this.fullscreen) return;

            if (vv) {
                vf.style.height = `${vv.height}px`;
                vf.style.width = `${vv.width}px`;
            } else {
                vf.style.height = `${window.innerHeight}px`;
            }
        },

        setViewportCover(on) {
            const meta = document.querySelector('meta[name="viewport"]');
            if (!meta) return;
            if (on && this.viewportWas === undefined) this.viewportWas = meta.content;
            if (on) {
                meta.content = `${this.viewportWas}, viewport-fit=cover`;
            } else if (this.viewportWas !== undefined) {
                meta.content = this.viewportWas;
                this.viewportWas = undefined;
            }
        },

        closeFullscreen() {
            if (!this.fullscreen) return;
            this.fullscreen = false;
            document.body.style.overflow = '';
            this.setViewportCover(false);

            window.visualViewport?.removeEventListener('resize', this.onViewportResize);
            window.visualViewport?.removeEventListener('scroll', this.onViewportResize);
            if (document.fullscreenElement) document.exitFullscreen?.().catch(() => {});
            this.nativeFs = false;

            const vf = this.$refs.viewfinder;
            // Inline sizing belongs to the overlay only — back in the card the
            // aspect-ratio class owns the box again.
            if (vf) { vf.style.height = ''; vf.style.width = ''; }
            if (vf && this.vfHome?.parentNode) {
                Alpine.mutateDom(() => {
                    this.vfHome.parentNode.insertBefore(vf, this.vfHome);
                    this.vfHome.remove();
                });
            }
            this.vfHome = null;
            this.$nextTick(() => this.videoEl?.play?.().catch(() => {}));
        },

        // Largest centered LANDSCAPE 4:3 rectangle of the stream. A landscape
        // stream passes through whole; a portrait stream contributes its
        // middle band — the phone can stay upright and still produce a frame
        // that matches the sequence.
        cropRect(vw, vh) {
            const sw = Math.min(vw, Math.round(vh * 4 / 3));
            const sh = Math.round(sw * 3 / 4);
            return { sx: Math.round((vw - sw) / 2), sy: Math.round((vh - sh) / 2), sw, sh };
        },

        /**
         * Mean-subtracted grayscale thumbnail. `zoom` > 1 samples a TIGHTER
         * centre and stretches it — simulating standing closer. Always crops
         * inward, never outward, so the source rect can't leave the frame;
         * the "step back" case is probed by zooming the REFERENCE instead.
         */
        grayFrom(source, w, h, zoom = 1) {
            // Plain property, not $refs: $refs is a merged proxy over the
            // x-ref registry, so stashing a scratch canvas there is not
            // reliably readable back — this ran several times a second and
            // could allocate a fresh canvas every call.
            const canvas = this.thumbCanvas || (this.thumbCanvas = document.createElement('canvas'));
            canvas.width = w; canvas.height = h;
            const ctx = canvas.getContext('2d', { willReadFrequently: true });
            // The live video is judged on the same centered band capture will
            // store, or the guide would chase pixels that never land in the
            // frame.
            if (source instanceof HTMLVideoElement && source.videoWidth) {
                const c = this.cropRect(source.videoWidth, source.videoHeight);
                const zw = c.sw / zoom, zh = c.sh / zoom;
                ctx.drawImage(source, c.sx + (c.sw - zw) / 2, c.sy + (c.sh - zh) / 2, zw, zh, 0, 0, w, h);
            } else {
                const sw = source.width / zoom, sh = source.height / zoom;
                ctx.drawImage(source, (source.width - sw) / 2, (source.height - sh) / 2, sw, sh, 0, 0, w, h);
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
            if (!this.isSequence || !this.onionSrc) {
                this.refThumb = null;
                this.refThumbZoomed = null;
                this.guideVisible = false;

                return;
            }
            const img = new Image();
            img.onload = () => {
                try {
                    this.refThumb = this.grayFrom(img, this.THUMB_W, this.THUMB_H);
                    // A zoomed-in copy of the reference: when THIS matches the
                    // live view better than the plain one, the live view is
                    // the zoomed-in one — you're standing too close.
                    this.refThumbZoomed = this.grayFrom(img, this.THUMB_W, this.THUMB_H, this.ZOOM_PROBE);
                    this.guideVisible = true;
                } catch (e) {
                    this.refThumb = null;
                    this.refThumbZoomed = null;
                    this.guideVisible = false;
                }
            };
            img.src = this.onionSrc;
        },

        /**
         * Best correlation of two thumbs over a small offset search, so a
         * distance probe isn't defeated by the pan being slightly off.
         */
        bestNear(ref, live, cx, cy, span = 4, step = 2) {
            let best = -2;

            for (let dy = cy - span; dy <= cy + span; dy += step) {
                for (let dx = cx - span; dx <= cx + span; dx += step) {
                    const score = this.nccAt(ref, live, dx, dy);
                    if (score > best) best = score;
                }
            }

            return best;
        },

        /**
         * Is the camera at the wrong DISTANCE? Panning can't fix that, and the
         * translation-only search just reads it as a poor match ("Find the
         * last frame's view") with nothing actionable to say. Probe both
         * directions: zoom the live in, and zoom the reference in. Whichever
         * beats the straight comparison by a clear margin names the move.
         * Run sparsely — this is a step-forward/step-back cue, not a jitter.
         */
        probeDistance(live, baseScore, dx, dy) {
            if (!this.refThumbZoomed) return;

            let closer = -2;
            try {
                closer = this.bestNear(this.refThumb, this.grayFrom(this.videoEl, this.THUMB_W, this.THUMB_H, this.ZOOM_PROBE), dx, dy);
            } catch (e) { /* frame not readable this tick */ }

            const back = this.bestNear(this.refThumbZoomed, live, dx, dy);
            const bestZoom = Math.max(closer, back);

            // Only speak up when a zoom genuinely explains the mismatch AND
            // the straight comparison isn't already good.
            if (bestZoom < baseScore + this.ZOOM_MARGIN || baseScore >= this.OK_SCORE) {
                this.scaleStreak = 0;
                this.scaleHint = '';

                return;
            }

            // Confirmed twice before it counts: this verdict suppresses the
            // red "lost" dot and blocks the green ring, so a single noisy
            // probe must not be able to park the guide in the wrong state.
            const direction = closer >= back ? 'Step closer' : 'Step back';
            this.scaleStreak = direction === this.scaleLast ? this.scaleStreak + 1 : 0;
            this.scaleLast = direction;
            this.scaleHint = this.scaleStreak >= 1 ? direction : '';
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
            const video = this.videoEl;

            // `switching` too: scoring a frozen still (or the black frame
            // behind it) against the reference would flash a false "lost".
            if (!this.refThumb || !this.cameraReady || !video || !video.videoWidth || this.shutter || this.switching) {
                this.aligned = false;
                this.lost = false;
                this.scaleHint = '';
                this.scaleStreak = 0;

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

            // A good straight match settles the distance question outright —
            // clear it THIS tick rather than letting a stale probe verdict
            // block the green ring until the next one runs.
            if (best.score >= this.OK_SCORE) {
                this.scaleHint = '';
                this.scaleStreak = 0;
            }

            // Distance is the one error panning can never fix, so check it
            // before deciding the view is simply "lost". Every 3rd tick
            // (~600ms) — it costs one extra thumbnail and a small search.
            if (++this.probeTick % 3 === 0) {
                this.probeDistance(live, best.score, best.dx, best.dy);
            }

            // A wrong-distance view scores like a wrong view. If a zoom probe
            // explains it, say which way to walk instead of the dead-end red
            // "Find the last frame's view".
            this.lost = best.score < this.LOST_SCORE && !this.scaleHint;

            if (this.lost || best.score < this.LOST_SCORE) {
                this.bubbleX = 0;
                this.bubbleY = 0;
                this.aligned = false;
                this.hint = this.scaleHint || this.hint;

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
            // Never green while the framing is the wrong size — a perfectly
            // panned shot from the wrong distance still breaks the sequence.
            const hit = best.score >= this.OK_SCORE && distance <= this.OK_OFFSET && !this.scaleHint;

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
            // Distance first: no amount of panning fixes standing in the
            // wrong place, so that instruction outranks the pan arrow.
            this.hint = this.scaleHint
                || (key ? `${arrows[key]} Pan ${key}` : 'Almost there — hold still');

            // Green only after TWO consecutive passing ticks: crossing the
            // target mid-swing shouldn't flash green and invite a moving tap.
            this.alignedStreak = hit ? this.alignedStreak + 1 : 0;
            this.aligned = this.alignedStreak >= 2;
        },

        async start(opts = {}) {
            // Supersede any start still in flight: whoever finishes with a
            // stale gen throws its stream away. Covers the rotate timer racing
            // a lens switch AND getUserMedia resolving after the component
            // died mid-prompt (destroy() bumps the gen too) — either way a
            // camera can't stay lit with nothing showing it.
            const gen = ++this.startGen;

            // ONE camera at a time, on every platform — not a compromise but
            // the only model iOS permits: the OS kills the running stream the
            // instant a new getUserMedia is granted, so an "overlap handover"
            // just shows a dead black stream on an iPhone. The order here is
            // the whole fix for the black flash:
            //   1. freeze the last LIVE frame (before anything is touched),
            //   2. release the old lens,
            //   3. acquire and wait for the new lens to actually paint,
            //   4. fade the still out over live pixels.
            if (this.stream) this.freezeFrame();
            this.stop();

            try {
                // Ask for the most the sensor will give: the stored original
                // is whatever resolution lands here, and a capped request
                // would throw away detail we can never recover. Browsers
                // clamp to the nearest supported mode. A remembered lens pins
                // the exact camera (0.5× vs 1×); otherwise whatever back
                // camera the browser prefers.
                const stream = await navigator.mediaDevices.getUserMedia({
                    video: {
                        ...(this.lensId
                            ? { deviceId: { exact: this.lensId } }
                            : { facingMode: 'environment' }),
                        width: { ideal: 4096 },
                        height: { ideal: 3072 },
                    },
                    audio: false,
                });

                const video = this.videoEl;

                if (gen !== this.startGen || !video) {
                    this.stopTracks(stream);

                    return;
                }

                this.stream = stream;
                video.srcObject = stream;
                // autoplay usually covers this, but iOS occasionally leaves a
                // re-assigned stream paused — an explicit play() costs nothing.
                video.play?.().catch(() => {});

                // Hold the still until the new lens is genuinely painting.
                const painted = await this.firstFrame(video);

                if (gen !== this.startGen) return;

                // Don't take the browser's word for it — WebKit happily
                // reports a stream as playing while delivering nothing but
                // black after a lens switch. Look at the actual PIXELS, and
                // if they stay black, restart the stream (stop + reacquire)
                // up to twice. The frozen still covers every attempt, so the
                // user sees the last live frame, then the new lens — never
                // the black in between.
                const live = painted && await this.looksLive(video, gen);

                if (gen !== this.startGen) return;

                if (!live && (opts.attempt ?? 0) < 1) {
                    this.stopTracks(stream);
                    this.stream = null;

                    return this.start({ ...opts, attempt: (opts.attempt ?? 0) + 1 });
                }

                // Still black after a restart: this lens does not work in
                // this browser (a real iOS failure mode — the ultra-wide via
                // deviceId can be permanently black). NEVER leave the user
                // on a black camera: drop the lens from the pills and go
                // back to the one that was live before the switch.
                if (!live && opts.switchFrom !== undefined && !opts.reverting) {
                    if (this.lensId) this.lensUnavailable.push(this.lensId);
                    this.lenses = this.lenses.filter((l) => l.id !== this.lensId);
                    this.lensId = opts.switchFrom;
                    this.persistLens();
                    this.noteLens("That lens isn't available in this browser");

                    return this.start({ reverting: true });
                }

                this.cameraReady = true;
                this.cameraError = '';
                this.activeLensId = stream.getVideoTracks()[0]?.getSettings()?.deviceId ?? null;

                // Seamless-zoom support: capabilities with min < 1 mean this
                // stream can move between physical lenses by itself.
                const caps = stream.getVideoTracks()[0]?.getCapabilities?.() ?? {};
                this.zoomCaps = caps.zoom && typeof caps.zoom.min === 'number' ? caps.zoom : null;
                this.zoomMode = !!(this.zoomCaps && this.zoomCaps.min < 1);

                if (this.zoomMode) {
                    this.buildZoomPills();
                    const startAt = this.savedZoom ?? this.activeZoom ?? 1;
                    this.applyZoom(Math.min(Math.max(startAt, this.zoomCaps.min), this.zoomCaps.max));
                }

                this.unfreeze();
                this.discoverLenses();
                // Already sideways when the camera came up → go fullscreen now.
                // Only when NOT already fullscreen: onOrient force-closes in
                // portrait, and start() is now also called by selectLens(),
                // which would collapse a manually-opened portrait fullscreen
                // out from under the tap that switched lenses.
                if (this.onOrient && !this.fullscreen) this.onOrient();
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
                // A newer start() owns the state now — a stale failure must
                // not clobber its stream with an error panel.
                if (gen !== this.startGen) return;

                // Forget the remembered lens ONLY when the error actually says
                // the id is bad (reshuffled ids, different phone). A transient
                // NotReadableError mid-rotation used to wipe the pin and
                // silently drop the sequence onto the wrong lens.
                if (this.lensId && !opts.lensRetry && ['OverconstrainedError', 'NotFoundError'].includes(e?.name)) {
                    this.lensId = null;
                    try { localStorage.removeItem(this.lensKey); } catch (err) {}

                    return this.start({ ...opts, lensRetry: true });
                }

                // The camera briefly held by the OS mid-switch resolves
                // itself — one quiet second-ask before surfacing an error.
                if (e?.name === 'NotReadableError' && !opts.reacquired) {
                    await new Promise((r) => setTimeout(r, 300));

                    if (gen !== this.startGen) return;

                    return this.start({ ...opts, reacquired: true });
                }

                this.switching = false;
                this.frozenSrc = '';
                this.stillFading = false;

                // A failed camera hides the viewfinder (x-show="cameraReady")
                // — while fullscreen that node holds the only touch exit and
                // the body scroll lock, so come home before showing the error.
                this.closeFullscreen();

                this.cameraReady = false;
                this.cameraError = this.describeCameraError(e);
                // iOS Safari refuses getUserMedia that isn't inside a user
                // gesture — and opening this card goes through a Livewire
                // round trip, so the tap is over by the time we ask. Offer a
                // button whose own tap IS the gesture.
                this.needsTap = !e || e.name === 'NotAllowedError';
            }
        },

        /**
         * The phone's back lenses, labeled like the camera app. Runs after the
         * stream is live because labels are empty before permission. Virtual
         * multi-lens devices ("Back Dual/Triple Camera") are skipped — they
         * auto-switch lenses, which is exactly what a sequence can't have.
         * Unlabeled duplicates (some Androids) collapse to one entry; with
         * fewer than two distinct lenses the switcher stays hidden.
         */
        /**
         * Populate the pills. In zoom mode they were already built from the
         * track's own capabilities; here we only look for the SEAMLESS
         * multi-lens virtual camera ("Back Dual/Triple Camera") and switch
         * onto it once — after that, every lens change is an
         * applyConstraints({zoom}) inside the running stream. Only if no such
         * camera exists do the pills fall back to one-device-per-lens.
         */
        async discoverLenses() {
            if (this.zoomMode) return;

            try {
                const devices = await navigator.mediaDevices.enumerateDevices();
                const backs = devices.filter((d) =>
                    d.kind === 'videoinput' && d.deviceId && /back|rear|environment/i.test(d.label));

                // The virtual multi-lens camera switches optics by ZOOM, the
                // way the native app does. Worth one still-covered switch to
                // get onto it; if it comes up black we revert and never try
                // again this session.
                const virtual = backs.find((d) => /dual|triple/i.test(d.label));

                if (virtual && !this.virtualTried && !this.lensUnavailable.includes(virtual.deviceId)
                    && virtual.deviceId !== this.activeLensId) {
                    this.virtualTried = true;
                    const from = this.lensId;
                    this.lensId = virtual.deviceId;

                    return this.start({ switchFrom: from ?? null });
                }

                const order = ['0.5×', '1×', '2×', '5×'];
                const seen = new Set();

                this.lenses = backs
                    .filter((d) => !this.lensUnavailable.includes(d.deviceId))
                    .map((d) => ({ id: d.deviceId, label: this.lensLabel(d.label) }))
                    .filter((l) => {
                        if (!l.label || seen.has(l.label)) return false;
                        seen.add(l.label);
                        return true;
                    })
                    .sort((a, b) => order.indexOf(a.label) - order.indexOf(b.label));

                if (this.lenses.length < 2) this.lenses = [];
            } catch (e) {
                this.lenses = [];
            }
        },

        lensLabel(raw) {
            if (/dual|triple/i.test(raw)) return null;
            if (/ultra/i.test(raw)) return '0.5×';
            if (/tele/i.test(raw)) return '2×';

            return '1×';
        },

        /** Pills from the zoom range: only steps the hardware actually has. */
        buildZoomPills() {
            const caps = this.zoomCaps;
            const presets = [
                { zoom: Math.min(Math.max(0.5, caps.min), 1), label: '0.5×' },
                { zoom: 1, label: '1×' },
                { zoom: 2, label: '2×' },
            ];

            this.lenses = presets
                .filter((p) => p.zoom >= caps.min && p.zoom <= caps.max)
                .filter((p, i, all) => all.findIndex((q) => q.zoom === p.zoom) === i)
                .map((p) => ({ id: `zoom:${p.zoom}`, label: p.label, zoom: p.zoom }));

            if (this.lenses.length < 2) this.lenses = [];
        },

        /** One tap = one lens, whichever mechanism this stream offers. */
        async selectLens(lens) {
            if (this.switching) return;

            if (lens.zoom != null) {
                this.applyZoom(lens.zoom);
                this.persistLens();

                return;
            }

            if (lens.id === this.activeLensId) return;

            // Optimistic, camera-app feel: the tapped pill flips NOW; the
            // frozen still + fade carry the sensor swap, and the black-pixel
            // check reverts to the previous lens if the new one is dead.
            const from = this.lensId ?? this.activeLensId;
            this.lensId = lens.id;
            this.activeLensId = lens.id;
            this.persistLens();
            // The onion skin and reference thumb stay — they ARE the point:
            // the guide now compares the new lens's view against the frame
            // being matched.
            await this.start({ switchFrom: from ?? null });
        },

        /** Physical lens change via the running stream — nothing restarts. */
        applyZoom(zoom) {
            const track = this.stream?.getVideoTracks?.()[0];
            if (!track) return;

            track.applyConstraints({ advanced: [{ zoom }] })
                .catch(() => track.applyConstraints({ zoom }).catch(() => {}));
            this.activeZoom = zoom;
            this.savedZoom = zoom;
        },

        persistLens() {
            try {
                localStorage.setItem(this.lensKey, JSON.stringify(
                    this.zoomMode
                        ? { device: this.lensId, zoom: this.activeZoom }
                        : { device: this.lensId },
                ));
            } catch (e) {}
        },

        noteLens(text) {
            this.lensNote = text;
            clearTimeout(this.lensNoteTimer);
            this.lensNoteTimer = setTimeout(() => { this.lensNote = ''; }, 3500);
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


        stopTracks(stream) {
            stream?.getTracks?.().forEach((t) => t.stop());
        },

        /**
         * Paint the current viewfinder into a still, shown over the <video>
         * while the sensor changes over. A stopped or not-yet-playing video
         * element renders BLACK, which is what a lens switch looked like.
         */
        freezeFrame() {
            const video = this.videoEl;
            if (!video?.videoWidth) return;

            try {
                const c = this.cropRect(video.videoWidth, video.videoHeight);
                const canvas = document.createElement('canvas');
                canvas.width = c.sw;
                canvas.height = c.sh;
                canvas.getContext('2d').drawImage(video, c.sx, c.sy, c.sw, c.sh, 0, 0, c.sw, c.sh);
                this.frozenSrc = canvas.toDataURL('image/jpeg', 0.7);
                this.switching = true;
            } catch (e) {
                // Tainted or not readable — better a brief black than a throw.
                this.frozenSrc = '';
                this.switching = true;
            }
        },

        stop() {
            if (this.stream) {
                this.stopTracks(this.stream);
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
            // A frozen still is not a live view — never shoot mid-swap.
            if (this.shutter || this.armingCapture || this.switching) return;
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
            const video = this.videoEl;
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
                // The shot carries its own destination: it may still be in
                // the queue long after the camera has moved on.
                Alpine.store('tlUploads').add({ blob, meta, collectionId: this.collectionId });
                // 0.92: the archive copy is encoded once, here — but 0.98 was
                // ~3MB a frame, which is a minute on jobsite signal and most
                // of why saving felt stuck. 0.92 is visually the same photo
                // at roughly half the bytes; the server derives the smaller
                // sequence copy from it.
            }, 'image/jpeg', 0.92);
        },

        destroy() {
            // Anything still waiting on getUserMedia now belongs to no one —
            // the gen bump makes it stop its own stream when it resolves.
            this.startGen++;
            clearTimeout(this.rotateTimer);
            clearTimeout(this.freezeTimer);

            // A viewfinder still ported to <body> would outlive the page.
            // RE-HOME it (not remove): wire:navigate snapshots the DOM for
            // back/forward right after teardown, and a snapshot missing the
            // <video> node would restore a camera card that can never start.
            if (this.vfHome) {
                document.body.style.overflow = '';
                this.setViewportCover(false);
                window.visualViewport?.removeEventListener('resize', this.onViewportResize);
                window.visualViewport?.removeEventListener('scroll', this.onViewportResize);
                const vf = this.$refs.viewfinder;
                if (vf) { vf.style.height = ''; vf.style.width = ''; }
                Alpine.mutateDom(() => {
                    if (vf && this.vfHome.parentNode) {
                        this.vfHome.parentNode.insertBefore(vf, this.vfHome);
                    } else {
                        vf?.remove();
                    }
                    this.vfHome.remove();
                });
                this.vfHome = null;
            }
            this.stop();
            clearInterval(this.guideTimer);
            window.removeEventListener('orientationchange', this.onRotate);
            if (this.landscapeMq) this.landscapeMq.removeEventListener('change', this.onOrient);
            document.removeEventListener('fullscreenchange', this.onFsChange);
        },
    }));
</script>
@endscript
