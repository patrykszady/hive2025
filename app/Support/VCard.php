<?php

namespace App\Support;

/**
 * A small vCard reader for the contact cards phones share over MMS
 * (text/x-vcard, VERSION 2.1 from Android, 3.0 from iOS). Reads the fields a
 * thread can show — name, organisation, title, phones, emails, addresses,
 * note — and ignores the rest (photos, keys, X- fields).
 */
class VCard
{
    /**
     * @return list<array{
     *   name: string, first: string, last: string, org: string, title: string, note: string,
     *   phones: list<array{type: string, number: string}>,
     *   emails: list<array{type: string, address: string}>,
     *   addresses: list<string>
     * }>
     */
    public static function parse(string $raw): array
    {
        $cards = [];
        foreach (self::cardBlocks($raw) as $lines) {
            $card = ['name' => '', 'first' => '', 'last' => '', 'org' => '', 'title' => '', 'note' => '', 'phones' => [], 'emails' => [], 'addresses' => []];
            foreach ($lines as $line) {
                $parsed = self::line($line);
                if ($parsed === null) {
                    continue;
                }
                [$key, $params, $value] = $parsed;
                switch ($key) {
                    case 'FN':
                        $card['name'] = self::unescape($value);
                        break;
                    case 'N':
                        $parts = self::split($value, ';');
                        $card['last'] = self::unescape($parts[0] ?? '');
                        $card['first'] = self::unescape($parts[1] ?? '');
                        break;
                    case 'ORG':
                        $card['org'] = self::unescape(self::split($value, ';')[0] ?? '');
                        break;
                    case 'TITLE':
                        $card['title'] = self::unescape($value);
                        break;
                    case 'NOTE':
                        $card['note'] = self::unescape($value);
                        break;
                    case 'TEL':
                        $number = trim(self::unescape($value));
                        if ($number !== '') {
                            $card['phones'][] = ['type' => self::typeLabel($params), 'number' => $number];
                        }
                        break;
                    case 'EMAIL':
                        $address = trim(self::unescape($value));
                        if ($address !== '') {
                            $card['emails'][] = ['type' => self::typeLabel($params), 'address' => $address];
                        }
                        break;
                    case 'ADR':
                        $parts = array_map(fn ($p) => trim(self::unescape($p)), self::split($value, ';'));
                        // PO box, extended, street, city, region, postal code, country
                        $street = trim(($parts[1] ?? '').' '.($parts[2] ?? ''));
                        $cityLine = trim(implode(' ', array_filter([($parts[3] ?? '') !== '' ? $parts[3].',' : '', $parts[4] ?? '', $parts[5] ?? ''])));
                        $address = trim(implode(', ', array_filter([$street, $cityLine, $parts[6] ?? ''])));
                        if ($address !== '') {
                            $card['addresses'][] = $address;
                        }
                        break;
                }
            }
            if ($card['name'] === '') {
                $card['name'] = trim($card['first'].' '.$card['last']);
            }
            if ($card['name'] === '' && $card['org'] !== '') {
                $card['name'] = $card['org'];
            }
            if ($card['name'] !== '' || $card['phones'] !== [] || $card['emails'] !== []) {
                $cards[] = $card;
            }
        }

        return $cards;
    }

    public static function looksLikeVCard(string $raw): bool
    {
        return (bool) preg_match('/^\s*BEGIN:VCARD/i', ltrim($raw, "\xEF\xBB\xBF"));
    }

    /** Unfold continuation lines and cut the text into BEGIN…END blocks. @return list<list<string>> */
    private static function cardBlocks(string $raw): array
    {
        $raw = ltrim($raw, "\xEF\xBB\xBF");
        $raw = preg_replace("/\r\n|\r/", "\n", $raw) ?? $raw;
        // RFC folding: a line starting with a space or tab continues the previous one.
        $raw = preg_replace("/\n[ \t]/", '', $raw) ?? $raw;
        // Quoted-printable soft line breaks (vCard 2.1): "=\n" continues the value.
        $raw = str_replace("=\n", '', $raw);

        $blocks = [];
        $current = null;
        foreach (explode("\n", $raw) as $line) {
            $trimmed = trim($line);
            if (strcasecmp($trimmed, 'BEGIN:VCARD') === 0) {
                $current = [];
                continue;
            }
            if (strcasecmp($trimmed, 'END:VCARD') === 0) {
                if ($current !== null) {
                    $blocks[] = $current;
                }
                $current = null;
                continue;
            }
            if ($current !== null && $trimmed !== '') {
                $current[] = $line;
            }
        }

        return $blocks;
    }

    /** @return array{0: string, 1: list<string>, 2: string}|null key, params, value */
    private static function line(string $line): ?array
    {
        $colon = strpos($line, ':');
        if ($colon === false) {
            return null;
        }
        $head = substr($line, 0, $colon);
        $value = substr($line, $colon + 1);
        $params = explode(';', $head);
        $key = strtoupper(array_shift($params) ?? '');
        // iOS groups: "item1.TEL", "item2.EMAIL".
        if (str_contains($key, '.')) {
            $key = substr($key, strrpos($key, '.') + 1);
        }
        $params = array_map('strtoupper', $params);
        foreach ($params as $p) {
            if (str_starts_with($p, 'ENCODING=QUOTED-PRINTABLE') || $p === 'QUOTED-PRINTABLE') {
                $value = quoted_printable_decode($value);
            }
            if (str_starts_with($p, 'ENCODING=B') || $p === 'BASE64') {
                return null; // photos and the like
            }
        }
        if (! mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'ISO-8859-1');
        }

        return [$key, $params, $value];
    }

    /** "TYPE=CELL,VOICE" / "CELL" / "TYPE=WORK" → "cell" / "work" / "home" / "" */
    private static function typeLabel(array $params): string
    {
        $types = [];
        foreach ($params as $p) {
            $p = str_starts_with($p, 'TYPE=') ? substr($p, 5) : $p;
            foreach (explode(',', $p) as $t) {
                $types[] = strtolower(trim($t));
            }
        }
        foreach (['cell', 'mobile', 'iphone', 'main', 'work', 'home', 'fax', 'pager'] as $known) {
            if (in_array($known, $types, true)) {
                return $known === 'mobile' || $known === 'iphone' ? 'cell' : $known;
            }
        }

        return '';
    }

    /** Split on an unescaped separator. @return list<string> */
    private static function split(string $value, string $sep): array
    {
        return preg_split('/(?<!\\\\)'.preg_quote($sep, '/').'/', $value) ?: [$value];
    }

    private static function unescape(string $value): string
    {
        return trim(str_replace(['\\n', '\\N', '\\,', '\;', '\\\\'], ["\n", "\n", ',', ';', '\\'], $value));
    }
}
