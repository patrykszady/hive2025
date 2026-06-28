@props(['class' => 'size-7'])

<span {{ $attributes->merge(['class' => 'hive-logo inline-flex shrink-0 '.$class]) }} aria-hidden="true">
    <svg class="hive-logo__svg size-full text-indigo-900 dark:text-indigo-300" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 500 500" fill="none">
        <polyline data-draw pathLength="1" points="377.03 255.61 436.16 288.39 436.16 357.47 363.78 399.26" stroke="currentColor" stroke-linecap="round" stroke-miterlimit="10" stroke-width="30"/>
        <polyline data-draw pathLength="1" points="361.84 99.62 436.16 142.53 436.16 143.56 436.16 221.47" stroke="currentColor" stroke-linecap="round" stroke-miterlimit="10" stroke-width="30"/>
        <polyline data-draw pathLength="1" points="179.66 75.68 250.01 35.06 361.84 99.62" stroke="currentColor" stroke-linecap="round" stroke-miterlimit="10" stroke-width="30"/>
        <polyline data-draw pathLength="1" points="122.97 218.72 122.97 323.34 145.93 336.6" stroke="currentColor" stroke-linecap="round" stroke-miterlimit="10" stroke-width="30"/>
        <path data-draw pathLength="1" d="M319.16,356.76c19.29-11.14,38.58-22.28,57.87-33.42v-24.55" stroke="currentColor" stroke-linecap="round" stroke-miterlimit="10" stroke-width="30"/>
        <path data-draw pathLength="1" d="M129.64,180.51l120.37,70.53c7.19-4.15,14.38-8.3,21.57-12.45" stroke="currentColor" stroke-linecap="round" stroke-miterlimit="10" stroke-width="30"/>
        <path data-draw pathLength="1" d="M179.51,424.24l70.5,40.7,68.7-39.98v-135.68s0,0,0,0h0c19.44-11.22,38.88-22.45,58.32-33.67v-78.95l-58.32-33.68-68.71-39.67-89.01,51.87" stroke="currentColor" stroke-linecap="round" stroke-miterlimit="10" stroke-width="30"/>
        <path data-draw pathLength="1" d="M271.54,316.51c-7.18,4.14-14.35,8.29-21.53,12.43L63.86,221.47v-78.94l67.63-39.24,1.07-.73.27.16,59.11,34.12,126.77,73.19" stroke="currentColor" stroke-linecap="round" stroke-miterlimit="10" stroke-width="30"/>
        <path data-draw pathLength="1" d="M63.86,283.06v74.41l68.7,39.85,58.59-33.82,58.85,33.19c7.22-4.17,14.44-8.34,21.66-12.5" stroke="currentColor" stroke-linecap="round" stroke-miterlimit="10" stroke-width="30"/>
    </svg>
</span>

@once
    <style>
        .hive-logo {
            animation: hive-bob 4.5s ease-in-out infinite;
        }
        .hive-logo__svg {
            transform: rotate(var(--hive-rot, 0deg));
            transform-origin: 50% 50%;
            transition: transform .12s linear, filter .3s ease;
            animation: hive-glow 3.4s ease-in-out infinite, hive-breathe 3.4s ease-in-out infinite;
            will-change: transform, filter, opacity;
        }
        .hive-logo__svg [data-draw] {
            stroke-dasharray: 1;
            stroke-dashoffset: 1;
            animation: hive-draw 1.4s ease forwards, hive-flow 6s linear 1.6s infinite;
        }
        .hive-logo__svg [data-draw]:nth-child(2) { animation-delay: 0s, 1.7s; }
        .hive-logo__svg [data-draw]:nth-child(3) { animation-delay: .08s, 1.8s; }
        .hive-logo__svg [data-draw]:nth-child(4) { animation-delay: .16s, 1.9s; }
        .hive-logo__svg [data-draw]:nth-child(5) { animation-delay: .24s, 2.0s; }
        .hive-logo__svg [data-draw]:nth-child(6) { animation-delay: .32s, 2.1s; }
        .hive-logo__svg [data-draw]:nth-child(7) { animation-delay: .40s, 2.2s; }
        .hive-logo__svg [data-draw]:nth-child(8) { animation-delay: .48s, 2.3s; }
        .hive-logo__svg [data-draw]:nth-child(9) { animation-delay: .56s, 2.4s; }
        .hive-logo:hover .hive-logo__svg {
            animation: hive-spin 1.1s cubic-bezier(.4, 0, .2, 1), hive-glow 1.2s ease-in-out infinite;
            filter: drop-shadow(0 0 6px rgba(79, 70, 229, .55));
        }
        @keyframes hive-draw { to { stroke-dashoffset: 0; } }
        @keyframes hive-flow {
            0%, 100% { stroke-dashoffset: 0; }
            45% { stroke-dashoffset: .04; }
            55% { stroke-dashoffset: -.04; }
        }
        @keyframes hive-spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
        @keyframes hive-bob {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-2px); }
        }
        @keyframes hive-breathe {
            0%, 100% { opacity: .9; }
            50% { opacity: 1; }
        }
        @keyframes hive-glow {
            0%, 100% { filter: drop-shadow(0 0 0 rgba(79, 70, 229, 0)); }
            50% { filter: drop-shadow(0 0 5px rgba(79, 70, 229, .45)); }
        }
        @media (prefers-reduced-motion: reduce) {
            .hive-logo, .hive-logo__svg, .hive-logo__svg [data-draw] { animation: none !important; }
            .hive-logo__svg { transform: none !important; filter: none !important; opacity: 1 !important; }
        }
    </style>

    <script>
        (function () {
            if (window.__hiveLogoScroll) { return; }
            window.__hiveLogoScroll = true;

            var reduce = window.matchMedia('(prefers-reduced-motion: reduce)');
            var ticking = false;

            function update() {
                ticking = false;
                if (reduce.matches) { return; }
                var max = document.documentElement.scrollHeight - window.innerHeight;
                var progress = max > 0 ? Math.min(window.scrollY / max, 1) : 0;
                document.documentElement.style.setProperty('--hive-rot', (progress * 360).toFixed(2) + 'deg');
            }

            function onScroll() {
                if (!ticking) {
                    ticking = true;
                    window.requestAnimationFrame(update);
                }
            }

            window.addEventListener('scroll', onScroll, { passive: true });
            window.addEventListener('resize', onScroll, { passive: true });
            document.addEventListener('livewire:navigated', update);
            update();
        })();
    </script>
@endonce
