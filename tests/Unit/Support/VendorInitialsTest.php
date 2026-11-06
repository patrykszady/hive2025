<?php

use App\Support\VendorInitials;

it('drops a connecting word like "of"', function () {
    expect(VendorInitials::for('Village of Palatine'))->toBe('VP');
});

it('takes the first character even when it is a digit', function () {
    expect(VendorInitials::for("1900's Barbershop"))->toBe('1B');
});

it('keeps a hyphenated, digit-led first word as its own significant word', function () {
    expect(VendorInitials::for('A-1 Suburban Lock'))->toBe('AS');
});

it('treats an embedded "&" as part of the word it sits in, not a separator', function () {
    expect(VendorInitials::for('A&A Maintenance'))->toBe('AM');
});

it('treats a standalone "&" as a separator', function () {
    expect(VendorInitials::for('Smith & Sons'))->toBe('SS');
});

it('uppercases the first two letters of a one-word name', function () {
    expect(VendorInitials::for('Walmart'))->toBe('WA');
});

it('is case-insensitive about stop words', function () {
    expect(VendorInitials::for('village OF palatine'))->toBe('VP');
});

it('skips more than one connecting word', function () {
    expect(VendorInitials::for('The Village of Palatine Water District'))->toBe('VP');
});

it('returns an empty string for an empty name', function () {
    expect(VendorInitials::for(''))->toBe('');
    expect(VendorInitials::for(null))->toBe('');
});
