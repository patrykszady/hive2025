<?php

/**
 * Guards against the two things that took production down on 2026-08-15.
 * Both were invisible locally and only appeared once the deploy cached
 * things, which is exactly why they need tests rather than vigilance.
 */

it('never calls env() in a blade view', function () {
    // Once `php artisan config:cache` has run — any deploy may do it —
    // Laravel stops loading .env and every env() call OUTSIDE config/
    // returns null. env('APP_NAME') in the <title> tag is what blanked the
    // app name out of every browser tab. config() reads the cached value
    // and works either way.
    $offenders = [];

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(resource_path('views'), RecursiveDirectoryIterator::SKIP_DOTS)
    );

    foreach ($files as $file) {
        if (! str_ends_with($file->getFilename(), '.blade.php')) {
            continue;
        }

        $body = file_get_contents($file->getPathname());

        // PHP env() only — CSS env(safe-area-inset-*) is a different beast
        // and is everywhere in the camera UI.
        preg_match_all('/(?<![\w-])env\s*\(\s*[\'"]([A-Z0-9_]+)[\'"]/', $body, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $offenders[] = str_replace(base_path().'/', '', $file->getPathname()).": env('{$match[1]}')";
        }
    }

    expect($offenders)->toBe([], "Use config() instead of env() in views:\n".implode("\n", $offenders));
});

it('keeps the app name in page titles even when config is cached', function () {
    // The regression itself: with .env unavailable (cached-config
    // conditions), the title must still carry the app name.
    $head = file_get_contents(resource_path('views/components/layouts/head.blade.php'));

    expect($head)->toContain("config('app.name')")
        ->and($head)->not->toContain("env('APP_NAME')");

    config(['app.name' => 'Hive']);

    $rendered = view('components.layouts.head', ['title' => 'Projects'])->render();

    expect($rendered)->toContain('<title>Projects | Hive</title>');
});
