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

it('parses Hebrew tapback (liked) using Hebrew Gershayim quotes', function () {
    // Real production message: הוסיף/ה סימן ״אהבתי״ להודעה ״Mark knows of the delay.\n-PS״
    $text = "הוסיף/ה סימן \u{05f4}אהבתי\u{05f4} להודעה \u{05f4}Mark knows of the delay.\n-PS\u{05f4}";

    $msg = new SmsMessage(['text' => $text]);
    $parsed = $msg->parseTapback();

    expect($parsed)->not->toBeNull()
        ->and($parsed['emoji'])->toBe('👍')
        ->and($parsed['quoted'])->toContain('Mark knows of the delay');
});
