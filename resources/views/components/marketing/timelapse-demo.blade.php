{{-- The timelapse, reduced to its idea: with every step forward, more of the
     house is simply there — whole lines appear frame by frame, the way a real
     timelapse shows more work per frame, never lines growing. Progress runs
     0 → 100, holds, then backtracks to zero and repeats. A rAF clock drives
     it; the slider underneath scrubs it (auto-play pauses while the user
     drags, resumes shortly after). Strokes inherit currentColor so the site
     palette (zinc structure, indigo accent) carries both themes. An
     illustration, never a real client's home. --}}
@props(['class' => ''])

@php
    // One threshold per stroke: visible once progress reaches it. The main
    // house is done by 62; the addition is later-phase work.
    $strokes = [
        // [path, tailwind text color, width, appears at]
        ['M70 320 H570', 'text-zinc-300 dark:text-zinc-600', 3, 4],                        // ground
        ['M160 320 V180', 'text-zinc-700 dark:text-zinc-300', 5, 13],                      // wall L
        ['M400 320 V180', 'text-zinc-700 dark:text-zinc-300', 5, 21],                      // wall R
        ['M134 186 L280 96 L446 186', 'text-indigo-600 dark:text-indigo-400', 5, 32],      // roof
        ['M255 320 V252 H305 V320', 'text-indigo-600 dark:text-indigo-400', 5, 43],        // door
        ['M185 212 h58 v46 h-58 Z M185 235 h58', 'text-zinc-700 dark:text-zinc-300', 4, 53], // window L
        ['M317 212 h58 v46 h-58 Z M317 235 h58', 'text-zinc-700 dark:text-zinc-300', 4, 62], // window R
        ['M396 210 L549 236', 'text-zinc-700 dark:text-zinc-300', 5, 73],                  // addition roof
        ['M545 320 V236', 'text-zinc-700 dark:text-zinc-300', 5, 80],                      // addition wall
        ['M436 320 V262 H508 V320', 'text-zinc-700 dark:text-zinc-300', 4, 89],            // garage door
        ['M436 291 H508', 'text-zinc-700 dark:text-zinc-300', 4, 95],                      // garage door panel
    ];
@endphp

<div
    {{ $attributes->merge(['class' => 'p-6 rounded-2xl bg-gray-50 dark:bg-zinc-900 ring-1 ring-gray-200 dark:ring-zinc-800 '.$class]) }}
    x-data="{
        c: 0,            // clock over one 228-unit cycle: 0–100 up, hold, 100–0 back, hold
        playing: true,
        resume: null,
        get p() {
            const r = this.c % 228;
            if (r <= 100) return r;
            if (r <= 114) return 100;
            if (r <= 214) return 214 - r;
            return 0;
        },
        set p(v) { this.c = Number(v) }, // scrubbing re-enters the forward leg
        get day() { return 1 + Math.round(this.p * 0.71) },
        scrub() {
            this.playing = false;
            clearTimeout(this.resume);
            this.resume = setTimeout(() => this.playing = true, 2500);
        },
    }"
    x-init="
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) { c = 100; playing = false }
        let last = null;
        const step = (ts) => {
            if (last !== null && playing) { c = (c + (ts - last) * 0.0145) % 228 }
            last = ts;
            if ($el.isConnected) requestAnimationFrame(step);
        };
        requestAnimationFrame(step);
    "
>
    <svg viewBox="0 0 640 400" class="w-full h-auto" role="img"
        aria-label="{{ __('Line drawing of a house assembling itself, stroke by stroke') }}">
        <title>{{ __('Project timelapse') }}</title>
        @foreach ($strokes as $s)
            <path d="{{ $s[0] }}"
                class="{{ $s[1] }}"
                stroke="currentColor" stroke-width="{{ $s[2] }}" fill="none"
                stroke-linecap="round" stroke-linejoin="round"
                opacity="1" :opacity="p >= {{ $s[3] }} ? 1 : 0" />
        @endforeach
    </svg>

    {{-- the player: auto-advancing, grab-to-scrub --}}
    <div class="flex items-center gap-4 mt-4">
        <input type="range" min="0" max="100" step="0.1"
            :value="p"
            @input="p = $event.target.value; scrub()"
            aria-label="{{ __('Scrub through the timelapse') }}"
            class="w-full accent-indigo-600 dark:accent-indigo-400 cursor-pointer rounded-full focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500/60 focus-visible:ring-offset-2 dark:focus-visible:ring-offset-zinc-900" />
        <span class="text-xs font-semibold text-gray-500 dark:text-gray-400 whitespace-nowrap tabular-nums w-14 text-right"
            x-text="'{{ __('Day') }} ' + day"></span>
    </div>
</div>
