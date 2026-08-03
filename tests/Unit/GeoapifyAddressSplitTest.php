<?php

use App\Services\GeoapifyService;

/**
 * The structured-query split is what makes geocoding trustworthy: free text
 * makes the geocoder hedge (and answer the wrong ZIP), while fields pin it.
 */
it('splits a written address into structured geocoder fields', function () {
    expect(GeoapifyService::splitForGeocoding('5647 N Magnolia Ave, Chicago'))
        ->toBe([
            'housenumber' => '5647',
            'street' => 'N Magnolia Ave',
            'country' => 'United States',
            'city' => 'Chicago',
        ]);

    expect(GeoapifyService::splitForGeocoding('5 Oak St, Barrington, IL 60010, USA'))
        ->toBe([
            'housenumber' => '5',
            'street' => 'Oak St',
            'country' => 'United States',
            'postcode' => '60010',
            'state' => 'IL',
            'city' => 'Barrington',
        ]);
});

it('refuses to structure an address with nowhere to look', function () {
    // A bare street is unanswerable: the geocoder would happily resolve
    // "511 Sherwood Dr" to Rolla, Missouri at full confidence.
    expect(GeoapifyService::splitForGeocoding('511 Sherwood Dr'))->toBeNull()
        ->and(GeoapifyService::splitForGeocoding('Sherwood Dr, Chicago'))->toBeNull()
        ->and(GeoapifyService::splitForGeocoding(''))->toBeNull();
});

it('recognises what anchors an address to a place', function () {
    expect(GeoapifyService::addressAnchor('511 Sherwood Dr'))->toBeNull();

    expect(GeoapifyService::addressAnchor('960 Danielson Ct, Gurnee'))
        ->toBe(['zip' => null, 'state' => null]);

    expect(GeoapifyService::addressAnchor('5 Oak St, Barrington, IL 60010'))
        ->toBe(['zip' => '60010', 'state' => 'IL']);
});
