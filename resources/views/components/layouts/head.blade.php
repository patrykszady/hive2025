<head>
    {{-- Prevent browser hash jump - must run before any rendering --}}
    <script>
        if (window.location.hash) {
            window.__initialHash = window.location.hash;
            history.scrollRestoration = 'manual';
            window.scrollTo(0, 0);
        }
    </script>

    @if(env('APP_ENV') == 'production')
        <!-- Google tag (gtag.js) -->
        <script async src="https://www.googletagmanager.com/gtag/js?id={{env('GOOGLE_ANALYTICS_GTAG')}}"></script>
        <script>
            window.dataLayer = window.dataLayer || [];
                function gtag(){dataLayer.push(arguments);}
                gtag('js', new Date());

                gtag('config', '{{env('GOOGLE_ANALYTICS_GTAG')}}', {
                    'user_id': '{{auth()->guest() ? "GUEST" : auth()->user()->id}}'
                });
        </script>

        {{-- Microsoft Clarity --}}
        <script type="text/javascript">
            (function(c,l,a,r,i,t,y){
                    c[a]=c[a]||function(){(c[a].q=c[a].q||[]).push(arguments)};
                    t=l.createElement(r);t.async=1;t.src="https://www.clarity.ms/tag/"+i;
                    y=l.getElementsByTagName(r)[0];y.parentNode.insertBefore(t,y);
                    window.clarity("set", "userId", "{{auth()->guest() ? "GUEST" : auth()->user()->id}}");
                })(window, document, "clarity", "script", "hbp3mhwtnp");
        </script>
    @endif

    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ isset($title) ? $title . ' | ' . env('APP_NAME') : env('APP_NAME')}}</title>
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600&display=swap" rel="stylesheet" />

    <!-- Styles -->
    @vite(['resources/css/app.css'])
    <noscript>
        <style>
            [data-page-fade] { opacity: 1 !important; }
        </style>
    </noscript>
    <style>
        /* Hide Alpine elements until initialized */
        [x-cloak] {
            display: none !important;
        }
        /* Hide elements with x-show that should start hidden */
        [x-cloak\:hidden] {
            display: none !important;
        }
        /* Fullscreen planner pages: remove any grid/flex gaps */
        body.h-screen {
            gap: 0 !important;
            grid-gap: 0 !important;
            column-gap: 0 !important;
            row-gap: 0 !important;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            min-height: 100dvh;
        }

        /* Fullscreen planner pages: make main flex + zero padding so children can stretch */
        body.h-screen [data-flux-main] {
            display: flex;
            flex-direction: column;
            min-height: 0;
            flex: 1 1 auto;
            padding: 0 !important;
        }

        /* Mobile: keep hamburger overlay (don't reserve header height) */
        @media (max-width: 1023.98px) {
            [data-flux-header] {
                position: fixed !important;
                top: 0 !important;
                left: 0 !important;
                right: 0 !important;
                z-index: 60 !important;
                background: transparent !important;
                pointer-events: none !important;
            }

            /* Re-enable pointer events for interactive children */
            [data-flux-header] > * {
                pointer-events: auto !important;
            }

            /* When the sidebar is open on mobile, hide the header toggle overlay */
            body:has([data-flux-sidebar-on-mobile]:not([data-flux-sidebar-collapsed-mobile])) [data-flux-header] {
                display: none !important;
            }
        }
    </style>
    @stack('styles')

    <!-- Java Scripts -->
    {{-- ALPINE CORE INCLUDED WITH LIVEWIRE --}}
    <!-- Alpine Plugins -->
    <script defer src="https://cdn.jsdelivr.net/npm/@alpinejs/sort@3.x.x/dist/cdn.min.js"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/@alpinejs/mask@3.x.x/dist/cdn.min.js"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/@alpinejs/intersect@3.x.x/dist/cdn.min.js"></script>

    <!-- Plaid Link -->
    <script src="https://cdn.plaid.com/link/v2/stable/link-initialize.js"></script>

    @vite(['resources/js/app.js'])

    @fluxAppearance
</head>
