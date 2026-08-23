{{--
    Shared photo lightbox.

    Teleported to <body> so no card or modal can clip it, blurred backdrop, dot
    indicators, arrow keys, swipe, click-outside and Escape to close, page
    scroll locked while open.

    Listens on the WINDOW for `open-lightbox`, so any component on the page can
    open it without knowing where it lives:

        $dispatch('open-lightbox', { frames: [...], index: 0 })

    Each frame is { id, url, original, label } — `original` powers "Show
    original", `label` is the caption. Extracted from the project photo grid so
    the task modal and the project page share one implementation rather than
    drifting apart.
--}}
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
            // showModal() promotes this to the browser's TOP LAYER. Flux modals
            // are native <dialog>s opened the same way, and the top layer is a
            // stack painted in promotion order — so a plain z-index, however
            // high, always lost to an already-open modal. Opening second is
            // what puts the lightbox in front.
            this.$nextTick(() => {
                const dlg = this.$refs.dlg;
                if (dlg && !dlg.open) dlg.showModal();
            });
            document.body.style.overflow = 'hidden';
        },
        close() {
            this.open = false;
            const dlg = this.$refs.dlg;
            if (dlg && dlg.open) dlg.close();
            // Only release the page scroll if no other modal still wants it
            // locked — closing the lightbox must not let the page behind an
            // open task modal start scrolling.
            if (!document.querySelector('dialog[open]')) {
                document.body.style.overflow = '';
            }
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
        x-on:keydown.arrow-right.window="open && next()"
        x-on:keydown.arrow-left.window="open && prev()"
    >
        <template x-teleport="body">
            {{-- A native <dialog>, not a div: see show() above. The default
                 UA styling (auto width/height, border, padding, centring) is
                 reset so it fills the viewport exactly as the old fixed
                 overlay did, and the dim moves to ::backdrop, which the top
                 layer renders for us. --}}
            {{-- No x-cloak: a <dialog> without [open] is display:none by UA
                 default, so it is already hidden before showModal(), and
                 x-cloak's `display:none !important` would fight the top-layer
                 render if it ever lingered. --}}
            <dialog x-ref="dlg"
                x-on:cancel.prevent="close()"
                x-on:close="open = false"
                class="fixed inset-0 z-50 m-0 h-full max-h-none w-full max-w-none items-center justify-center overscroll-none border-0 bg-black/60 p-0 backdrop-blur-2xl backdrop:bg-black/60 backdrop:backdrop-blur-2xl open:flex"
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
                        <a x-show="current.original" :href="current.original" target="_blank" rel="noopener" x-on:click.stop
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
            </dialog>
        </template>
    </div>
