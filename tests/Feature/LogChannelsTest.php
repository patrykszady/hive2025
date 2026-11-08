<?php

use Illuminate\Support\Facades\File;

/*
 * Every Log::channel('…') the app writes to is defined in config/logging.php.
 * A missing one does not throw: Laravel falls back to the emergency logger
 * and writes "Unable to create configured logger" at EMERGENCY level, and
 * the line never lands in the file anyone reads. 'receipt' (Amazon SP-API
 * secret rotation) and 'plaid_transaction_removal' were missing until
 * 2026-11-08; mozaika.haus hit the same gap every hour with 'seo-reports'.
 * Commented-out calls are ignored.
 */
it('defines every log channel the app writes to', function () {
    $used = collect(File::allFiles([app_path(), base_path('routes')]))
        ->flatMap(function ($file) {
            $code = collect(preg_split('/\R/', $file->getContents()))
                ->reject(fn (string $line) => preg_match('#^\s*(//|\*|/\*|\#)#', $line) === 1)
                ->implode("\n");
            preg_match_all("/(?:Log::channel|->channel)\\('([^']+)'\\)/", $code, $matches);

            return $matches[1];
        })
        ->unique()
        ->values();

    expect($used)->toContain('receipt', 'plaid_transaction_removal');

    foreach ($used as $channel) {
        expect(config("logging.channels.{$channel}"))->not->toBeNull("config/logging.php has no '{$channel}' channel");
    }
});
