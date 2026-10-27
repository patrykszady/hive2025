<?php

namespace App\Support\Permits;

use App\Jobs\Permits\FindVillageCodes;
use App\Models\Project;
use App\Models\VillageCodes;
use App\Services\GeoapifyService;
use Illuminate\Support\Str;

/**
 * The codes a permit cover letter lists for the village or city that issues
 * the permit — what each has adopted, as its own building department
 * publishes it. Towns are read off their own websites into village_codes
 * (App\Services\Permits\VillageCodeFinder, looked up when first needed and
 * re-read monthly); the two lists typed in here on 2026-10-26 seed that
 * table and stand in until it has them. A town nobody has found yet gets the
 * list GS's cover letter template carried, and the lines are editable in the
 * Permit Packet dialog either way.
 *
 * The town is suggested from the job's address, not its mailing city: 123
 * Villa Circle Dr has a Palatine 60067 mailing address and sits in the City
 * of Rolling Meadows, whose codes are two editions newer.
 */
class BuildingCodes
{
    /**
     * @var array<string, array{source: string, codes: list<string>}>
     */
    public const JURISDICTIONS = [
        // https://www.cityrm.org/149/Building-Codes — "For permits issued after
        // 1/1/2026, the following Codes will apply". The city's notice lists
        // the Mechanical Code as the 2025 edition; kept as the city writes it.
        'Rolling Meadows' => [
            'source' => 'https://www.cityrm.org/149/Building-Codes',
            'codes' => [
                'International Residential Code, 2024 Edition',
                'International Building Code, 2024 Edition',
                'International Existing Building Code, 2024 Edition',
                'International Mechanical Code, 2025 Edition',
                'International Fuel Gas Code, 2024 Edition',
                'International Fire Code, 2024 Edition',
                'National Electrical Code, 2023 Edition',
                'Illinois Plumbing Code, current Edition (77 Ill. Adm. Code 890)',
                'Illinois Energy Conservation Code, 2024 Edition',
                'Illinois Accessibility Code, 2018 Edition',
            ],
        ],
        // https://www.palatine.il.us/206/Building-Code-Information
        'Palatine' => [
            'source' => 'https://www.palatine.il.us/206/Building-Code-Information',
            'codes' => [
                'International Residential Code, 2021 Edition',
                'International Building Code, 2021 Edition',
                'International Existing Building Code, 2021 Edition',
                'International Mechanical Code, 2021 Edition',
                'International Fuel Gas Code, 2021 Edition',
                'International Fire Code, 2021 Edition',
                'National Electrical Code, 2020 Edition',
                'Illinois Plumbing Code, current Edition (77 Ill. Adm. Code 890)',
                'Illinois Energy Conservation Code, 2024 Edition',
                'Illinois Accessibility Code, 2018 Edition',
            ],
        ],
    ];

    /** The list GS's cover letter template carried before towns were looked up. @var list<string> */
    public const DEFAULT_CODES = [
        'International Residential Code, 2021 Edition',
        'International Building Code, 2021 Edition',
        'International Mechanical Code, 2021 Edition',
        'International Fuel Gas Code, 2021 Edition',
        'Illinois Energy Conservation Code, 2024 Edition',
        'International Existing Building Code, 2021 Edition',
        'International Fire Code, 2021 Edition',
        'National Electrical Code, 2020 Edition',
        'Illinois Plumbing Code, 2014 Edition',
    ];

    /** @var array<string, VillageCodes|null> */
    protected static array $rows = [];

    /** @return list<string> */
    public static function jurisdictions(): array
    {
        return array_keys(self::JURISDICTIONS);
    }

    /** Forget the per-request row cache (VillageCodes clears it on every save). */
    public static function forget(): void
    {
        self::$rows = [];
    }

