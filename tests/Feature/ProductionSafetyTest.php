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

it('keeps every OpenCV job on the single-process timelapse queue', function () {
    // Each alignment spawns a python process peaking near 1.7GB. On the
    // default supervisor (10 workers) that asked ~17GB of an 8GB box and the
    // kernel SIGKILLed them mid-frame. Two things must hold, and BOTH are
    // easy to lose: the jobs name the queue, and every Bus::chain() names it
    // too — chaining OVERWRITES a job's own queue with the chain's.
    $jobs = [
        new App\Jobs\AlignTimelapseFrame(1),
        new App\Jobs\HarmonizeTimelapseFrameColor(1, 2),
        new App\Jobs\ProcessTimelapseFrame(1),
    ];

    foreach ($jobs as $job) {
        expect($job->queue)->toBe('timelapse', class_basename($job).' must run on the timelapse queue');
    }

    $chained = [];
    foreach (['app/Livewire/Projects/TimelapseStudio.php',
        'app/Console/Commands/ReprocessTimelapses.php',
        'app/Jobs/HarmonizeTimelapseColors.php'] as $file) {
        $body = file_get_contents(base_path($file));
        preg_match_all('/Bus::chain\(.*?\)\s*(->[a-zA-Z]+\([^)]*\)\s*)*->dispatch\(\)/s', $body, $m);
        foreach ($m[0] as $call) {
            if (! str_contains($call, "onQueue('timelapse')")) {
                $chained[] = $file;
            }
        }
    }

    expect($chained)->toBe([], 'Bus::chain() without ->onQueue(\'timelapse\') in: '.implode(', ', $chained));

    // And the supervisor that drains it must exist, in every environment,
    // with exactly one process — a queue nothing listens to is silent death.
    foreach (['production', 'local'] as $env) {
        $supervisor = config("horizon.environments.{$env}.timelapse");
        expect($supervisor)->not->toBeNull("horizon.{$env} needs a timelapse supervisor")
            ->and($supervisor['maxProcesses'])->toBe(1);
    }

    expect(config('horizon.defaults.timelapse.queue'))->toBe(['timelapse']);
});

it('keeps Livewire assets in the cached public-page body', function () {
    // CachePublicPage stores the response body from inside the middleware —
    // BEFORE Livewire's post-request asset auto-injection runs. When the
    // guest layout relied on auto-injection, every cache-hit visitor got a
    // page with no livewire.js and therefore no Alpine: dead nav dropdown,
    // dead language switcher, dead marketing interactions. The guest layout
    // now renders @livewireScripts explicitly so the assets are part of the
    // cached body; this pins that.
    Illuminate\Support\Facades\Cache::flush();

    $miss = $this->get('/en/welcome/photos');
    $miss->assertOk();

    $hit = $this->get('/en/welcome/photos');
    $hit->assertOk();
    $hit->assertHeader('X-Page-Cache', 'hit');

    expect($hit->getContent())->toContain('/livewire');
});
