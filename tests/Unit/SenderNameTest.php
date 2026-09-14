<?php

use App\Support\SenderName;

it('reads a person\'s name out of a From display name', function (?string $display, ?string $email, ?string $expected) {
    expect(SenderName::fromHeader($display, $email))->toBe($expected);
})->with([
    'provider junk after the surname' => ['William Johnson89 wa', 'willjohn1089@gmail.com', 'William Johnson'],
    'last, first' => ['JOHNSON, WILLIAM', null, 'William Johnson'],
    'plain' => ['Amy Dusto', null, 'Amy Dusto'],
    'typed lowercase' => ['amy dusto', 'adusto@example.com', 'Amy Dusto'],
    'title and suffix' => ['Mr. William Johnson Jr.', null, 'William Johnson'],
    'quoted with a note' => ['"Will Johnson" (Home)', null, 'Will Johnson'],
    'mixed case kept' => ['Ronald McDonald', null, 'Ronald McDonald'],
    'a pet name is still two words' => ['MiMi DiDi', 'mdimarco71@hotmail.com', 'MiMi DiDi'],
    'apostrophe' => ["mary o'brien", null, "Mary O'Brien"],
    'polish letters' => ['Michał Nowak', null, 'Michał Nowak'],
    'the address local part' => ['willjohn1089', 'willjohn1089@gmail.com', null],
    'dotted local part' => ['will.john', 'will.john@gmail.com', null],
    'an address' => ['willjohn1089@gmail.com', 'willjohn1089@gmail.com', null],
    'a device' => ["Bill's iPhone", null, null],
    'two people' => ['Amy Dusto and Chris Ecker', null, null],
    'empty' => ['', null, null],
    'null' => [null, null, null],
]);

it('pairs a first-name sign-off with the surname from the header', function (?string $extracted, ?string $display, ?string $expected) {
    expect(SenderName::complete($extracted, $display, 'sender@example.com'))->toBe($expected);
})->with([
    'sign-off is a short form of the header name' => ['Will', 'William Johnson89 wa', 'William Johnson'],
    'sign-off equals the header first name' => ['William', 'William Johnson', 'William Johnson'],
    'classic nickname' => ['Bill', 'William Johnson', 'William Johnson'],
    'polish short form' => ['Kasia', 'Katarzyna Nowak', 'Katarzyna Nowak'],
    'signed with the surname' => ['Johnson', 'William Johnson', 'William Johnson'],
    'header first name is an initial' => ['Will', 'W Johnson', 'Will Johnson'],
    'someone else\'s account' => ['Mark', 'Mary Johnson', 'Mark'],
    'a near-miss is not a match' => ['Dan', 'Dana Johnson', 'Dan'],
    'they wrote their full name' => ['Will Johnson', 'William Johnson89 wa', 'Will Johnson'],
    'the header spells the same name' => ['Michael Dimarco', 'Michael DiMarco', 'Michael DiMarco'],
    'the header shouts the same name' => ['Michael DiMarco', 'MICHAEL DIMARCO', 'Michael DiMarco'],
    'a couple wrote in' => ['Amy Dusto and Chris Ecker', 'Amy Dusto', 'Amy Dusto and Chris Ecker'],
    'header has no surname either' => ['Will', 'Will', 'Will'],
    'nothing extracted' => [null, 'William Johnson89 wa', 'William Johnson'],
    'nothing anywhere' => [null, "Bill's iPhone", null],
]);

it('reads a surname out of the address when the header is no help', function (?string $extracted, ?string $display, string $email, ?string $expected) {
    expect(SenderName::complete($extracted, $display, $email))->toBe($expected);
})->with([
    'pet name in the header, name in the address' => ['Michael', 'MiMi DiDi', 'michael_dimarco@outlook.com', 'Michael Dimarco'],
    'no header at all' => ['Michael', null, 'michael.dimarco@example.com', 'Michael Dimarco'],
    'surname first in the address' => ['Michael', null, 'dimarco.michael@example.com', 'Michael Dimarco'],
    'nickname signed, formal in the address' => ['Mike', null, 'michael_dimarco@example.com', 'Mike Dimarco'],
    'an address with no split' => ['Michael', 'MiMi DiDi', 'mdimarco71@hotmail.com', 'Michael'],
    'an address that is not their name' => ['Mark', null, 'mary.johnson@example.com', 'Mark'],
    'a proper header still wins' => ['Will', 'William Johnson89 wa', 'will.jones@example.com', 'William Johnson'],
    'a stray number is not a surname' => ['Toby 312', 'Toby 312', 'toby.daisy112148@gmail.com', 'Toby Daisy'],
    'nothing signed, one-word header, address spells it' => [null, 'Toby', 'toby.daisy112148@gmail.com', 'Toby Daisy'],
    'a stray number with no other source just goes' => ['Toby 312', 'Toby 312', 'sender@example.com', 'Toby'],
]);

it('counts the real words of a name', function (string $name, int $expected) {
    expect(count(SenderName::nameWords($name)))->toBe($expected);
})->with([
    'plain' => ['Michael DiMarco', 2],
    'number tacked on' => ['Toby 312', 1],
    'initial' => ['J. Bradley Bates', 3],
    'empty' => ['', 0],
]);

it('splits an address into its two name parts, or refuses', function (string $email, ?array $expected) {
    expect(SenderName::addressParts($email))->toBe($expected);
})->with([
    'underscore' => ['michael_dimarco@outlook.com', ['michael', 'dimarco']],
    'dot' => ['Chris.Ecker@example.com', ['chris', 'ecker']],
    'digits between' => ['amy2dusto@example.com', ['amy', 'dusto']],
    'one part' => ['willjohn1089@gmail.com', null],
    'three parts' => ['ecker.chris.r@gmail.com', null],
    'initial and surname' => ['mdimarco71@hotmail.com', null],
]);
