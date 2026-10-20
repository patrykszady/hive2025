<?php

/**
 * App exports (sheets, bank statements) sit behind 10-minute signed links;
 * exports:prune removes the files once those links are long gone.
 */

use Illuminate\Support\Facades\Storage;

it('deletes export files older than an hour and keeps fresh ones', function () {
    Storage::fake('local');
    $disk = Storage::disk('local');
    $disk->put('exports/sheets/old.xlsx', 'old');
    $disk->put('exports/bank-statements/fresh.pdf', 'fresh');
    $disk->put('other/keep.txt', 'not an export');
    touch($disk->path('exports/sheets/old.xlsx'), now()->subHours(2)->getTimestamp());

    $this->artisan('exports:prune')->expectsOutputToContain('Deleted 1 expired export file(s).')->assertSuccessful();

    expect($disk->exists('exports/sheets/old.xlsx'))->toBeFalse()
        ->and($disk->exists('exports/bank-statements/fresh.pdf'))->toBeTrue()
        ->and($disk->exists('other/keep.txt'))->toBeTrue();
});
