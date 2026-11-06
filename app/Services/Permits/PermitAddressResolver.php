<?php

namespace App\Services\Permits;

use App\Models\PermitLookup;
use Illuminate\Support\Str;

/**
 * Which house a permit number on a village's receipt belongs to. The village
 * is read from the expense's vendor ("Village of Palatine" → the Palatine
 * portal in config/permit_portals.php), the portal is asked once per number
 * and its answer kept in permit_lookups, so the expense matcher can treat the
 * permit's address like one written on the receipt.
 */
class PermitAddressResolver
{
    /** "BLDR-026546-2026", "ELEC-001234-2026" — a prefix, a serial, the year. */
    public const PERMIT_PATTERN = '/\b([A-Z]{2,6}-\d{4,8}-\d{4})\b/';

    public function __construct(protected EnerGovPermitLookup $energov) {}

    /**
     * The permit numbers written in a receipt.
     *
     * @return list<string>
     */
    public static function permitNumbers(string $text): array
    {
        preg_match_all(self::PERMIT_PATTERN, $text, $matches);

        return array_values(array_unique($matches[1] ?? []));
    }

    /**
     * The portal a vendor's permits can be looked up on, or null when the
     * vendor is not a village hive knows how to ask.
     *
     * @return array{key: string, name: string, driver: string, base_url: string, tenant: string, tenant_id: string, vendor_match: list<string>}|null
     */
    public static function portalForVendor(?string $vendorName): ?array
    {
        $name = Str::lower((string) $vendorName);
        if ($name === '') {
            return null;
        }

        foreach ((array) config('permit_portals.portals', []) as $key => $portal) {
            $words = (array) ($portal['vendor_match'] ?? []);
            if ($words !== [] && collect($words)->every(fn ($word) => str_contains($name, Str::lower((string) $word)))) {
                return ['key' => (string) $key] + $portal;
            }
        }

        return null;
    }

    /**
     * What the vendor's portal knows about the permit: the kept answer, or a
     * fresh one when there is none yet (or the last one was a miss a week
     * ago). Null when the vendor has no portal or the portal was unreachable.
     */
    public function resolve(?string $vendorName, string $permitNumber): ?PermitLookup
    {
        $portal = self::portalForVendor($vendorName);
        if (! $portal) {
            return null;
        }

        $permitNumber = strtoupper(trim($permitNumber));
        $known = PermitLookup::query()->where('portal', $portal['key'])->where('permit_number', $permitNumber)->first();
        if ($known && ! $known->isStaleMiss()) {
            return $known;
        }

        $answer = match ($portal['driver'] ?? 'energov') {
            'energov' => $this->energov->lookup($portal, $permitNumber),
            default => null,
        };
        if ($answer === null) {
            return $known;
        }

        return PermitLookup::updateOrCreate(
            ['portal' => $portal['key'], 'permit_number' => $permitNumber],
            $answer + ['looked_up_at' => now()],
        );
    }
}
