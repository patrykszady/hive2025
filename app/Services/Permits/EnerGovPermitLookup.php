<?php

namespace App\Services\Permits;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Asks a Tyler EnerGov Citizen Self Service portal (Palatine's
 * permits.palatine.il.us) which house a permit number was issued for. The
 * request is the portal's own global search — its front end's exact JSON,
 * kept in resources/permits/energov-global-search.json, with the permit
 * number as the keyword — because the API answers 500 to anything slimmer.
 */
class EnerGovPermitLookup
{
    public const TEMPLATE = 'permits/energov-global-search.json';

    /**
     * The portal's answer, or null when it could not be reached (nothing is
     * remembered then, so the next run asks again).
     *
     * @param  array{base_url: string, tenant: string, tenant_id: string}  $portal
     * @return array{found: bool, address: ?string, city: ?string, state: ?string, zip: ?string, status: ?string, case_type: ?string, description: ?string, raw: ?array<string, mixed>}|null
     */
    public function lookup(array $portal, string $permitNumber): ?array
    {
        $request = json_decode((string) file_get_contents(resource_path(self::TEMPLATE)), true);
        $request['Keyword'] = $permitNumber;

        try {
            $response = Http::withHeaders([
                'tenantid' => (string) $portal['tenant_id'],
                'tenantname' => (string) $portal['tenant'],
                'tyler-tenanturl' => (string) $portal['tenant'],
                'tyler-tenant-culture' => 'en-US',
                'Accept' => 'application/json, text/plain, */*',
            ])
                ->withUserAgent('Mozilla/5.0 (compatible; hive.contractors permit lookup)')
                ->timeout((int) config('permit_portals.timeout', 20))
                ->post(rtrim((string) $portal['base_url'], '/').'/api/energov/search/search', $request);
        } catch (\Throwable $e) {
            Log::warning('EnerGovPermitLookup: portal unreachable', ['portal' => $portal['base_url'], 'permit' => $permitNumber, 'error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful() || ! $response->json('Success')) {
            Log::warning('EnerGovPermitLookup: portal answered badly', ['portal' => $portal['base_url'], 'permit' => $permitNumber, 'status' => $response->status(), 'body' => Str::limit((string) $response->body(), 200)]);

            return null;
        }

        $hit = collect((array) $response->json('Result.EntityResults', []))
            ->first(fn ($row) => is_array($row) && strcasecmp((string) ($row['CaseNumber'] ?? ''), $permitNumber) === 0);

        if (! $hit) {
            return ['found' => false, 'address' => null, 'city' => null, 'state' => null, 'zip' => null, 'status' => null, 'case_type' => null, 'description' => null, 'raw' => null];
        }

        $address = (array) ($hit['Address'] ?? []);
        $street = Str::squish(implode(' ', [
            (string) ($address['AddressLine1'] ?? ''),
            (string) ($address['PreDirection'] ?? ''),
            (string) ($address['AddressLine2'] ?? ''),
            (string) ($address['StreetTypeName'] ?? ''),
            (string) ($address['PostDirection'] ?? ''),
            (string) ($address['UnitOrSuite'] ?? ''),
        ]));

        return [
            'found' => true,
            'address' => $street !== '' ? self::titleCase($street) : null,
            'city' => self::text($address['City'] ?? null),
            'state' => self::text($address['StateName'] ?? null),
            'zip' => self::text($address['PostalCode'] ?? null),
            'status' => self::text($hit['CaseStatus'] ?? null),
            'case_type' => self::text($hit['CaseType'] ?? null),
            'description' => self::text($hit['Description'] ?? null),
            'raw' => $hit,
        ];
    }

    /** "116 N BENTON ST" → "116 N Benton St", the way a project address is written. */
    public static function titleCase(string $street): string
    {
        return ucwords(strtolower($street));
    }

    protected static function text(mixed $value): ?string
    {
        $text = is_string($value) ? Str::squish($value) : '';

        return $text !== '' ? $text : null;
    }
}
