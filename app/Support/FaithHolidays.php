<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use IntlCalendar;

/**
 * The religious holidays a homeowner may not want a crew in the house on,
 * for the faiths picked on the project timeline. Easter comes from the
 * calendar extension, Jewish and Muslim days from ICU's Hebrew and Umm
 * al-Qura calendars, and Diwali (lunisolar, no ICU calendar) from a table.
 *
 * Each day says whether the crew works it by default: the main holy days do
 * not (works_by_default false), the observances around them do.
 */
final class FaithHolidays
{
    /** @var array<string, string> */
    public const FAITHS = [
        'christian' => 'Christian',
        'orthodox' => 'Orthodox (Greek, Serbian, Ukrainian…)',
        'jewish' => 'Jewish',
        'muslim' => 'Muslim',
        'hindu' => 'Hindu',
    ];

    /** Diwali (Lakshmi Puja) as observed in the US; extend as years are published. */
    private const DIWALI = [
        2025 => '2025-10-20',
        2026 => '2026-11-08',
        2027 => '2027-10-29',
        2028 => '2028-10-17',
        2029 => '2029-11-05',
    ];

    private const SUNDOWN = 'Begins at sundown the evening before.';

    private const MOON = 'May shift a day with the moon sighting.';

    /**
     * @param  list<string>  $faiths
     * @return list<array{date: string, name: string, kind: string, faith: string, observed: bool, works_by_default: bool, note: ?string}>
     */
    public static function forYear(int $year, array $faiths): array
    {
        $holidays = [];

        foreach (array_intersect(array_keys(self::FAITHS), $faiths) as $faith) {
            $days = match ($faith) {
                'christian' => self::christian($year),
                'orthodox' => self::orthodox($year),
                'jewish' => self::jewish($year),
                'muslim' => self::muslim($year),
                'hindu' => self::hindu($year),
            };

            foreach ($days as [$date, $name, $worksByDefault, $note]) {
                if ($date->year === $year) {
                    $holidays[] = [
                        'date' => $date->toDateString(),
                        'name' => $name,
                        'kind' => ConstructionHolidays::FAITH,
                        'faith' => $faith,
                        'observed' => false,
                        'works_by_default' => $worksByDefault,
                        'note' => $note,
                    ];
                }
            }
        }

        usort($holidays, fn (array $a, array $b) => $a['date'] <=> $b['date']);

        return $holidays;
    }

    /**
     * The picked faiths' holidays from $start to $finish, inclusive.
     *
     * @param  list<string>  $faiths
     * @return list<array{date: string, name: string, kind: string, faith: string, observed: bool, works_by_default: bool, note: ?string}>
     */
    public static function between(CarbonImmutable $start, CarbonImmutable $finish, array $faiths): array
    {
        $from = $start->toDateString();
        $to = $finish->toDateString();
        $found = [];

        for ($year = $start->year; $year <= $finish->year; $year++) {
            foreach (self::forYear($year, $faiths) as $holiday) {
                if ($holiday['date'] >= $from && $holiday['date'] <= $to) {
                    $found[] = $holiday;
                }
            }
        }

        return $found;
    }

    /** Western Easter Sunday. */
    public static function easter(int $year): CarbonImmutable
    {
        return CarbonImmutable::create($year, 3, 21)->addDays(easter_days($year));
    }

    /** Orthodox Easter Sunday: the Julian Easter moved onto the Gregorian calendar (13 days apart until 2100). */
    public static function orthodoxEaster(int $year): CarbonImmutable
    {
        return CarbonImmutable::create($year, 3, 21)->addDays(easter_days($year, CAL_EASTER_ALWAYS_JULIAN) + 13);
    }

    /** @return list<array{CarbonImmutable, string, bool, ?string}> */
    private static function christian(int $year): array
    {
        $easter = self::easter($year);

        return [
            [$easter->subDays(46), 'Ash Wednesday', true, null],
            [$easter->subDays(2), 'Good Friday', false, null],
            [$easter, 'Easter', false, null],
            [$easter->addDay(), 'Easter Monday', true, null],
        ];
    }

