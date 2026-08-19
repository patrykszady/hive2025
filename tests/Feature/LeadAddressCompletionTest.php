<?php

use App\Services\GeoapifyService;
use App\Services\LeadAddressCompleter;

/**
 * A real lead — a kitchen enquiry typed on a phone as "166 Akenside rd
 * Riverside Il" — was logged as an unanchored street and never became a
 * client. Three separate faults had to line up for that, and each is pinned
 * here: the missing commas, the query built without them, and the completed
 * answer being dropped on the way back into the payload.
 */
it('fills a blank the website posted as an explicit null', function () {
    // THE root cause. lead_data arrives as {"city": null, …} — the key is
    // present, so the old `$leadData + [...]` union treated it as already
    // filled and threw the geocoder's answer away.
    $this->mock(GeoapifyService::class)
        ->shouldReceive('geocodeAddress')
        ->once()
        ->andReturn([
            'address' => '166 Akenside Road',
            'city' => 'Riverside',
            'state' => 'IL',
            'zip_code' => '60546',
        ]);

    $completed = app(LeadAddressCompleter::class)->complete([
        'address' => '166 Akenside rd  Riverside Il',
        'city' => null,
        'state' => 'IL',
        'zip' => '60546',
    ]);

    expect($completed['city'])->toBe('Riverside')
        ->and($completed['state'])->toBe('IL')
        ->and($completed['zip'])->toBe('60546')
        // And the street no longer repeats the city it now sits next to.
        ->and($completed['address'])->toBe('166 Akenside Rd');
});

it('keeps the street in the sender\'s own words, not the geocoder\'s', function () {
    $this->mock(GeoapifyService::class)
        ->shouldReceive('geocodeAddress')
        ->once()
        // The geocoder spells it out in full; the sender abbreviated.
        ->andReturn([
            'address' => '166 Akenside Road',
            'city' => 'Riverside',
            'state' => 'IL',
            'zip_code' => '60546',
        ]);

    $completed = app(LeadAddressCompleter::class)->complete([
        'address' => '166 Akenside rd  Riverside Il',
        'city' => null,
        'state' => 'IL',
        'zip' => '60546',
    ]);

    expect($completed['address'])->toBe('166 Akenside Rd');
});

it('leaves a well-formed street exactly as the sender typed it', function () {
    // Nothing to strip, so nothing is touched — casing included.
    $this->mock(GeoapifyService::class)
        ->shouldReceive('geocodeAddress')
        ->once()
        ->andReturn([
            'address' => '5647 N Magnolia Ave',
            'city' => 'Chicago',
            'state' => 'IL',
            'zip_code' => '60660',
        ]);

    $completed = app(LeadAddressCompleter::class)->complete([
        'address' => '5647 N Magnolia Ave',
        'city' => null,
        'state' => 'IL',
        'zip' => '60660',
    ]);

    expect($completed['address'])->toBe('5647 N Magnolia Ave')
        ->and($completed['city'])->toBe('Chicago');
});

it('keeps the comma between street and state when no city was stated', function () {
    // str_contains() reports an empty needle as found, so a null city used to
    // take the "street already names the city" branch and join with a space —
    // dropping the one separator the geocoder splits segments on.
    $this->mock(GeoapifyService::class)
        ->shouldReceive('geocodeAddress')
        ->once()
        ->with('166 Akenside rd  Riverside Il, IL 60546')
        ->andReturn([
            'address' => '166 Akenside Road',
            'city' => 'Riverside',
            'state' => 'IL',
            'zip_code' => '60546',
        ]);

    app(LeadAddressCompleter::class)->complete([
        'address' => '166 Akenside rd  Riverside Il',
        'city' => null,
        'state' => 'IL',
        'zip' => '60546',
    ]);
});

it('still lets a stated value beat the geocoder', function () {
    // The sender who wrote their own ZIP knows it better than a geocoder that
    // answers 60642 for the same street.
    $this->mock(GeoapifyService::class)
        ->shouldReceive('geocodeAddress')
        ->once()
        ->andReturn([
            'address' => '5647 N Magnolia Ave',
            'city' => 'Chicago',
            'state' => 'IL',
            'zip_code' => '60642',
        ]);

    $completed = app(LeadAddressCompleter::class)->complete([
        'address' => '5647 N Magnolia Ave',
        'city' => 'Chicago',
        'state' => null,
        'zip' => '60660',
    ]);

    expect($completed['zip'])->toBe('60660')
        ->and($completed['city'])->toBe('Chicago')
        ->and($completed['state'])->toBe('IL');
});

it('leaves an already complete address alone without calling the geocoder', function () {
    $this->mock(GeoapifyService::class)->shouldNotReceive('geocodeAddress');

    $data = [
        'address' => '5 Oak St',
        'city' => 'Barrington',
        'state' => 'IL',
        'zip' => '60010',
    ];

    expect(app(LeadAddressCompleter::class)->complete($data))->toBe($data);
});
