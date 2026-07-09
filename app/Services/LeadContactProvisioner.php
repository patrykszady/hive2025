<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Support\Facades\DB;

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
        $phone = $this->normalizePhone($data['phone'] ?? null);
        $address = $this->stringValue($data['address'] ?? null);

        if ($name === null || $email === null || $phone === null) {
            return;
        }

        DB::transaction(function () use ($lead, $name, $email, $phone, $address) {
            $user = $this->findOrCreateUser($name, $email, $phone);

            if ($lead->user_id !== $user->id) {
                $lead->user_id = $user->id;
                $lead->saveQuietly();
            }

            if ($address === null) {
                return;
            }

            $vendorId = (int) $lead->belongs_to_vendor_id;

            // Skip client provisioning when the resolved user is staff of this
            // vendor — these are self-submissions / test leads, not customers.
            if ($this->userBelongsToVendor($user, $vendorId)) {
                return;
            }

            $client = $this->findOrCreateClient($user, $address, $vendorId);

            if (! $user->clients()->where('clients.id', $client->id)->exists()) {
                $user->clients()->attach($client->id);
            }

            if (! $client->vendors()->where('vendors.id', $vendorId)->exists()) {
                $client->vendors()->attach($vendorId, ['source' => $lead->origin]);
            }
        });
    }

    protected function findOrCreateUser(string $name, string $email, string $phone): User
    {
        $user = User::query()
            ->where('cell_phone', $phone)
            ->orWhereRaw('LOWER(email) = ?', [mb_strtolower($email)])
            ->first();

        if ($user) {
            return $user;
        }

        [$firstName, $lastName] = $this->splitName($name);

        return User::create([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'cell_phone' => $phone,
        ]);
    }

    protected function userBelongsToVendor(User $user, int $vendorId): bool
    {
        if ((int) ($user->primary_vendor_id ?? 0) === $vendorId) {
            return true;
        }
        return $user->vendors()->where('vendors.id', $vendorId)->exists();
    }

    protected function findOrCreateClient(User $user, string $address, int $vendorId): Client
    {
        $parsed = $this->parseAddress($address);

        // Leads from the marketing site arrive without a ZIP ("104 N Plum
        // Grove Rd, Palatine, IL, USA") — geocode the full address to fill it
        // so the client record is complete from day one. Only when a city or
        // state anchors the lookup: a bare street would geocode anywhere.
        if (empty($parsed['zip_code']) && ($parsed['city'] || $parsed['state'])) {
            $parsed['zip_code'] = app(GeoapifyService::class)->lookupZipCode($address);
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
