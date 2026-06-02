<?php

use App\Services\LeadContactProvisioner;

it('expands street suffix abbreviations to a canonical form', function () {
    expect(LeadContactProvisioner::normalizeAddressKey('873 Buttonwood Cir'))
        ->toBe('873 buttonwood circle');
    expect(LeadContactProvisioner::normalizeAddressKey('873 Buttonwood Circle'))
        ->toBe('873 buttonwood circle');
    expect(LeadContactProvisioner::normalizeAddressKey('317 N Westminster Dr'))
        ->toBe('317 n westminster drive');
    expect(LeadContactProvisioner::normalizeAddressKey('317 North Westminster Drive'))
        ->toBe('317 n westminster drive');
});

it('drops trailing city/state tokens after the street suffix', function () {
    expect(LeadContactProvisioner::normalizeAddressKey('873 Buttonwood Circle Naperville, Il'))
        ->toBe('873 buttonwood circle');
    expect(LeadContactProvisioner::normalizeAddressKey('400 N Wheeling Road, Prospect Heights'))
        ->toBe('400 n wheeling road');
});

it('returns empty string for null or blank addresses', function () {
    expect(LeadContactProvisioner::normalizeAddressKey(null))->toBe('');
    expect(LeadContactProvisioner::normalizeAddressKey('  '))->toBe('');
});
