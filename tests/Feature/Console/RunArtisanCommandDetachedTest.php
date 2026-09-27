<?php

use SsSystems\Platform\Reports\Jobs\RunArtisanCommandDetached;

/**
 * With no queue worker, a long SEO command (the GSC/Bing sync, the
 * inspection sweep) runs as a detached artisan process with its own log —
 * SsSystems\Platform\Reports\Jobs\RunArtisanCommandDetached (kit 0.12.0),
 * which replaced this app's own RunSeoChannelSyncJob/RunGscInspectBulkJob.
 * jpeterson-design's RunSeoChannelSyncJobDetachedTest covers the identical
 * shell-building logic; this is hive2025's own copy of that same proof,
 * since this app dispatches the kit job directly now rather than through a
 * site-local wrapper class.
 */
it('builds the detached cli command with the options and logs per command', function () {
    $job = new RunArtisanCommandDetached('seo:gsc-sync', [
        '--days' => 30,
        '--lag-days' => 3,
        '--force' => true,
        '--quiet' => false,
    ]);

    $shell = $job->detachedShellCommand();

    expect($shell)->toStartWith('nohup ')
        ->and($shell)->toContain(escapeshellarg(base_path('artisan')).' '.escapeshellarg('seo:gsc-sync').' --days='.escapeshellarg('30').' --lag-days='.escapeshellarg('3').' --force')
        ->and($shell)->not->toContain('--quiet')
        ->and($shell)->toEndWith(escapeshellarg(storage_path('logs/seo-gsc-sync.log')).' 2>&1 &');

    // The CLI binary, never php-fpm (PHP_BINARY under FPM).
    preg_match('/^nohup (\'[^\']+\'|\S+) /', $shell, $m);
    expect($m[1])->not->toContain('php-fpm')
        ->and($m[1])->toContain('php');
});

it('slugifies the command name for the log path', function () {
    $job = new RunArtisanCommandDetached('seo:gsc-inspect-bulk', ['--limit' => 0, '--markdown' => true]);

    expect($job->detachedLogPath())->toBe(storage_path('logs/seo-gsc-inspect-bulk.log'));
});
