<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * The holidays a remodel schedule has to work around. Two kinds here (the
 * homeowner's religious holidays, the third kind, are FaithHolidays):
 *
 * - crew off: the days the crew does not work (New Year's Day, Memorial
 *   Day, July 4th, Labor Day, Thanksgiving and the Friday after, Christmas
 *   Eve and Day, New Year's Eve). A fixed-date one that lands on a weekend
 *   is taken on the nearest weekday instead (observed).
 * - offices closed: federal and Illinois holidays the crew works, but permit
 *   offices and inspectors do not (MLK Day, Lincoln's Birthday, Presidents'
 *   Day, Juneteenth, Columbus Day, Illinois' general Election Day in even
 *   years, Veterans Day) — no inspections that day — and Casimir Pulaski Day.
 */
final class ConstructionHolidays
{
    public const CREW_OFF = 'crew_off';

    public const OFFICES_CLOSED = 'offices_closed';

    public const FAITH = 'faith';

    private const OFFICES_NOTE = 'Village offices are closed: no permits or inspections.';

    private const STATE_NOTE = 'Illinois state offices are closed: no permits or inspections.';

    /**
     * @return list<array{date: string, name: string, kind: string, faith: null, observed: bool, works_by_default: bool, note: ?string}>
     */
    public static function forYear(int $year): array
    {
        $holidays = [];
        $add = function (CarbonImmutable $date, string $name, string $kind, bool $observeOnWeekday = false, ?string $note = null) use (&$holidays): void {
            $holiday = [
                'date' => $date->toDateString(),
                'name' => $name,
                'kind' => $kind,
                'faith' => null,
                'observed' => false,
                'works_by_default' => $kind === self::OFFICES_CLOSED,
                'note' => $note ?? ($kind === self::OFFICES_CLOSED ? self::OFFICES_NOTE : null),
            ];
            $holidays[] = $holiday;

            if ($observeOnWeekday && $date->isWeekend()) {
                $weekday = $date->isSaturday() ? $date->subDay() : $date->addDay();
                $holidays[] = ['date' => $weekday->toDateString(), 'name' => $name.' (observed)', 'observed' => true] + $holiday;
            }
        };

        $add(CarbonImmutable::create($year, 1, 1), "New Year's Day", self::CREW_OFF, true);
        $add(self::nthWeekday($year, 1, CarbonImmutable::MONDAY, 3), 'Martin Luther King Jr. Day', self::OFFICES_CLOSED);
        $add(CarbonImmutable::create($year, 2, 12), "Lincoln's Birthday", self::OFFICES_CLOSED, true, self::STATE_NOTE);
        $add(self::nthWeekday($year, 2, CarbonImmutable::MONDAY, 3), "Presidents' Day", self::OFFICES_CLOSED);
        $add(self::nthWeekday($year, 3, CarbonImmutable::MONDAY, 1), 'Casimir Pulaski Day', self::OFFICES_CLOSED, false, 'Illinois commemorative day: some schools close.');
        $add(self::lastWeekday($year, 5, CarbonImmutable::MONDAY), 'Memorial Day', self::CREW_OFF);
        $add(CarbonImmutable::create($year, 6, 19), 'Juneteenth', self::OFFICES_CLOSED, true);
        $add(CarbonImmutable::create($year, 7, 4), 'Independence Day', self::CREW_OFF, true);
        $add(self::nthWeekday($year, 9, CarbonImmutable::MONDAY, 1), 'Labor Day', self::CREW_OFF);
        $add(self::nthWeekday($year, 10, CarbonImmutable::MONDAY, 2), 'Columbus Day', self::OFFICES_CLOSED);
        if ($year % 2 === 0) {
            $add(self::nthWeekday($year, 11, CarbonImmutable::MONDAY, 1)->addDay(), 'Election Day', self::OFFICES_CLOSED, false, 'Illinois general election: state offices are closed.');
        }
        $add(CarbonImmutable::create($year, 11, 11), 'Veterans Day', self::OFFICES_CLOSED, true);
        $thanksgiving = self::nthWeekday($year, 11, CarbonImmutable::THURSDAY, 4);
        $add($thanksgiving, 'Thanksgiving', self::CREW_OFF);
        $add($thanksgiving->addDay(), 'Day after Thanksgiving', self::CREW_OFF);
        $add(CarbonImmutable::create($year, 12, 24), 'Christmas Eve', self::CREW_OFF);
        $add(CarbonImmutable::create($year, 12, 25), 'Christmas Day', self::CREW_OFF, true);
        $add(CarbonImmutable::create($year, 12, 31), "New Year's Eve", self::CREW_OFF);

        usort($holidays, fn (array $a, array $b) => $a['date'] <=> $b['date']);

        return $holidays;
    }

    /**
     * Every holiday from $start to $finish, inclusive, in date order.
     *
     * @return list<array{date: string, name: string, kind: string, faith: null, observed: bool, works_by_default: bool, note: ?string}>
     */
    public static function between(CarbonImmutable $start, CarbonImmutable $finish): array
    {
        $from = $start->toDateString();
        $to = $finish->toDateString();
        $found = [];

        // A year's observed day can fall in the year before (New Year's Day
        // on a Saturday is taken on December 31st), so read one year around.
        for ($year = $start->year - 1; $year <= $finish->year + 1; $year++) {
            foreach (self::forYear($year) as $holiday) {
                if ($holiday['date'] >= $from && $holiday['date'] <= $to) {
                    $found[] = $holiday;
                }
            }
        }

        usort($found, fn (array $a, array $b) => $a['date'] <=> $b['date']);

        return $found;
    }

    /**
     * GS Construction's limited days from $start to $finish: Y-m-d strings.
     * See limitedDayReasons() for which.
     *
     * @return list<string>
     */
    public static function limitedDays(CarbonImmutable $start, CarbonImmutable $finish, bool $thanksgivingWeek = true, bool $christmasWeek = true, bool $bridgeDays = true, bool $easterDays = true, bool $orthodoxDays = true, bool $dayBeforeChristmasEve = true): array
    {
        return array_keys(self::limitedDayReasons($start, $finish, $thanksgivingWeek, $christmasWeek, $bridgeDays, $easterDays, $orthodoxDays, $dayBeforeChristmasEve));
    }

    /**
     * GS Construction's limited days — the crew is thin, not gone, so they
     * still count as working days — from $start to $finish, each with why:
     *
     * - about a week "off" around Thanksgiving (Monday to Sunday of its week)
     *   and from Christmas Eve — for GS the day before it, Dec 23 — to New
     *   Year's Day. A lone weekday left between that run and a weekend joins
     *   it — nobody comes back for Friday Jan 2 after a Thursday New Year's
     *   (2026), nor works the Monday before a run starting on a Tuesday.
     * - Easter: Holy Thursday, Good Friday and Easter Monday.
     * - the Ukrainian crew's Orthodox holidays, on the Julian calendar they
     *   keep: Christmas Eve and Christmas (Jan 6–7), Theophany (Jan 19), and
     *   Good Friday, Easter Monday and Trinity Monday from Orthodox Easter.
     *
     * Each set, and the bridging, is a company setting (TimelineSettings).
     *
     * @return array<string, string> Y-m-d => why, in date order
     */
    public static function limitedDayReasons(CarbonImmutable $start, CarbonImmutable $finish, bool $thanksgivingWeek = true, bool $christmasWeek = true, bool $bridgeDays = true, bool $easterDays = true, bool $orthodoxDays = true, bool $dayBeforeChristmasEve = true): array
    {
        $from = $start->toDateString();
        $to = $finish->toDateString();
        $days = [];
        $add = function (CarbonImmutable $first, CarbonImmutable $last, string $why) use (&$days, $from, $to): void {
            for ($day = $first; $day->lte($last); $day = $day->addDay()) {
                $date = $day->toDateString();

                if ($date >= $from && $date <= $to) {
                    $days[$date] ??= $why;
                }
            }
        };

        for ($year = $start->year - 1; $year <= $finish->year; $year++) {
            if ($thanksgivingWeek) {
                $thanksgiving = self::nthWeekday($year, 11, CarbonImmutable::THURSDAY, 4);
                $add($thanksgiving->subDays(3), $thanksgiving->addDays(3), 'Thanksgiving week');
            }

            if ($christmasWeek) {
                $first = CarbonImmutable::create($year, 12, $dayBeforeChristmasEve ? 23 : 24);
                $newYear = CarbonImmutable::create($year + 1, 1, 1);
                $add($bridgeDays ? self::bridgedBack($first) : $first, $bridgeDays ? self::bridgedForward($newYear) : $newYear, 'Christmas to New Year');
            }

            if ($easterDays) {
                $easter = FaithHolidays::easter($year);
                $add($easter->subDays(3), $easter->subDays(3), 'Holy Thursday');
                $add($easter->subDays(2), $easter->subDays(2), 'Good Friday');
                $add($easter->addDay(), $easter->addDay(), 'Easter Monday');
            }

            if ($orthodoxDays) {
                $orthodoxEaster = FaithHolidays::orthodoxEaster($year);
                foreach ([
                    'Orthodox Christmas Eve' => CarbonImmutable::create($year, 1, 6),
                    'Orthodox Christmas' => CarbonImmutable::create($year, 1, 7),
                    'Theophany' => CarbonImmutable::create($year, 1, 19),
                    'Orthodox Good Friday' => $orthodoxEaster->subDays(2),
                    'Orthodox Easter Monday' => $orthodoxEaster->addDay(),
                    'Trinity Monday' => $orthodoxEaster->addDays(50),
                ] as $why => $day) {
                    $add($day, $day, $why);
                }
            }
        }

        ksort($days);

        return $days;
    }

    /** The weekdays the crew is off from $start to $finish: Y-m-d strings. */
    public static function crewOffWeekdays(CarbonImmutable $start, CarbonImmutable $finish): array
    {
        return array_values(array_unique(array_column(array_filter(
            self::between($start, $finish),
            fn (array $holiday) => $holiday['kind'] === self::CREW_OFF && ! CarbonImmutable::parse($holiday['date'])->isWeekend()
        ), 'date')));
    }

    /** A run starting on a Tuesday takes in the Monday before it. */
    private static function bridgedBack(CarbonImmutable $first): CarbonImmutable
    {
        return $first->isTuesday() ? $first->subDay() : $first;
    }

    /** A run ending on a Thursday takes in the Friday after it. */
    private static function bridgedForward(CarbonImmutable $last): CarbonImmutable
    {
        return $last->isThursday() ? $last->addDay() : $last;
    }

    private static function nthWeekday(int $year, int $month, int $weekday, int $nth): CarbonImmutable
    {
        $first = CarbonImmutable::create($year, $month, 1);
        $day = $first->dayOfWeek === $weekday ? $first : $first->next($weekday);

        return $day->addWeeks($nth - 1);
    }

    private static function lastWeekday(int $year, int $month, int $weekday): CarbonImmutable
    {
        $last = CarbonImmutable::create($year, $month, 1)->endOfMonth()->startOfDay();

        return $last->dayOfWeek === $weekday ? $last : $last->previous($weekday);
    }
}
