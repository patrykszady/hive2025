<?php

namespace App\Support\Leads;

/**
 * The "Missed You" lead email template, derived from a vendor's own
 * "Consult" template so the signature (name, phone, links) is theirs, not
 * ours: everything after the paragraph that holds {{lead_time_block}} is
 * the signature. Seeded by the 2026-11-05 migration for every vendor with a
 * Consult template. The body is Patryk's wording: {{missed_consult_line}}
 * ("Sorry we missed you — we had your consultation with … scheduled for …
 * and weren't able to connect", no full stop) carried on by the template's
 * own words, then the ask around {{pick_times_link}} — both filled by
 * LeadReplyComposer::replacePlaceholders().
 */
final class MissedYouTemplate
{
    public const NAME = 'Missed You';

    public static function subject(): string
    {
        return 'Sorry we missed you | {{vendor_name}} | {{client_last_names}}';
    }

    public static function bodyFrom(?string $consultBody): string
    {
        return '<p>Hi {{client_first_name}},</p><p></p>'
            .'<p>{{missed_consult_line}}, we left our business card at your door.</p><p></p>'
            .'<p>Let&rsquo;s find a new time: please {{pick_times_link}} that suit you and we&rsquo;ll confirm ASAP.</p><p></p>'
            .self::signatureOf((string) $consultBody);
    }

    /** The Consult template's signature: what follows its time-block paragraph. */
    public static function signatureOf(string $consultBody): string
    {
        $marker = '{{lead_time_block}}';
        $at = strpos($consultBody, $marker);
        $after = $at === false ? '' : substr($consultBody, $at + strlen($marker));
        $close = strpos($after, '</p>');
        $signature = $close === false ? '' : trim(substr($after, $close + strlen('</p>')));
        // The spacer paragraph before the signature is ours to add, not to
        // double: bodyFrom() puts one in.
        $signature = (string) preg_replace('#^(\s*<p>\s*</p>)+#', '', $signature);

        return $signature !== '' ? $signature : '<p>Thank you,<br>{{short_vendor_name}}</p>';
    }
}
