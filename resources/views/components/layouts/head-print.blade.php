<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>{{ isset($title) ? $title : config('app.name') }}</title>

    <!-- Inline CSS for PDF generation (Browsershot can't access localhost URLs) -->
    @if(file_exists(public_path('build/manifest.json')))
        @php
            $manifest = json_decode(file_get_contents(public_path('build/manifest.json')), true);
            $cssFile = $manifest['resources/css/app.css']['file'] ?? null;
            $cssPath = $cssFile ? public_path('build/' . $cssFile) : null;
        @endphp
        @if($cssPath && file_exists($cssPath))
            <style>{!! file_get_contents($cssPath) !!}</style>
        @endif
    @endif

    <style>
        /* Fallback font stack for PDF generation */
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; }
        /* Basic print styles */
        @media print {
            body { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
        }
    </style>
</head>
