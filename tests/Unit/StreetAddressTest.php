<?php

use App\Support\StreetAddress;

it('knows a street from a landmark', function (?string $value, bool $expected) {
    expect(StreetAddress::looksLikeStreet($value))->toBe($expected);
})->with([
    'street' => ['1210 East Crabtree Drive', true],
    'street with unit' => ['7815 Kenton Ave #2', true],
    'landmark' => ['by Lake Arlington', false],
    'town only' => ['Arlington Heights', false],
    'zip only' => ['60004', false],
    'empty' => ['', false],
    'null' => [null, false],
]);

it('reads the street address out of a message', function (string $text, ?array $expected) {
    expect(StreetAddress::findInText($text))->toBe($expected);
})->with([
    'city and zip on the same line' => [
        "Yes.  Sorry.\n \n1210 East Crabtree Drive, Arlington Heights  60004\n \n312 636 2700\n",
        ['address' => '1210 East Crabtree Drive', 'city' => 'Arlington Heights', 'state' => null, 'zip' => '60004'],
    ],
    'mid-sentence' => [
        "Address is 1210 East Crabtree Drive, Arlington Heights 60004\n\nHave a great week.",
        ['address' => '1210 East Crabtree Drive', 'city' => 'Arlington Heights', 'state' => null, 'zip' => '60004'],
    ],
    'locality on the next line' => [
        "Sure thing - info as follows:\n\n7815 Kenton Ave \nSkokie, IL 60076\n\n(832) 257-1204\n\nThanks,\n\nWill",
        ['address' => '7815 Kenton Ave', 'city' => 'Skokie', 'state' => 'IL', 'zip' => '60076'],
    ],
    'full line with state and a period' => [
        'Our address is 2 Regan Blvd, Barrington, IL 60010. Kathy',
        ['address' => '2 Regan Blvd', 'city' => 'Barrington', 'state' => 'IL', 'zip' => '60010'],
    ],
    'directional, no zip' => [
        '5647 N Magnolia Ave, Chicago, IL',
        ['address' => '5647 N Magnolia Ave', 'city' => 'Chicago', 'state' => 'IL', 'zip' => null],
    ],
    'street only, trailing period' => [
        "I have 2 kids; we're at 511 Sherwood Dr.",
        ['address' => '511 Sherwood Dr', 'city' => null, 'state' => null, 'zip' => null],
    ],
    'a landmark is not an address' => ['We live in Arlington Heights by Lake Arlington and have a small bathroom', null],
    'our own signature' => ["Thank you,\nGS Construction\nGreg & Patryk | (224) 735-4200\nwww.gs.construction | Google | Best of Houzz | Instagram", null],
    'a phone number' => ['Please call me at 847 749 4614', null],
    'nothing' => ['', null],
]);

it('tidies a street\'s casing without taking any capital away', function (string $typed, string $shown) {
    expect(\App\Support\StreetAddress::tidyCase($typed))->toBe($shown);
})->with([
    'all lower' => ['6 drake terrace', '6 Drake Terrace'],
    'already right' => ['6 Drake Terrace', '6 Drake Terrace'],
    'internal capitals kept' => ['12 McDonald Ct NE', '12 McDonald Ct NE'],
    'ordinal and unit untouched' => ['2258 south 8th avenue #4b', '2258 South 8th Avenue #4b'],
    'joining word stays small' => ['1 avenue of the americas', '1 Avenue of the Americas'],
    'shouting is left alone' => ['400 N WHEELING RD', '400 N WHEELING RD'],
    'extra spaces kept' => ['6  drake terrace', '6  Drake Terrace'],
]);

it('shows the leads table street cased for reading', function () {
    $lead = new \App\Models\Lead(['lead_data' => ['address' => '6 drake terrace, prospect heights, IL 60070', 'city' => 'Prospect Heights']]);

    expect($lead->shortAddressParts())->toBe(['city' => 'Prospect Heights', 'street' => '6 Drake Terrace']);
});