    /** @return list<array{CarbonImmutable, string, bool, ?string}> */
    private static function orthodox(int $year): array
    {
        $easter = self::orthodoxEaster($year);

        return [
            [CarbonImmutable::create($year, 1, 6), 'Orthodox Christmas Eve', false, null],
            [CarbonImmutable::create($year, 1, 7), 'Orthodox Christmas', false, null],
            [$easter->subDays(2), 'Orthodox Good Friday', false, null],
            [$easter, 'Orthodox Easter', false, null],
        ];
    }

    /** @return list<array{CarbonImmutable, string, bool, ?string}> */
    private static function jewish(int $year): array
    {
        $days = [];

        // The spring holidays belong to Hebrew year $year + 3760, the autumn ones to $year + 3761.
        foreach ([$year + 3760, $year + 3761] as $hebrewYear) {
            $day = fn (int $month, int $dayOfMonth): CarbonImmutable => self::fromIcu('hebrew', $hebrewYear, $month, $dayOfMonth);

            array_push($days,
                [$day(6, 14), 'Purim', true, self::SUNDOWN],
                [$day(7, 15), 'Passover (day 1)', false, self::SUNDOWN],
                [$day(7, 16), 'Passover (day 2)', false, null],
                [$day(7, 21), 'Passover (day 7)', false, self::SUNDOWN],
                [$day(7, 22), 'Passover (day 8)', false, null],
                [$day(9, 6), 'Shavuot (day 1)', false, self::SUNDOWN],
                [$day(9, 7), 'Shavuot (day 2)', false, null],
                [$day(0, 1), 'Rosh Hashanah (day 1)', false, self::SUNDOWN],
                [$day(0, 2), 'Rosh Hashanah (day 2)', false, null],
                [$day(0, 10), 'Yom Kippur', false, self::SUNDOWN],
                [$day(0, 15), 'Sukkot (day 1)', false, self::SUNDOWN],
                [$day(0, 16), 'Sukkot (day 2)', false, null],
                [$day(0, 22), 'Shemini Atzeret', false, self::SUNDOWN],
                [$day(0, 23), 'Simchat Torah', false, null],
                [$day(2, 25), 'Hanukkah begins', true, self::SUNDOWN],
            );
        }

        return $days;
    }

    /** @return list<array{CarbonImmutable, string, bool, ?string}> */
    private static function muslim(int $year): array
    {
        $days = [];
        $hijri = (int) floor(($year - 622) * 33 / 32);

        // The Islamic year is eleven days shorter, so a Gregorian year can hold a holiday twice.
        for ($hijriYear = $hijri - 1; $hijriYear <= $hijri + 2; $hijriYear++) {
            array_push($days,
                [self::fromIcu('islamic-umalqura', $hijriYear, 8, 1), 'Ramadan begins', true, self::MOON],
                [self::fromIcu('islamic-umalqura', $hijriYear, 9, 1), 'Eid al-Fitr', false, self::MOON],
                [self::fromIcu('islamic-umalqura', $hijriYear, 11, 10), 'Eid al-Adha', false, self::MOON],
            );
        }

        return $days;
    }

    /** @return list<array{CarbonImmutable, string, bool, ?string}> */
    private static function hindu(int $year): array
    {
        return isset(self::DIWALI[$year])
            ? [[CarbonImmutable::parse(self::DIWALI[$year]), 'Diwali', false, null]]
            : [];
    }

    /** A day of an ICU calendar ($month is ICU's zero-based month), as a Gregorian date. */
    private static function fromIcu(string $calendar, int $year, int $month, int $day): CarbonImmutable
    {
        $icu = IntlCalendar::createInstance('UTC', 'en_US@calendar='.$calendar);
        $icu->clear();
        $icu->set($year, $month, $day);

        return CarbonImmutable::createFromTimestampUTC(intdiv((int) $icu->getTime(), 1000))->startOfDay();
    }
}
