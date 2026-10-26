<?php

namespace App\Services;

use App\Models\EmailMessage;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Who an enquiry's sender already is to us — asked before an email is filed
 * as a stranger's enquiry, and before anyone is emailed for "missing" details.
 *
 * Bliss Hansen, 2026-10-16: a client since 2020, mid-addition, forwarded her
 * HVAC notes to crew@ from a second Gmail address, CC'ing her husband (a
 * contact on her client record). Her account had another address, so the
 * only check — a user with that exact email — found nothing, the site's
 * classifier called the forward a new enquiry, and she was asked for "the
 * project address". Known now means any of:
 *
 *  - user       a user account with that address (the lead links to it);
 *  - household  another address on the email belongs to a user who is a
 *               contact on a client — the household is writing in together;
 *  - timeline   the address is already in the project email timeline
 *               (email_messages placed on a project or client): we have
 *               corresponded about a job with it before.
 */
class LeadSenderRecogniser
{
    /**
     * @param  list<string>  $otherEmails  the To and CC addresses on the email
     * @return array{user: ?User, known: bool, via: ?string}
     */
    public function recognise(?string $fromEmail, array $otherEmails = []): array
    {
        $from = mb_strtolower(trim((string) $fromEmail));

        if ($from === '') {
            return ['user' => null, 'known' => false, 'via' => null];
        }

        $user = User::withoutGlobalScopes()->whereRaw('LOWER(email) = ?', [$from])->first();

        if ($user) {
            return ['user' => $user, 'known' => true, 'via' => 'user'];
        }

        $others = collect($otherEmails)
            ->map(fn ($address) => mb_strtolower(trim((string) $address)))
            ->filter(fn (string $address) => $address !== '' && $address !== $from && ! self::isOurAddress($address))
            ->unique()
            ->values();

        if ($others->isNotEmpty()) {
            $household = User::withoutGlobalScopes()
                ->whereIn(DB::raw('LOWER(email)'), $others->all())
                ->whereHas('clients')
                ->exists();

            if ($household) {
                return ['user' => null, 'known' => true, 'via' => 'household'];
            }
        }

        $placed = EmailMessage::query()
            ->where(fn ($q) => $q->whereNotNull('project_id')->orWhereNotNull('client_id'))
            ->where(fn ($q) => $q->whereRaw('LOWER(from_email) = ?', [$from])
                ->orWhere('to', 'like', '%"'.$from.'"%')
                ->orWhere('cc', 'like', '%"'.$from.'"%'))
            ->exists();

        if ($placed) {
            return ['user' => null, 'known' => true, 'via' => 'timeline'];
        }

        return ['user' => null, 'known' => false, 'via' => null];
    }

    /** One of the company's own addresses: the crew mailbox or anything at an internal domain. */
    public static function isOurAddress(string $address): bool
    {
        $address = mb_strtolower(trim($address));
        $domain = (string) substr(strrchr($address, '@') ?: '', 1);

        return $address === mb_strtolower((string) config('nylas.crew_leads.mailbox'))
            || ($domain !== '' && in_array($domain, array_map('strtolower', (array) config('nylas.crew_leads.internal_domains', [])), true));
    }
}
