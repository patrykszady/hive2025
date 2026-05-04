<?php

use App\Services\GeoapifyService;

it('normalizes geoapify result fields to legacy address keys', function () {
    $result = [
        'place_id' => 'abc123',
        'housenumber' => '130',
        'street' => 'E Main Ave',
        'city' => 'Chicago',
        'state_code' => 'IL',
        'postcode' => '60640',
        'formatted' => '130 E Main Ave, Chicago, IL 60640, United States of America',
    ];

    $normalized = GeoapifyService::normalizeGeoapifyResult($result);

    expect($normalized['place_id'])->toBe('abc123')
        ->and($normalized['street_number'])->toBe('130')
        ->and($normalized['route'])->toBe('E Main Ave')
        ->and($normalized['locality'])->toBe('Chicago')
        ->and($normalized['administrative_area_level_1'])->toBe('IL')
        ->and($normalized['postal_code'])->toBe('60640')
        ->and($normalized['formatted_address'])->toBe('130 E Main Ave, Chicago, IL 60640');
});

it('builds fallback formatted address when geoapify formatted field is missing', function () {
    $result = [
        'place_id' => 'xyz789',
        'housenumber' => '45',
        'street' => 'W Lake St',
        'city' => 'Elmhurst',
        'state' => 'Illinois',
        'postcode' => '60126',
    ];

    $normalized = GeoapifyService::normalizeGeoapifyResult($result);

    expect($normalized['place_id'])->toBe('xyz789')
        ->and($normalized['administrative_area_level_1'])->toBe('Illinois')
        ->and($normalized['formatted_address'])->toBe('45 W Lake St, Elmhurst, Illinois 60126');
});

it('sorts autocomplete results by distance from vendor location', function () {
    $origin = '-87.9058,42.1142';

    $results = [
        [
            'place_id' => 'far',
            'lat' => 41.8781,
            'lon' => -87.6298,
            'formatted' => 'Chicago, IL',
        ],
        [
            'place_id' => 'near',
            'lat' => 42.1092,
            'lon' => -87.8445,
            'formatted' => 'Wheeling, IL',
        ],
        [
            'place_id' => 'no-coords',
            'formatted' => 'Unknown',
        ],
    ];

    $sorted = GeoapifyService::sortResultsByDistance($results, $origin);

    expect($sorted[0]['place_id'])->toBe('near')
        ->and($sorted[1]['place_id'])->toBe('far')
        ->and($sorted[2]['place_id'])->toBe('no-coords');
});

it('detects township suggestions for filtering', function () {
    expect(GeoapifyService::isTownshipSuggestion('317 North Westminster Drive, Palatine Township, Palatine, IL 60067'))
        ->toBeTrue()
        ->and(GeoapifyService::isTownshipSuggestion('317 North Westminster Drive, Palatine, IL 60067'))
        ->toBeFalse();
});
