<?php

use App\Models\SmsMessage;

it('parses Polish tapback with non-breaking space inside reaction name', function () {
    // Real production text — note the U+00A0 (non-breaking space) between "w" and "górę"
    $text = "Dodano \u{201e}kciuk w\u{00a0}górę\u{201d} do \u{201e}Siema Grzesiek, będziemy używać ten numer zęby Grzesiek i ja mieliśmy te same informacje cały czas. - Patryk\n-PS\u{201d}";

    $msg = new SmsMessage(['text' => $text]);
    $parsed = $msg->parseTapback();

    expect($parsed)->not->toBeNull()
        ->and($parsed['emoji'])->toBe('👍')
        ->and($parsed['quoted'])->toContain('Siema Grzesiek');
});

it('parses Polish tapback with regular space inside reaction name', function () {
    $text = "Dodano \u{201e}kciuk w górę\u{201d} do \u{201e}original message\u{201d}";

    $msg = new SmsMessage(['text' => $text]);
    $parsed = $msg->parseTapback();

    expect($parsed)->not->toBeNull()
        ->and($parsed['emoji'])->toBe('👍');
});
