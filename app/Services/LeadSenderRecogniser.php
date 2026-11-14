<?php

namespace App\Services;

use App\Models\EmailMessage;
use App\Models\User;
use Carbon\CarbonInterface;
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
 *
 * Asked about a lead already on file, only what existed before the lead
 * counts ($knownBefore). Richard Harrison, 2026-11-13 (lead 216): a stranger
 * with a phone and no address; the lead's own provisioning made his account,
 * the missing-info ask then found that account, called him "known sender
 * (user)", and he was never asked for the address.
 */
class LeadSenderRecogniser
{
    /**
     * @param  list<string>  $otherEmails  the To and CC addresses on the email
     * @param  CarbonInterface|null  $knownBefore  only accounts and timeline rows made before this moment count — the lead's own arrival
     * @return array{user: ?User, known: bool, via: ?string}
     */
    public function recognise(?string $fromEmail, array $otherEmails = [], ?CarbonInterface $knownBefore = null): array
    {
        $from = mb_strtolower(trim((string) $fromEmail));

        if ($from === '') {
            return ['user' => null, 'known' => false, 'via' => null];
        }

        $before = fn ($query) => $query->when($knownBefore !== null, fn ($q) => $q->where($q->qualifyColumn('created_at'), '<', $knownBefore));

        $user = User::withoutGlobalScopes()->whereRaw('LOWER(email) = ?', [$from])->tap($before)->first();

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
                ->tap($before)
                ->whereHas('clients', $before)
                ->exists();

            if ($household) {
                return ['user' => null, 'known' => true, 'via' => 'household'];
            }
        }

        $placed = EmailMessage::query()
            ->tap($before)
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
