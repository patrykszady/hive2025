<?php

namespace App\Services;

use App\Models\EmailTracking;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Support\Str;

/**
 * Who a message in the certificates mailbox is about, from the message
 * itself — before anyone reads the certificate.
 *
 * An agent's COI usually arrives as a reply to the request we sent, and that
 * request named the vendor: "COI Request | Mariusz Kot Construction". The
 * insured name on the certificate is read afterwards, and it can point at the
 * wrong one of two records for the same business — "Kot Construction" and
 * "Mariusz Kot Construction, Inc", same owner, same address — where the
 * thread never could.
 */
class InsuranceReplyContext
{
    /**
     * @param  array{subject?: ?string, from?: array, to?: array, cc?: array}  $message  Nylas message
     * @return array{vendor_id: int, belongs_to_vendor_id: ?int, source: string}|null
     */
    public function resolve(array $message): ?array
    {
        $subject = $this->bareSubject((string) ($message['subject'] ?? ''));

        if ($subject !== '') {
            // A reply to a request we sent: the request knows both parties.
            $request = EmailTracking::withoutGlobalScopes()
                ->where('event_type', 'sent')
                ->where('metadata->email_template_name', 'Insurance Request')
                ->where('metadata->subject', $subject)
                ->orderByDesc('event_at')
                ->first();

            if ($request && ! empty($request->metadata['vendor_id'])) {
                return [
                    'vendor_id' => (int) $request->metadata['vendor_id'],
                    'belongs_to_vendor_id' => ! empty($request->metadata['belongs_to_vendor_id'])
                        ? (int) $request->metadata['belongs_to_vendor_id']
                        : null,
                    'source' => 'request',
                ];
            }

            // The request's subject shape, even when we have no record of it.
            // The subject carries the vendor's display name (an accessor,
            // not a column), so compare in PHP.
            if (preg_match('/^[^|]+\|\s*(.+)$/u', $subject, $m)) {
                $name = mb_strtolower(trim($m[1]));

                $vendor = Vendor::withoutGlobalScopes()
                    ->whereNotNull('business_name')
                    ->where(fn ($q) => $q->whereNull('business_type')->orWhere('business_type', '!=', 'Retail'))
                    ->get()
                    ->first(fn (Vendor $vendor) => mb_strtolower(trim((string) $vendor->name)) === $name
                        || mb_strtolower(trim((string) $vendor->business_name)) === $name);

                if ($vendor) {
                    return ['vendor_id' => (int) $vendor->id, 'belongs_to_vendor_id' => null, 'source' => 'subject'];
                }
            }
        }

        // The people on the message — the vendor CC'd on their agent's reply,
        // or a vendor sending their own certificate. Decisive only when they
        // all belong to one vendor.
        $emails = collect([$message['from'] ?? [], $message['to'] ?? [], $message['cc'] ?? []])
            ->flatten(1)
            ->map(fn ($party) => mb_strtolower(trim((string) (is_array($party) ? ($party['email'] ?? '') : $party))))
            ->filter()
            ->reject(fn (string $email) => $this->isOurs($email))
            ->unique()
            ->values();

        if ($emails->isEmpty()) {
            return null;
        }

        $vendorIds = User::withoutGlobalScopes()
            ->where(function ($query) use ($emails) {
                foreach ($emails as $email) {
                    $query->orWhereRaw('LOWER(email) = ?', [$email]);
                }
            })
            ->get()
            ->flatMap(fn (User $user) => $user->vendors()->withoutGlobalScopes()->pluck('vendors.id'))
            ->unique()
            ->values();

        if ($vendorIds->count() === 1) {
            return ['vendor_id' => (int) $vendorIds->first(), 'belongs_to_vendor_id' => null, 'source' => 'recipient'];
        }

        return null;
    }

    /** "Re: RE: Fwd: COI Request | X" → "COI Request | X". */
    public function bareSubject(string $subject): string
    {
        return Str::squish((string) preg_replace('/^\s*(?:(?:re|aw|fw|fwd|tr|wg|odp|sv|vs)\s*(?:\[\d+\])?\s*:\s*)+/iu', '', $subject));
    }

    protected function isOurs(string $email): bool
    {
        if ($email === mb_strtolower((string) config('nylas.certificates_email'))) {
            return true;
        }

        $domain = Str::after($email, '@');

        foreach ((array) config('nylas.crew_leads.internal_domains') as $internal) {
            if ($domain === $internal || str_ends_with($domain, '.'.$internal)) {
                return true;
            }
        }

        return false;
    }
}
