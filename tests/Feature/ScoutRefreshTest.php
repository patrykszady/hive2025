<?php

use App\Console\Commands\ScoutRefresh;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('refreshes each model in place, synchronously, and reports it', function () {
    $this->artisan('scout:refresh', ['--models' => ['App\Models\Vendor', 'App\Models\Client'], '--no-prune' => true])
        ->expectsOutputToContain('Vendor: refreshed')
        ->expectsOutputToContain('Client: refreshed')
        ->expectsOutputToContain('Search refresh complete.')
        ->assertExitCode(0);

    expect(config('scout.queue'))->toBeFalse();
});

it('reports a model that fails and exits with an error, after doing the rest', function () {
    $this->artisan('scout:refresh', ['--models' => ['App\Models\NoSuchModel', 'App\Models\Vendor'], '--no-prune' => true])
        ->expectsOutputToContain('Vendor: refreshed')
        ->expectsOutputToContain('Search refresh finished with errors: NoSuchModel')
        ->assertExitCode(1);
});

it('prunes exactly the documents whose rows are gone', function () {
    expect(ScoutRefresh::staleIds(['1', '2', '3', '9'], ['1', '2', '3', '4']))->toBe(['9'])
        ->and(ScoutRefresh::staleIds(['1'], ['1']))->toBe([]);
});
