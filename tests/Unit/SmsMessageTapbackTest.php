<?php

use App\Models\SmsMessage;

uses(Tests\TestCase::class);

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

it('parses mojibake Polish tapback text from iOS relay', function () {
    $text = "Dodano â€žkciuk wÂ gÃ³rÄ™â€ do â€žSiema Grzesiek, bÄ™dziemy uÅ¼ywaÄ‡ ten numer zÄ™by Grzesiek i ja mieliÅ›my te same informacje caÅ‚y czas. - Patryk\n-PSâ€";

    $msg = new SmsMessage(['text' => $text]);
    $parsed = $msg->parseTapback();

    expect($parsed)->not->toBeNull()
        ->and($parsed['emoji'])->toBe('👍')
        ->and($parsed['quoted'])->toContain('Siema Grzesiek');
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

it('repairs mojibake in display text and strips signature', function () {
    $msg = new SmsMessage([
        'text' => "Hi , tak bÄ™dzie miÄ™dzy 1-2 pm\n-PS",
    ]);

    expect($msg->display_text)->toBe('Hi , tak będzie między 1-2 pm');
});

it('builds a searchable array from the cleaned display text', function () {
    $msg = new SmsMessage([
        'thread_id' => 7,
        'from_number' => '+13125550123',
        'text' => "Hi , tak bÄ™dzie miÄ™dzy 1-2 pm\n-PS",
    ]);

    $array = $msg->toSearchableArray();

    expect($array['thread_id'])->toBe(7)
        ->and($array['from_number'])->toBe('+13125550123')
        ->and($array['text'])->toBe('Hi , tak będzie między 1-2 pm');
});

it('only indexes messages that have body text', function () {
    expect((new SmsMessage(['text' => 'hello']))->shouldBeSearchable())->toBeTrue()
        ->and((new SmsMessage(['text' => '   ']))->shouldBeSearchable())->toBeFalse()
        ->and((new SmsMessage(['text' => null]))->shouldBeSearchable())->toBeFalse();
});
