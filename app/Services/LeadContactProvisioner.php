<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class LeadContactProvisioner
{
    /** @var array<string,string> */
    protected const STREET_SUFFIXES = [
        'st' => 'street', 'str' => 'street', 'street' => 'street',
        'ave' => 'avenue', 'av' => 'avenue', 'avenue' => 'avenue',
        'rd' => 'road', 'road' => 'road',
        'dr' => 'drive', 'drv' => 'drive', 'drive' => 'drive',
        'ln' => 'lane', 'lane' => 'lane',
        'blvd' => 'boulevard', 'boulevard' => 'boulevard',
        'cir' => 'circle', 'circle' => 'circle',
        'ct' => 'court', 'court' => 'court',
        'pl' => 'place', 'place' => 'place',
        'pkwy' => 'parkway', 'parkway' => 'parkway',
        'ter' => 'terrace', 'terrace' => 'terrace',
        'hwy' => 'highway', 'highway' => 'highway',
        'sq' => 'square', 'square' => 'square',
        'tr' => 'trail', 'trl' => 'trail', 'trail' => 'trail',
        'way' => 'way',
        'mews' => 'mews',
        'row' => 'row',
        'run' => 'run',
        'walk' => 'walk',
        'pt' => 'point', 'point' => 'point',
        'cv' => 'cove', 'cove' => 'cove',
        'crk' => 'creek', 'creek' => 'creek',
        'xing' => 'crossing', 'crossing' => 'crossing',
        'loop' => 'loop',
    ];

    /** @var array<string,string> */
    protected const DIRECTIONS = [
        'n' => 'n', 'north' => 'n',
        's' => 's', 'south' => 's',
        'e' => 'e', 'east' => 'e',
        'w' => 'w', 'west' => 'w',
        'ne' => 'ne', 'northeast' => 'ne',
        'nw' => 'nw', 'northwest' => 'nw',
        'se' => 'se', 'southeast' => 'se',
        'sw' => 'sw', 'southwest' => 'sw',
    ];

    /** @var array<string,string> */
    protected const STATE_NAMES = [
        'illinois' => 'IL', 'indiana' => 'IN', 'iowa' => 'IA', 'wisconsin' => 'WI',
        'michigan' => 'MI', 'missouri' => 'MO', 'minnesota' => 'MN', 'kentucky' => 'KY',
    ];

    public function provision(Lead $lead): void
    {
        $data = $lead->lead_data;

        $name = $this->stringValue($data['name'] ?? null);
        $email = $this->stringValue($data['email'] ?? null);
        $phones = $this->normalizePhones($data['phone'] ?? null);
        // The address parts arrive as separate fields (street here, city
        // there) — keep them discrete rather than concatenating and re-parsing,
        // which is how `state` used to get lost on the way to the client.
        // Whatever the sender stated always wins over anything we derive.
        $stated = array_filter([
            'address' => $this->stringValue($data['address'] ?? null),
            'city' => $this->stringValue($data['city'] ?? null),
            'state' => $this->stringValue($data['state'] ?? null),
            'zip_code' => $this->stringValue($data['zip'] ?? null),
        ]);

        $address = $stated['address'] ?? null;

        if ($name === null) {
            return;
        }

        // Couples enquire as one lead: "Amy Dusto and Chris Ecker" with
        // "443-333-0697 / 339-222-0798". One user for the household loses the
        // second person's number and files the lead under a mangled name
        // ("Amy Dusto and Chris" / "Ecker"), so split them and pair each with
        // the phone in the same position.
        $contacts = $this->splitContacts($name);

        $partnerEmails = collect($data['cc_emails'] ?? [])
            ->filter(fn ($value) => is_string($value) && trim($value) !== '')
            ->map(fn (string $value) => mb_strtolower(trim($value)))
            ->reject(fn (string $value) => $email !== null && $value === mb_strtolower($email))
            ->values()
            ->all();

        DB::transaction(function () use ($lead, $contacts, $email, $partnerEmails, $phones, $address, $stated) {
            $users = [];

            foreach ($contacts as $index => $contact) {
                // The sender's address belongs to whoever wrote in; the others
                // are matched by name against the addresses on the enquiry
                // ("ecker.chris.r@gmail.com" → Chris Ecker). Never positional:
                // a CC can be anyone, and a stranger's address on a customer
                // record is worse than a blank.
                $contactEmail = $index === 0
                    ? $email
                    : $this->matchEmailToContact($contact, $partnerEmails);
                $contactPhone = $phones[$index] ?? null;

                // A contact record is only worth creating when it's whole:
                // full name, email AND phone. A half-contact looks like a real
                // one in the CRM while being unreachable, and someone has to
                // finish it by hand anyway — the lead payload keeps whatever
                // did arrive until they do.
                if (! $this->isCompleteContact($contact, $contactEmail, $contactPhone)) {
                    continue;
                }

                $users[] = $this->findOrCreateUser($contact, $contactEmail, $contactPhone);
            }

            if ($users === []) {
                return;
            }

            // The lead belongs to the person who wrote in; the rest reach the
            // household through the client.
            $primary = $users[0];

            if ($lead->user_id !== $primary->id) {
                $lead->user_id = $primary->id;
                $lead->saveQuietly();
            }

            if ($address === null) {
                return;
            }

            $vendorId = (int) $lead->belongs_to_vendor_id;

            // Skip client provisioning when the resolved user is staff of this
            // vendor — these are self-submissions / test leads, not customers.
            if ($this->userBelongsToVendor($primary, $vendorId)) {
                return;
            }

            $client = $this->findOrCreateClient($primary, $address, $vendorId, $stated);

            if ($client === null) {
                return;
            }

            foreach ($users as $user) {
                if (! $user->clients()->where('clients.id', $client->id)->exists()) {
                    $user->clients()->attach($client->id);
                }
            }

            if (! $client->vendors()->where('vendors.id', $vendorId)->exists()) {
                $client->vendors()->attach($vendorId, ['source' => $lead->origin]);
            }
        });
    }

    /**
     * Enough to create a contact: both names, an address we can mail and a
     * number we can call.
     *
     * @param  array{0: string, 1: string}  $contact
     */
    public function isCompleteContact(array $contact, ?string $email, ?string $phone): bool
    {
        return trim($contact[0]) !== ''
            && trim($contact[1]) !== ''
            && $email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) !== false
            && $phone !== null && preg_match('/^\d{10}$/', $phone) === 1;
    }

    /**
     * Enough to create a client: a whole address. A client with half an
     * address can't be mailed, permitted or matched to a jobsite.
     *
     * @param  array{address: ?string, city: ?string, state: ?string, zip_code: ?string}  $parsed
     */
    public function isCompleteAddress(array $parsed): bool
    {
        return trim((string) ($parsed['address'] ?? '')) !== ''
            && trim((string) ($parsed['city'] ?? '')) !== ''
            && preg_match('/^[A-Za-z]{2}$/', (string) ($parsed['state'] ?? '')) === 1
            && preg_match('/^\d{5}(?:-\d{4})?$/', (string) ($parsed['zip_code'] ?? '')) === 1;
    }

    /**
     * The address on the enquiry that plausibly belongs to this person: its
     * local part has to carry their first or last name. Returns null when
     * nothing matches — we would rather record no address than someone
     * else's.
     *
     * @param  array{0: string, 1: string}  $contact
     * @param  array<int, string>  $emails
     */
    protected function matchEmailToContact(array $contact, array $emails): ?string
    {
        $names = array_values(array_filter([
            $this->emailToken($contact[0]),
            $this->emailToken($contact[1]),
        ]));

        if ($names === []) {
            return null;
        }

        foreach ($emails as $candidate) {
            $localPart = $this->emailToken(Str::before($candidate, '@'));

            if ($localPart === null) {
                continue;
            }

            foreach ($names as $namePart) {
                if (str_contains($localPart, $namePart)) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /** Letters and digits only, lowercased — "Ecker" and "ecker.chris.r" compare. */
    protected function emailToken(?string $value): ?string
    {
        $token = strtolower((string) preg_replace('/[^a-z0-9]+/i', '', (string) $value));

        return $token === '' ? null : $token;
    }

    /**
     * Split a household name into its people.
     *
     *   "Amy Dusto and Chris Ecker" → Amy Dusto + Chris Ecker
     *   "Mark & Gail Brodson"       → Mark Brodson + Gail Brodson
     *   "Zachary Wong"              → Zachary Wong
     *
     * A leading part with no surname of its own inherits the last person's —
     * that's the "Mark & Gail Brodson" shape the CRM already uses for couples.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    public function splitContacts(string $name): array
    {
        $parts = preg_split('/\s*(?:&|\band\b|\+)\s*/i', trim($name)) ?: [];
        $parts = array_values(array_filter(array_map('trim', $parts), fn ($p) => $p !== ''));

        if (count($parts) <= 1) {
            return [$this->splitName($name)];
        }

        $contacts = array_map(fn (string $part) => $this->splitName($part), $parts);

        // Inherit the surname rightwards: only the trailing person carries one
        // in "Mark & Gail Brodson", and splitName() leaves the others blank.
        $surname = '';
        for ($i = count($contacts) - 1; $i >= 0; $i--) {
            if ($contacts[$i][1] !== '') {
                $surname = $contacts[$i][1];

                continue;
            }

            if ($surname !== '') {
                $contacts[$i][1] = $surname;
            }
        }

        return $contacts;
    }

    /**
     * Every distinct 10-digit number in a phone field. Leads routinely carry
     * one per person ("443-333-0697 / 339-222-0798"); normalizePhone() saw the
     * whole string as a single 20-digit number and returned null, which took
     * the WHOLE provisioning run down — no user, no client, nothing.
     *
     * @return array<int, string>
     */
    public function normalizePhones(mixed $phone): array
    {
        if (! is_string($phone) && ! is_numeric($phone)) {
            return [];
        }

        // Split on anything that isn't part of a number's own punctuation, so
        // "(443) 333-0697 / 339.222.0798" yields both.
        $candidates = preg_split('#[/;,]|\bor\b|\band\b#i', (string) $phone) ?: [];

        $out = [];
        foreach ($candidates as $candidate) {
            $normalized = $this->normalizePhone($candidate);
            if ($normalized !== null && ! in_array($normalized, $out, true)) {
                $out[] = $normalized;
            }
        }

        return $out;
    }

    /**
     * @param  array{0: string, 1: string}  $contact  [first name, last name]
     */
    protected function findOrCreateUser(array $contact, ?string $email, ?string $phone): User
    {
        // Match on whatever we actually have. A null email must NOT become
        // LOWER(email) = '' — that would match an unrelated blank-email user.
        $user = User::query()
            ->where(function ($query) use ($email, $phone) {
                if ($phone !== null) {
                    $query->orWhere('cell_phone', $phone);
                }
                if ($email !== null) {
                    $query->orWhereRaw('LOWER(email) = ?', [mb_strtolower($email)]);
                }
            })
            ->first();

        if ($user) {
            // A real address or number learned later replaces a placeholder /
            // fills a blank — never overwrites something already on file.
            if ($email !== null && ! $user->hasRoutableEmail()) {
                $user->forceFill(['email' => $email])->save();
            }
            if ($phone !== null && ! $user->hasRoutablePhone()) {
                $user->forceFill(['cell_phone' => $phone])->save();
            }

            return $user;
        }

        [$firstName, $lastName] = $contact;

        return User::create([
            'first_name' => $firstName,
            'last_name' => $lastName,
            // users.email is NOT NULL UNIQUE and the app mails client users, so
            // a second household member without one gets a deliberately
            // non-routable address (.invalid is reserved by RFC 2606 and fails
            // at DNS) rather than a guessed real address that would reach a
            // stranger. hasRoutableEmail() keeps these out of recipient lists.
            'email' => $email ?? $this->placeholderEmail($firstName, $lastName, $phone),
            // No number given → none recorded. Inventing one would put a
            // number in the CRM that nobody can call and that reads as real;
            // the blank is the signal to fill it in by hand.
            'cell_phone' => $phone,
        ]);
    }

    protected function placeholderEmail(string $firstName, string $lastName, ?string $phone): string
    {
        $slug = trim(strtolower(preg_replace('/[^a-z0-9]+/i', '.', $firstName.' '.$lastName) ?? ''), '.');
        $suffix = $phone ?? substr(bin2hex(random_bytes(4)), 0, 8);

        return trim($slug.'.'.$suffix, '.').'@'.User::PLACEHOLDER_EMAIL_DOMAIN;
    }

    protected function userBelongsToVendor(User $user, int $vendorId): bool
    {
        if ((int) ($user->primary_vendor_id ?? 0) === $vendorId) {
            return true;
        }
        return $user->vendors()->where('vendors.id', $vendorId)->exists();
    }

    /**
     * @param  array<string, string>  $stated  parts the sender gave us verbatim
     */
    protected function findOrCreateClient(User $user, string $address, int $vendorId, array $stated = []): ?Client
    {
        // Stated parts first, then anything the street string itself carries.
        $parsed = $this->parseAddress($address);
        // Only the locality parts: the stated `address` is the raw field
        // parseAddress just derived the street FROM, and it often carries the
        // city/state/zip inline ("5 Oak St, Barrington, IL 60010, USA").
        foreach (['city', 'state', 'zip_code'] as $field) {
            if (! empty($stated[$field])) {
                $parsed[$field] = $stated[$field];
            }
        }

        // Fill whatever is still missing, then insist on the whole thing.
        // geocodeAddress refuses an unanchored street rather than resolving it
        // to the wrong state, so an incomplete answer here means incomplete.
        if (! $this->isCompleteAddress($parsed)) {
            // Only append what the street string doesn't already say, or the
            // query doubles up ("… Palatine, IL, USA, Palatine, IL").
            $street = $parsed['address'] ?? $address;
            $query = collect([$parsed['city'] ?? null, $parsed['state'] ?? null, $parsed['zip_code'] ?? null])
                ->filter()
                ->reject(fn (string $part) => stripos($street, $part) !== false)
                ->prepend($street)
                ->implode(', ');

            $resolved = app(GeoapifyService::class)->geocodeAddress($query);

            if ($resolved !== null) {
                foreach (['city', 'state', 'zip_code'] as $field) {
                    if (empty($parsed[$field])) {
                        $parsed[$field] = $resolved[$field];
                    }
                }
            }
        }

        if (! $this->isCompleteAddress($parsed)) {
            Log::info('Lead contact provisioning: address incomplete, no client created', [
                'address' => $address,
                'parsed' => $parsed,
            ]);

            return null;
        }

        $key = self::normalizeAddressKey($parsed['address']);

        // Look across all clients linked to this user (any vendor).
        foreach ($user->clients()->get() as $candidate) {
            if (self::normalizeAddressKey($candidate->address) === $key) {
                $this->backfillMissingFields($candidate, $parsed);
                return $candidate;
            }
        }

        // Look across vendor's clients (other users may already represent the household).
        $vendorClients = Client::query()
            ->whereHas('vendors', fn ($q) => $q->where('vendors.id', $vendorId))
            ->get();

        foreach ($vendorClients as $candidate) {
            if (self::normalizeAddressKey($candidate->address) !== $key) {
                continue;
            }
            // Only reuse when zip codes don't conflict.
            if ($parsed['zip_code'] && $candidate->zip_code && $parsed['zip_code'] !== $candidate->zip_code) {
                continue;
            }
            $this->backfillMissingFields($candidate, $parsed);
            return $candidate;
        }

        return Client::create([
            'address' => $parsed['address'],
            'city' => $parsed['city'],
            'state' => $parsed['state'],
            'zip_code' => $parsed['zip_code'],
        ]);
    }

    /**
     * @param  array{address: string, city: ?string, state: ?string, zip_code: ?string}  $parsed
     */
    protected function backfillMissingFields(Client $client, array $parsed): void
    {
        $dirty = false;
        foreach (['city', 'state', 'zip_code'] as $field) {
            if (empty($client->{$field}) && ! empty($parsed[$field])) {
                $client->{$field} = $parsed[$field];
                $dirty = true;
            }
        }
        if ($dirty) {
            $client->save();
        }
    }

    /**
     * Normalize a free-form street string to a canonical comparison key.
     * Lowercases, strips punctuation, expands directionals + street suffixes,
     * and drops trailing city/state tokens after the suffix.
     */
    public static function normalizeAddressKey(?string $address): string
    {
        if ($address === null || trim($address) === '') {
            return '';
        }

        $clean = strtolower((string) preg_replace('/[^a-z0-9 ]+/i', ' ', $address));
        $tokens = array_values(array_filter(preg_split('/\s+/', $clean) ?: []));

        if ($tokens === []) {
            return '';
        }

        $out = [];
        $sawSuffix = false;
        foreach ($tokens as $i => $tok) {
            if ($i === 0) {
                $out[] = $tok;
                continue;
            }

            if (isset(self::DIRECTIONS[$tok])) {
                $out[] = self::DIRECTIONS[$tok];
                continue;
            }

            if (isset(self::STREET_SUFFIXES[$tok])) {
                $out[] = self::STREET_SUFFIXES[$tok];
                $sawSuffix = true;
                continue;
            }

            if ($sawSuffix) {
                break;
            }

            $out[] = $tok;
        }

        return implode(' ', $out);
    }

    /**
     * @return array{address: string, city: ?string, state: ?string, zip_code: ?string}
     */
    protected function parseAddress(string $address): array
    {
        $parts = array_values(array_filter(array_map('trim', explode(',', $address)), fn ($p) => $p !== ''));

        if (! empty($parts)) {
            $last = strtolower(end($parts));
            if (in_array($last, ['usa', 'us', 'united states', 'united states of america'], true)) {
                array_pop($parts);
            }
        }

        $street = $parts[0] ?? $address;
        $city = $parts[1] ?? null;
        $state = null;
        $zip = null;

        $stateZip = $parts[2] ?? null;
        if ($stateZip !== null) {
            $stateZipTrimmed = trim($stateZip);
            if (preg_match('/^([A-Za-z]{2})\s*(\d{5}(?:-\d{4})?)?$/', $stateZipTrimmed, $m)) {
                $state = strtoupper($m[1]);
                $zip = $m[2] ?? null;
            } elseif (preg_match('/(\d{5}(?:-\d{4})?)$/', $stateZipTrimmed, $m)) {
                $zip = $m[1];
                $state = $this->resolveState(trim(str_replace($m[1], '', $stateZipTrimmed)));
            } else {
                $state = $this->resolveState($stateZipTrimmed);
            }
        }

        // Fallback for single-segment inputs like "873 Buttonwood Circle Naperville, Il".
        if ($zip === null && preg_match('/(\d{5}(?:-\d{4})?)/', $address, $m)) {
            $zip = $m[1];
        }

        if ($state === null) {
            if (preg_match('/\b(IL|IN|IA|WI|MI|MO|MN|KY)\b/i', $address, $m)) {
                $state = strtoupper($m[1]);
            } else {
                foreach (self::STATE_NAMES as $name => $abbr) {
                    if (stripos($address, $name) !== false) {
                        $state = $abbr;
                        break;
                    }
                }
            }
        }

        if ($city === null) {
            [$street, $city] = $this->splitTrailingCity($street);
        } else {
            $cityClean = trim((string) preg_replace('/\b(IL|IN|IA|WI|MI|MO|MN|KY)\b/i', '', $city));
            // "Chicago 60660" — people write the ZIP straight after the city,
            // with no state between. Keep it out of the city name.
            if (preg_match('/^(.*?)[\s,]+(\d{5}(?:-\d{4})?)$/', trim($cityClean), $m)) {
                $cityClean = $m[1];
                $zip = $zip ?? $m[2];
            }
            $cityClean = trim($cityClean, " \t\n\r\0\x0B,");
            $city = $cityClean !== '' ? $cityClean : null;
        }

        return [
            'address' => trim($street),
            'city' => $city ? $this->titleCase($city) : null,
            'state' => $state,
            'zip_code' => $zip,
        ];
    }

    /**
     * @return array{0: string, 1: ?string}
     */
    protected function splitTrailingCity(string $street): array
    {
        $tokens = preg_split('/\s+/', trim($street)) ?: [];
        if (count($tokens) < 3) {
            return [$street, null];
        }

        $suffixIndex = null;
        foreach ($tokens as $i => $tok) {
            if ($i === 0) {
                continue;
            }
            if (isset(self::STREET_SUFFIXES[strtolower($tok)])) {
                $suffixIndex = $i;
            }
        }

        if ($suffixIndex === null || $suffixIndex >= count($tokens) - 1) {
            return [$street, null];
        }

        $streetTokens = array_slice($tokens, 0, $suffixIndex + 1);
        $cityTokens = array_slice($tokens, $suffixIndex + 1);

        $cityTokens = array_filter($cityTokens, function ($t) {
            $lt = strtolower($t);
            return ! in_array($lt, ['il', 'in', 'ia', 'wi', 'mi', 'mo', 'mn', 'ky'], true)
                && ! isset(self::STATE_NAMES[$lt]);
        });

        return [
            implode(' ', $streetTokens),
            $cityTokens ? implode(' ', $cityTokens) : null,
        ];
    }

    protected function resolveState(?string $input): ?string
    {
        if ($input === null) {
            return null;
        }
        $clean = strtolower(trim($input));
        if ($clean === '') {
            return null;
        }
        if (preg_match('/^[a-z]{2}$/', $clean)) {
            return strtoupper($clean);
        }
        return self::STATE_NAMES[$clean] ?? null;
    }

    protected function titleCase(string $value): string
    {
        return mb_convert_case(trim($value), MB_CASE_TITLE, 'UTF-8');
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function splitName(string $name): array
    {
        $clean = preg_replace('/\s+/', ' ', trim($name)) ?? '';
        $parts = explode(' ', $clean);

        if (count($parts) === 1) {
            return [$parts[0], ''];
        }

        $last = array_pop($parts);

        return [implode(' ', $parts), $last];
    }

    protected function normalizePhone(mixed $phone): ?string
    {
        if (! is_string($phone) && ! is_numeric($phone)) {
            return null;
        }

        $digits = preg_replace('/\D/', '', (string) $phone);

        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            $digits = substr($digits, 1);
        }

        return strlen($digits) === 10 ? $digits : null;
    }

    protected function stringValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
