<head>
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
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    {{-- <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css"> --}}

    {{-- @lagoonStyles --}}
    {{-- <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/choices.js/public/assets/styles/choices.min.css" /> --}}

    <!-- Scripts -->
    {{-- <script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.0/main.min.js"></script> --}}
    {{-- <script src="https://cdn.jsdelivr.net/npm/choices.js/public/assets/scripts/choices.min.js"></script> --}}
    {{-- <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script> --}}

    {{-- ALPINE CORE INCLUDED WITH LIVEWIRE --}}
    <!-- Alpine Plugins -->
    <script defer src="https://unpkg.com/@alpinejs/ui@3.14.1-beta.0/dist/cdn.min.js"></script>
    {{-- <script defer src="https://unpkg.com/@alpinejs/focus@3.14.1/dist/cdn.min.js"></script> --}}
    {{-- <script defer src="https://cdn.jsdelivr.net/npm/@alpinejs/collapse@3.14.1/dist/cdn.min.js"></script>
    <script defer src="https://unpkg.com/@nextapps-be/livewire-sortablejs@0.4.0/dist/livewire-sortable.js"></script> --}}
    <script defer src="https://cdn.jsdelivr.net/npm/@alpinejs/sort@3.x.x/dist/cdn.min.js"></script>

    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.5.1/dist/chart.min.js"></script>
    {{-- <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script> --}}
    @fluxStyles
</head>
