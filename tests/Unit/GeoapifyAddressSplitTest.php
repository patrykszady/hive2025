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

/**
 * Leads arrive as free text from partner sites — no structured city/state
 * fields — so an address typed without commas is routine, not exotic. It used
 * to be refused outright ("refusing to geocode an unanchored street") because
 * every segment here is comma-delimited, which lost the lead's whole address.
 */
it('puts the commas back into an address typed without them', function () {
    // The real refused lead, double space and all.
    expect(GeoapifyService::normalizeSeparators('166 Akenside rd  Riverside Il'))
        ->toBe('166 Akenside rd, Riverside, IL');

    expect(GeoapifyService::normalizeSeparators('1234 W Mt Prospect Rd Mount Prospect IL'))
        ->toBe('1234 W Mt Prospect Rd, Mount Prospect, IL');

    // A trailing ZIP sits after the state.
    expect(GeoapifyService::normalizeSeparators('166 Akenside Rd Riverside IL 60546'))
        ->toBe('166 Akenside Rd, Riverside, IL 60546');

    // Already comma-delimited: the sender said where the segments are, so
    // nothing is second-guessed.
    expect(GeoapifyService::normalizeSeparators('5 Oak St, Barrington, IL 60010'))
        ->toBe('5 Oak St, Barrington, IL 60010');
});

it('never mistakes a street suffix or directional for the state', function () {
    // This is what the anchor check exists to stop, and the comma repair must
    // not punch a hole in it: each of these ends in a valid state code that is
    // not a state, and none has a city, so each stays untouched and refused.
    foreach (['960 Danielson Ct', '123 Main St NE', '77 Lakeview Dr OK'] as $address) {
        expect(GeoapifyService::normalizeSeparators($address))->toBe($address)
            ->and(GeoapifyService::addressAnchor($address))->toBeNull();
    }

    // Still no state anywhere — Rolla, Missouri stays off the table.
    expect(GeoapifyService::addressAnchor('511 Sherwood Dr'))->toBeNull();
});

it('geocodes a comma-less address as full structured fields', function () {
    // The end of the fix: anchored AND split, so the geocoder is asked with
    // fields rather than free text (which is what makes it answer the wrong
    // ZIP), and the city is available to verify the answer against.
    expect(GeoapifyService::addressAnchor('166 Akenside rd  Riverside Il'))
        ->toBe(['zip' => null, 'state' => 'IL']);

    expect(GeoapifyService::splitForGeocoding('166 Akenside rd  Riverside Il'))
        ->toBe([
            'housenumber' => '166',
            'street' => 'Akenside rd',
            'country' => 'United States',
            'state' => 'IL',
            'city' => 'Riverside',
        ]);
});
