<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    {{-- config(), never env() — see the note in head.blade.php. --}}
    <title>{{ isset($title) ? $title.' | '.config('app.name') : config('app.name') }}</title>

    @php
        // Browsershot loads the HTML from a local file (file:///tmp/.../index.html).
        // During PDF generation, we should avoid *any* HTTP requests back to the Laravel app
        // (php artisan serve is single-threaded and can deadlock).
        //
        // So we inline the compiled CSS from public/build when available.
        $inlineCss = null;
        $manifestPath = public_path('build/manifest.json');

        if (is_file($manifestPath)) {
            $manifest = json_decode((string) file_get_contents($manifestPath), true);
            $entry = $manifest['resources/css/app.css'] ?? null;
            $cssFile = is_array($entry) ? ($entry['file'] ?? null) : null;

            if (is_string($cssFile) && $cssFile !== '') {
                $cssPath = public_path('build/' . ltrim($cssFile, '/'));
                if (is_file($cssPath)) {
                    $inlineCss = (string) file_get_contents($cssPath);
                }
            }
        }
    @endphp

    @if(is_string($inlineCss) && $inlineCss !== '')
        <style>{!! $inlineCss !!}</style>
    @endif

    <noscript>
        <style>
            [data-page-fade] { opacity: 1 !important; }
        </style>
    </noscript>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Dancing+Script:wght@400;700&display=swap" rel="stylesheet">

    <style>
        [x-cloak], [x-cloak\:hidden] {
            display: none !important;
        }

        .contract-body ul { list-style-type: disc; padding-left: 2rem; margin: 0.5rem 0; }
        .contract-body ol { list-style-type: decimal; padding-left: 2rem; margin: 0.5rem 0; }
        .contract-body li { margin: 0.25rem 0; }
    </style>
</head>
