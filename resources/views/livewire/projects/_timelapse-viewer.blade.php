{{-- The gs.construction timelapse viewer, ported: same Alpine state machine
     (preload-all → autoplay → pause on hover/drag), same blur-up first frame,
     same Before/After slider band. Adapted for the studio card: starts on
     init instead of on scroll-into-view, aspect box instead of the marketing
     page's fixed gallery heights, and no project-title chip (we're already on
     the project).

     Expects: $frameUrls (array of frame URLs in play order). --}}
<section
    x-data="{
        frames: @js($frameUrls),
        position: 1,
        timer: null,
        hovering: false,
        dragging: false,
        intervalMs: 1200,
        allLoaded: false,
        loadedCount: 0,
        firstFrameLoaded: false,
        showBlur: false,
        init() {
            if (this.frames.length) {
                const img = new Image();
                img.src = this.frames[0];
                if (img.complete && img.naturalWidth > 0) {
                    this.firstFrameLoaded = true;
                } else {
                    this.showBlur = true;
                    img.onload = () => { this.firstFrameLoaded = true; };
                }
            }
            this.preloadAll();
        },
        // Fractional position drives a CROSS-FADE: the frame below the
        // position at full opacity, the next frame above it fading in by the
        // fraction — so scrubbing blends continuously instead of hard-cutting.
        baseSrc() {
            if (!this.frames.length) return null;
            const i = (Math.floor(this.position) - 1 + this.frames.length) % this.frames.length;
            return this.frames[i];
        },
        overSrc() {
            if (this.frames.length < 2) return null;
            const i = (Math.ceil(this.position) - 1 + this.frames.length) % this.frames.length;
            return this.frames[i];
        },
        overOpacity() {
            return this.position - Math.floor(this.position);
        },
        tick() {
            if (!this.frames.length) return;
            // Playback rides the same cross-fade: animate the fractional
            // position to the next frame fast (250ms), then hold.
            const from = this.position;
            const target = from + 1;
            const started = performance.now();
            const FADE = 250;
            const step = (now) => {
                // The interval handle doubles as the play flag — stop() clears
                // it, which halts a mid-flight fade too.
                if (!this.timer) return;
                const t = Math.min(1, (now - started) / FADE);
                this.position = from + t;
                if (t < 1) { requestAnimationFrame(step); return; }
                // Wrap N+1 back onto frame 1 once the fade completes.
                this.position = target > this.frames.length ? 1 : target;
            };
            requestAnimationFrame(step);
        },
        start() {
            if (this.hovering || this.dragging || this.timer || this.frames.length < 2 || !this.allLoaded) return;
            this.timer = setInterval(() => this.tick(), this.intervalMs);
        },
        stop() {
            if (!this.timer) return;
            clearInterval(this.timer);
            this.timer = null;
        },
        preloadAll() {
            if (this.allLoaded) return;
            const total = this.frames.length;
            if (total === 0) { this.allLoaded = true; return; }
            this.frames.forEach(src => {
                const img = new Image();
                const done = () => {
                    this.loadedCount++;
                    if (this.loadedCount >= total) {
                        this.allLoaded = true;
                        this.start();
                    }
                };
                img.onload = done;
                img.onerror = done;
                img.src = src;
            });
        },
        beginHover() { this.hovering = true; this.stop(); },
        endHover() { this.hovering = false; this.start(); },
        beginDrag() { this.dragging = true; this.stop(); },
        endDrag() { this.dragging = false; this.start(); },
        destroy() { this.stop(); },
    }"
    @pointerup.window="endDrag()"
    @pointercancel.window="endDrag()"
    class="relative w-full"
>
    <div class="relative overflow-hidden rounded-lg bg-zinc-950 aspect-[4/3]">
        {{-- Blur placeholder while the first frame loads --}}
        <img
            x-cloak
            x-show="showBlur && !firstFrameLoaded"
            src="{{ $frameUrls[0] ?? '' }}"
            alt=""
            aria-hidden="true"
            class="absolute inset-0 h-full w-full object-cover blur-xl scale-110 transition-opacity duration-300"
        />
        {{-- Timelapse frames: base layer + cross-fading overlay --}}
        <img
            x-show="frames.length"
            :src="baseSrc()"
            alt="Project construction timelapse"
            class="absolute inset-0 h-full w-full object-contain"
        />
        <img
            x-show="frames.length > 1"
            :src="overSrc()"
            :style="`opacity: ${overOpacity()}`"
            aria-hidden="true"
            class="absolute inset-0 h-full w-full object-contain"
        />

        {{-- Before/After scrub band --}}
        <div class="absolute inset-x-0 bottom-4 z-10">
            <div class="mx-auto w-full max-w-md px-6">
                <div
                    class="rounded-xl p-4 text-white backdrop-blur-sm shadow-lg ring-2 ring-white/50 **:text-white **:fill-white **:stroke-white"
                    @mouseenter="beginHover()"
                    @mouseleave="endHover()"
                    @focusin="beginHover()"
                    @focusout="endHover()"
                    @pointerdown.capture="beginDrag()"
                >
                    <flux:slider min="1" max="{{ count($frameUrls) }}" step="0.01" x-model.number="position">
                        @for ($i = 1; $i <= count($frameUrls); $i++)
                            <flux:slider.tick value="{{ $i }}" class="!text-white drop-shadow-sm font-medium">
                                @if ($i === 1)
                                    Before
                                @elseif ($i === count($frameUrls))
                                    After
                                @else
                                    <span class="sr-only">Frame {{ $i }}</span>
                                @endif
                            </flux:slider.tick>
                        @endfor
                    </flux:slider>
                </div>
            </div>
        </div>
    </div>
</section>
