{{-- The live camera, rendered INSIDE the collection card it shoots into —
     one place to tap, no separate card and no "shooting into" guesswork.
     Expects $collection (the card's own collection, which is the open one).

     The capture block is wire:ignore (Livewire must never morph a live
     <video>), so the overlay updates from JS: after a successful upload the
     just-captured canvas becomes the new overlay — no round trip, never
     stale. --}}
{{-- x-init on a plain div evaluates in the CARD's scope, so opening the
     camera also opens the card. Needed because the card is morphed, not
     replaced: Alpine keeps whatever `open` it had, and a collapsed
     timelapse would otherwise swallow the viewfinder. --}}
<div class="space-y-3 border-b border-zinc-200 pb-4 dark:border-zinc-700"
    x-init="typeof open !== 'undefined' && (open = true);
            $dispatch('images-card-opened', { key: 'collection-{{ $collection->id }}' })">
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
                        {{-- Crosses grow with the screen — card-sized marks
                             vanish on a fullscreen landscape view. --}}
                        <svg class="absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 text-white/60"
                            x-bind:class="fullscreen ? 'size-16' : 'size-10'"
                            viewBox="0 0 40 40" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M20 6v9M20 25v9M6 20h9M25 20h9" />
                        </svg>
                        <svg x-show="!lost" class="absolute transition-[left,top] duration-150"
                            x-bind:class="fullscreen ? 'size-16' : 'size-10'"
                            viewBox="0 0 40 40" fill="none" stroke="currentColor" stroke-width="3"
                            x-bind:style="`left: calc(50% + ${bubbleX}px); top: calc(50% + ${bubbleY}px); transform: translate(-50%, -50%); color: ${guideColor}`">
                            <path d="M20 6v9M20 25v9M6 20h9M25 20h9" />
                            <circle cx="20" cy="20" r="3.5" fill="currentColor" stroke="none" />
                        </svg>
                        <div x-show="lost" class="absolute left-1/2 top-1/2 size-4 -translate-x-1/2 -translate-y-1/2 rounded-full bg-red-500 animate-pulse"></div>
                    </div>

                    {{-- Fullscreen loses the card's colored ring at the bezel
                         where thumbs cover it — this inset frame is the same
                         signal where the eye actually is: green = lined up,
                         red = can't find the view. --}}
                    <div x-show="fullscreen && onionSrc" x-cloak
                        class="pointer-events-none absolute inset-0 z-[7] border-4 transition-colors duration-300"
                        x-bind:class="aligned ? 'border-green-500' : (lost ? 'border-red-500/80' : 'border-white/15')"></div>

                    {{-- The card shows the ghost toggle + opacity slider below
                         the viewfinder; fullscreen hides that row, which left
                         landscape with no way to adjust the onion skin. Same
                         controls, top-center band. --}}
                    <div x-show="fullscreen && onionSrc" x-cloak x-on:click.stop
                        class="absolute left-1/2 z-20 flex -translate-x-1/2 items-center gap-2 rounded-full bg-black/60 px-3 py-1.5"
                        x-bind:class="'top-[max(0.5rem,env(safe-area-inset-top))]'">
                        <button type="button" x-on:click="onionOn = !onionOn"
                            class="cursor-pointer text-xs font-semibold"
                            x-bind:class="onionOn ? 'text-white' : 'text-white/50'">
                            Ghost
                        </button>
                        <input type="range" min="10" max="90" x-model="onionOpacity" x-show="onionOn"
                            class="w-24 accent-indigo-500" />
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
                <form wire:submit="uploadFile" x-show="up" x-cloak class="space-y-2 pt-2">
                    <div class="flex items-end gap-2">
                        <div class="flex-1">
                            <flux:input type="file" wire:model="file" accept="image/jpeg,image/png,image/heic,image/heif,.heic,.heif" />
                        </div>
                        <flux:button type="submit" wire:loading.attr="disabled" wire:target="file, uploadFile">Add</flux:button>
                    </div>
                    @if ($this->collection?->isTimelapse())
                    @endif
                </form>
            </div>
</div>
