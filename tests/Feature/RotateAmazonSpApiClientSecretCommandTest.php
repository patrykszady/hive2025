<?php

it('supports dry run rotation command', function () {
    $this->artisan('amazon:spapi-rotate-client-secret --dry-run')
        ->expectsOutput('Dry run enabled. Skipping SP-API rotation call.')
        ->assertSuccessful();
});
