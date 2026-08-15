{{-- Inner content of the manual-align modal. Own file for the same reason as
     _text-images-modal: Blaze miscompiles an @if wrapping markup inside a
     component slot, and the include boundary keeps compile units intact.

     The math contract with applyManualAlignment(): the anchor is rendered
     object-contain in a stage whose aspect ratio IS the anchor's, so one
     anchor pixel = f css px where f = stageWidth / anchorNaturalWidth. The
     frame is laid out at (targetW / refW * 100)% width — the same f — and
     panned/zoomed with transform-origin 0 0, so cssOut = s * cssIn + t.
     Dividing t by f converts it to image pixels: outputPx = s * inputPx + t/f,
     exactly the matrix --apply builds. --}}
@php($aligner = $this->alignerFrame)
@php($alignFrame = $aligner['frame'])
<div
    {{-- Keyed by frame: reopening for a DIFFERENT frame must replace this
         element, not morph it — a morph would keep the old Alpine state
         (pan/zoom AND the frame id save() captured). --}}
    wire:key="aligner-{{ $alignFrame->id }}"
    {{-- "Use original" wipes the stored fit server-side; the editor drops
         back to 1:1 so it shows the frame that now exists, ready to adjust
         again. --}}
    x-on:alignment-cleared.window="saved = null; seeded = true; base = 1; reset()"
    class="flex flex-col"
    x-data="{
        s: 1, tx: 0, ty: 0, r: 0, op: 0.55,
        refW: 0, refH: 0, targetW: 0, targetH: 0,
        drag: null,
        // Where the sequence currently has this frame (null = never aligned).
        saved: @js($aligner['transform']),
        seeded: false,
        // The zoom the frame arrived with. The slider is centred on it, so
        // the middle is always 'as the timelapse has it' and there is as much
        // room to push in further as to pull back.
        base: 1,
        ready() { return this.refW > 0 && this.targetW > 0 },
        /** Open on the frame AS THE TIMELAPSE SHOWS IT, not at 1:1.
         *  The stored pan is in preview-image pixels; css = px * f. Runs once,
         *  after both images report their natural size. */
        seed() {
            if (this.seeded || ! this.ready() || ! this.saved) return;
            this.seeded = true;
            const f = this.$refs.stage.clientWidth / this.refW;
            this.s = this.saved.scale ?? 1;
            this.r = this.saved.rotation ?? 0;
            this.tx = (this.saved.tx ?? 0) * f;
            this.ty = (this.saved.ty ?? 0) * f;
            this.base = this.s;
        },
        /** Slider position: 0 is the frame's current zoom, +-1 is 4x either way. */
        zoomPos() { return Math.log(this.s / this.base) / Math.log(4) },
        zoomFromPos(p) { this.zoomTo(Math.min(8, Math.max(0.2, this.base * Math.pow(4, p)))) },
        refLoaded(el) {
            if (! el.naturalWidth) return;
            this.refW = el.naturalWidth;
            this.refH = el.naturalHeight;
            this.$nextTick(() => this.seed());
        },
        stageStyle() {
            return this.refW ? `aspect-ratio: ${this.refW} / ${this.refH}` : 'aspect-ratio: 4 / 3';
        },
        targetStyle() {
            const w = this.ready() ? (this.targetW / this.refW * 100) : 100;
            // Zoom and turn pivot about the image CENTRE (turning about a
            // corner swings the whole frame out of view); the pan is applied
            // on top. apply_mode composes the server matrix the same way.
            return `width:${w}%; transform: translate(${this.tx}px, ${this.ty}px) scale(${this.s}) rotate(${this.r}deg);`
                + ` transform-origin: 50% 50%; opacity:${this.op}`;
        },
        /** Image centre in stage coordinates, before any transform. */
        centre() {
            const el = this.$refs.target;
            return el ? { x: el.offsetWidth / 2, y: el.offsetHeight / 2 } : { x: 0, y: 0 };
        },
        turn(d) { this.r = Math.min(45, Math.max(-45, +(this.r + d).toFixed(2))) },
        zoomAt(px, py, k) {
            // Range wide enough for a real re-frame: matching a close anchor
            // from a wide shot needs 2x+ (frame 1 of a kitchen sequence sat
            // at 2.32), and the slider must be able to REACH the fit a frame
            // already has or it snaps backwards the moment it is touched.
            const ns = Math.min(8, Math.max(0.2, this.s * k));
            const kk = ns / this.s;
            if (kk === 1) return;
            // Keep the point under the cursor still. With a centre pivot the
            // correction is t += (1 - k)(cursor - centre - t) — independent
            // of the current turn, so zooming never fights the rotation.
            const c = this.centre();
            this.tx += (1 - kk) * (px - c.x - this.tx);
            this.ty += (1 - kk) * (py - c.y - this.ty);
            this.s = ns;
        },
        wheel(e) {
            const r = this.$refs.stage.getBoundingClientRect();
            this.zoomAt(e.clientX - r.left, e.clientY - r.top, e.deltaY < 0 ? 1.04 : 1 / 1.04);
        },
        zoomTo(v) {
            const r = this.$refs.stage.getBoundingClientRect();
            this.zoomAt(r.width / 2, r.height / 2, v / this.s);
        },
        nudge(dx, dy) { this.tx += dx; this.ty += dy },
        /** Back to the frame as shot (1:1), not to the stored fit. */
        reset() { this.s = 1; this.tx = 0; this.ty = 0; this.r = 0 },
        save() {
            if (! this.ready()) return;
            const f = this.$refs.stage.clientWidth / this.refW;
            this.$wire.applyManualAlignment({{ $alignFrame->id }}, this.s, this.tx / f, this.ty / f, this.r);
        },
        /** Make THIS frame the canvas, exactly as composed right now. Reset
         *  first for the plain original; adjust first to define a new look. */
        anchor() {
            if (! this.ready()) return;
            const f = this.$refs.stage.clientWidth / this.refW;
            this.$wire.setAlignmentAnchor({{ $alignFrame->id }}, this.s, this.tx / f, this.ty / f, this.r);
        },
    }"