    /** The town's row in village_codes, whatever its status, or null when nobody has looked it up. */
    public static function row(?string $jurisdiction, string $state = 'IL'): ?VillageCodes
    {
        $name = VillageCodes::normalizeTown($jurisdiction);
        if ($name === '') {
            return null;
        }

        $key = Str::lower($name).'|'.$state;
        if (! array_key_exists($key, self::$rows)) {
            self::$rows[$key] = VillageCodes::query()->named($name, $state)->first();
        }

        return self::$rows[$key];
    }

    /** A town whose codes are on file: read off its website, or typed in here. */
    public static function isKnown(?string $jurisdiction): bool
    {
        return self::row($jurisdiction)?->isFound() || self::registryName($jurisdiction) !== null;
    }

    /** The town's name as it is on file ("rolling meadows" → "Rolling Meadows"), or null when it is not. */
    public static function match(?string $jurisdiction): ?string
    {
        return self::registryName($jurisdiction) ?? self::row($jurisdiction)?->town;
    }

    protected static function registryName(?string $jurisdiction): ?string
    {
        $needle = Str::lower(VillageCodes::normalizeTown($jurisdiction));

        foreach (self::jurisdictions() as $name) {
            if (Str::lower($name) === $needle) {
                return $name;
            }
        }

        return null;
    }

    /**
     * The CODES block for a town: what its website lists, else what was typed
     * in here, else the template's list.
     *
     * @return list<string>
     */
    public static function for(?string $jurisdiction): array
    {
        $row = self::row($jurisdiction);
        if ($row?->isFound()) {
            return array_values($row->codes);
        }

        $name = self::registryName($jurisdiction);

        return $name ? self::JURISDICTIONS[$name]['codes'] : self::DEFAULT_CODES;
    }

    public static function sourceFor(?string $jurisdiction): ?string
    {
        $row = self::row($jurisdiction);
        if ($row?->isFound() && $row->source_url) {
            return $row->source_url;
        }

        $name = self::registryName($jurisdiction);

        return $name ? self::JURISDICTIONS[$name]['source'] : null;
    }

    /**
     * Make sure a town is being looked up: its row exists, and a search runs
     * in the background the first time. Towns on file are left alone.
     */
    public static function lookup(string $jurisdiction, string $state = 'IL'): ?VillageCodes
    {
        $name = VillageCodes::normalizeTown($jurisdiction);
        if ($name === '' || self::registryName($name) !== null) {
            return self::row($name, $state);
        }

        $row = self::row($name, $state);
        if (! $row) {
            $row = VillageCodes::query()->create(['town' => $name, 'state' => $state, 'status' => VillageCodes::STATUS_PENDING, 'found_by' => VillageCodes::FOUND_BY_AI]);
            FindVillageCodes::dispatch($name, $state);
        }

        return $row;
    }

    /**
     * The towns a permit for this job could come from: where the address
     * geocodes to, and the mailing city. Usually one and the same; when they
     * differ (123 Villa Circle: the lot is in Rolling Meadows, the mail says
     * Palatine) both come back, the geocoded town first, and the dialog asks
     * which one. Known towns come back under their registry name.
     *
     * @return list<string>
     */
    public static function townsFor(Project $project): array
    {
        $towns = [];

        $street = trim((string) $project->address);
        $zip = trim((string) $project->zip_code);
        if ($street !== '' && $zip !== '') {
            try {
                $found = app(GeoapifyService::class)->geocodeAddress(trim($street.', '.($project->state ?: 'IL').' '.$zip));
                $city = is_array($found) ? trim((string) ($found['city'] ?? '')) : '';
                if ($city !== '') {
                    $towns[] = self::match($city) ?? $city;
                }
            } catch (\Throwable) {
                // The project's own city stands.
            }
        }

        $mailing = trim((string) $project->city);
        if ($mailing !== '') {
            $towns[] = self::match($mailing) ?? $mailing;
        }

        $distinct = [];
        foreach ($towns as $town) {
            if (! in_array(Str::lower($town), array_map([Str::class, 'lower'], $distinct), true)) {
                $distinct[] = $town;
            }
        }

        return $distinct;
    }
}
