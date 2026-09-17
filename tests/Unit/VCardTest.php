<?php

use App\Support\VCard;

it('reads an android 2.1 card with quoted-printable and a bare type', function (): void {
    $raw = "BEGIN:VCARD\r\nVERSION:2.1\r\nN;CHARSET=UTF-8;ENCODING=QUOTED-PRINTABLE:Ko=C5=82odziej;Jennifer;;;\r\nFN;CHARSET=UTF-8;ENCODING=QUOTED-PRINTABLE:Jennifer Ko=C5=82odziej\r\nORG:Barrington Tile & Stone\r\nTEL;CELL:(847) 555-0123\r\nTEL;WORK:+18475550199\r\nEMAIL;PREF:jen@example.com\r\nEND:VCARD\r\n";

    $cards = VCard::parse($raw);

    expect($cards)->toHaveCount(1);
    expect($cards[0]['name'])->toBe('Jennifer Kołodziej');
    expect($cards[0]['first'])->toBe('Jennifer');
    expect($cards[0]['last'])->toBe('Kołodziej');
    expect($cards[0]['org'])->toBe('Barrington Tile & Stone');
    expect($cards[0]['phones'])->toBe([['type' => 'cell', 'number' => '(847) 555-0123'], ['type' => 'work', 'number' => '+18475550199']]);
    expect($cards[0]['emails'])->toBe([['type' => '', 'address' => 'jen@example.com']]);
});

it('reads an ios 3.0 card with folded lines, item groups and an address', function (): void {
    $raw = "BEGIN:VCARD\nVERSION:3.0\nPRODID:-//Apple Inc.//iPhone OS 17.5//EN\nN:Brown;Katherine;;;\nFN:Katherine Brown\nitem1.TEL;type=pref:+1 (224) 555-0100\nitem1.X-ABLabel:Mobile\nitem2.EMAIL;type=INTERNET;type=HOME:kate@exa\n mple.com\nitem3.ADR;type=HOME:;;1801 Elm St;Park Ridge;IL;60068;\nNOTE:Referred by Jennifer\\, wants a bath quote\nPHOTO;ENCODING=b;TYPE=JPEG:/9j/4AAQSkZJRgABAQ\n AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA\nEND:VCARD\n";

    $cards = VCard::parse($raw);

    expect($cards)->toHaveCount(1);
    expect($cards[0]['name'])->toBe('Katherine Brown');
    expect($cards[0]['phones'])->toBe([['type' => '', 'number' => '+1 (224) 555-0100']]);
    expect($cards[0]['emails'])->toBe([['type' => 'home', 'address' => 'kate@example.com']]);
    expect($cards[0]['addresses'])->toBe(['1801 Elm St, Park Ridge, IL 60068']);
    expect($cards[0]['note'])->toBe('Referred by Jennifer, wants a bath quote');
});

it('takes the organisation as the name when the card has none, and ignores empty cards', function (): void {
    $cards = VCard::parse("BEGIN:VCARD\nVERSION:3.0\nORG:ABC Supply\nTEL;TYPE=MAIN:8475550000\nEND:VCARD\nBEGIN:VCARD\nVERSION:3.0\nEND:VCARD\n");

    expect($cards)->toHaveCount(1);
    expect($cards[0]['name'])->toBe('ABC Supply');
    expect($cards[0]['phones'][0]['type'])->toBe('main');
    expect(VCard::looksLikeVCard("\xEF\xBB\xBFBEGIN:VCARD\n"))->toBeTrue();
    expect(VCard::looksLikeVCard("\x89PNG"))->toBeFalse();
});