>
    <div class="px-6 pt-6 pb-4">
        <flux:heading size="lg">Align frame</flux:heading>
        <flux:text class="mt-1">
            Your shot over the anchor frame — drag to move, scroll or use the slider to zoom,
            arrow keys to nudge (Shift = 10px), and the Turn slider to level the horizon.
            It opens where the timelapse currently has this frame, so a turn pulls in the
            overflow around the edges instead of cutting corners. <strong>Use as anchor</strong>
            makes this frame — exactly as composed here — the one every other frame matches;
            hit <strong>Reset to original</strong> first to anchor on the untouched shot.
            The original pixels are never modified — saving only pans, zooms, turns and crops.
        </flux:text>
    </div>

    <div class="px-6">
        {{-- The aspect ratio is an Alpine binding, not a hand-set style: a
             Livewire morph restores server attributes, and only bindings are
             re-evaluated afterwards — an imperative style would silently
             revert to the 4/3 placeholder and skew the saved transform.
             Drag ownership: one pointer (left button) owns the pan; a second
             finger or another button must not steal the baseline mid-drag. --}}
        <div
            x-ref="stage"
            tabindex="0"
            class="relative w-full overflow-hidden rounded-lg bg-black select-none touch-none cursor-move focus:outline-none focus:ring-2 focus:ring-indigo-500"
            x-bind:style="stageStyle()"
            x-on:pointerdown="if ($event.button === 0 && ! drag) { drag = { id: $event.pointerId, x: $event.clientX, y: $event.clientY, tx: tx, ty: ty }; $refs.stage.focus(); $refs.stage.setPointerCapture($event.pointerId) }"
            x-on:pointermove="if (drag && $event.pointerId === drag.id) { tx = drag.tx + ($event.clientX - drag.x); ty = drag.ty + ($event.clientY - drag.y) }"
            x-on:pointerup="if (drag && $event.pointerId === drag.id) drag = null"
            x-on:pointercancel="if (drag && $event.pointerId === drag.id) drag = null"
            x-on:wheel.prevent="wheel($event)"
            x-on:keydown.arrow-left.prevent="nudge($event.shiftKey ? -10 : -1, 0)"
            x-on:keydown.arrow-right.prevent="nudge($event.shiftKey ? 10 : 1, 0)"
            x-on:keydown.arrow-up.prevent="nudge(0, $event.shiftKey ? -10 : -1)"
            x-on:keydown.arrow-down.prevent="nudge(0, $event.shiftKey ? 10 : 1)"
        >
            {{-- The anchor: the canvas being aligned onto. --}}
            <img
                x-ref="ref"
                src="{{ $aligner['refUrl'] }}"
                alt="Anchor frame"
                draggable="false"
                class="pointer-events-none absolute inset-0 h-full w-full object-contain"
                x-init="if ($el.complete) refLoaded($el)"
                x-on:load="refLoaded($el)"
            />
            {{-- The frame being aligned — raw pixels, human-transformed. --}}
            <img
                src="{{ $aligner['targetUrl'] }}"
                alt="Frame being aligned"
                draggable="false"
                class="pointer-events-none absolute left-0 top-0 max-w-none"
                x-bind:style="targetStyle()"
                x-ref="target"
                x-init="if ($el.complete && $el.naturalWidth) { targetW = $el.naturalWidth; targetH = $el.naturalHeight; $nextTick(() => seed()) }"
                x-on:load="targetW = $el.naturalWidth; targetH = $el.naturalHeight; $nextTick(() => seed())"
            />
            <div x-show="! ready()" class="absolute inset-0 grid place-items-center text-sm text-white/70">
                Loading frames…
            </div>
        </div>
    </div>

    <div class="flex flex-wrap items-center gap-x-6 gap-y-3 px-6 py-4">
        <label class="flex items-center gap-2 text-sm text-zinc-600 dark:text-zinc-300">
            Zoom
            <input type="range" min="-1" max="1" step="0.005" class="w-36 accent-indigo-600"
                x-bind:value="zoomPos()" x-on:input="zoomFromPos(parseFloat($event.target.value))" />
            <span class="w-10 tabular-nums" x-text="Math.round(s * 100) + '%'"></span>
        </label>
        <label class="flex items-center gap-2 text-sm text-zinc-600 dark:text-zinc-300">
            Turn
            <input type="range" min="-15" max="15" step="0.1" class="w-36 accent-indigo-600"
                x-model.number="r" />
            <span class="w-12 tabular-nums" x-text="(r > 0 ? '+' : '') + r.toFixed(1) + '°'"></span>
            <flux:button size="xs" variant="ghost" x-on:click.stop="turn(-0.5)" title="Turn left">↺</flux:button>
            <flux:button size="xs" variant="ghost" x-on:click.stop="turn(0.5)" title="Turn right">↻</flux:button>
        </label>
        <label class="flex items-center gap-2 text-sm text-zinc-600 dark:text-zinc-300">
            Onion
            <input type="range" min="0.15" max="0.9" step="0.05" class="w-28 accent-indigo-600"
                x-model.number="op" />
        </label>
        <flux:button size="xs" variant="ghost" x-on:click="reset()"
            title="Back to this frame's untouched original">Reset to original</flux:button>

        <div class="ms-auto flex items-center gap-2">
            <flux:button
                size="sm" variant="ghost" icon="viewfinder-circle"
                x-on:click="if (confirm('Make this frame — exactly as composed here — the timelapse anchor? Every other frame is re-processed onto it.')) anchor()"
                x-bind:disabled="! ready()"
                wire:loading.attr="disabled"
                wire:target="setAlignmentAnchor"
            >
                <span wire:loading.remove wire:target="setAlignmentAnchor">Use as anchor</span>
                <span wire:loading wire:target="setAlignmentAnchor">Re-processing…</span>
            </flux:button>
            @if ($alignFrame->aligned_path)
                <flux:button
                    size="sm" variant="ghost"
                    wire:click="clearAlignment({{ $alignFrame->id }})"
                    wire:confirm="Remove the aligned copy and show this frame exactly as shot?"
                    wire:loading.attr="disabled"
                >
                    Use original
                </flux:button>
            @endif
            <flux:modal.close>
                <flux:button size="sm" variant="ghost">Cancel</flux:button>
            </flux:modal.close>
            <flux:button
                size="sm" variant="primary" color="indigo"
                x-on:click="save()"
                x-bind:disabled="! ready()"
                wire:loading.attr="disabled"
                wire:target="applyManualAlignment"
            >
                <span wire:loading.remove wire:target="applyManualAlignment">Save alignment</span>
                <span wire:loading wire:target="applyManualAlignment">Aligning…</span>
            </flux:button>
        </div>
    </div>
</div>
