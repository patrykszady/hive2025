<?php

namespace App\Services\Estimates;

use App\Support\ConstructionHolidays;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriodImmutable;
use Illuminate\Support\Number;

/**
 * The date arithmetic behind "Estimate your project duration": where a
 * project of a given length ends, when it must start for the contractor's
 * prep work to be done when the cabinets arrive, and how many of its days
 * the crew actually works.
 */
class ProjectDuration
{
    /**
     * Project lengths offered, in the order shown.
     *
     * @var array<string, array{label: string, unit: string, amount: int}>
     */
    public const PRESETS = [
        '2w' => ['label' => '2 weeks', 'unit' => 'week', 'amount' => 2],
        '4w' => ['label' => '4 weeks', 'unit' => 'week', 'amount' => 4],
        '6w' => ['label' => '6 weeks', 'unit' => 'week', 'amount' => 6],
        '8w' => ['label' => '8 weeks', 'unit' => 'week', 'amount' => 8],
        '3m' => ['label' => '3 months', 'unit' => 'month', 'amount' => 3],
        '4m' => ['label' => '4 months', 'unit' => 'month', 'amount' => 4],
        '6m' => ['label' => '6 months', 'unit' => 'month', 'amount' => 6],
        '9m' => ['label' => '9 months', 'unit' => 'month', 'amount' => 9],
        '12m' => ['label' => '12 months', 'unit' => 'month', 'amount' => 12],
    ];

    public const DEFAULT_PRESET = '8w';

    /** Weeks of contractor work (demo, rough-ins) that can come before the cabinets. */
    public const PREP_WEEKS = [1, 2, 3, 4, 5, 6];

    /**
     * How strongly $day shows the fade around a project (1 next to it, falling
     * to nothing past the fade), or null outside the fade. The company sets
     * the days ($before, $after: GS fades 8 in and 16 out).
     */
    public static function fade(CarbonImmutable $day, ?CarbonImmutable $start, ?CarbonImmutable $finish, int $before = 8, int $after = 16): ?float
    {
        if ($start && $day->lessThan($start)) {
            $days = (int) round($day->diffInDays($start));

            return $days <= $before ? round(($before + 1 - $days) / ($before + 1), 2) : null;
        }

        if ($finish && $day->greaterThan($finish)) {
            $days = (int) round($finish->diffInDays($day));

            return $days <= $after ? round(($after + 1 - $days) / ($after + 1), 2) : null;
        }

        return null;
    }

    /** The last day of a project starting on $start: the start is day one. */
    public static function finish(CarbonImmutable $start, string $preset): CarbonImmutable
    {
        $length = self::PRESETS[$preset] ?? self::PRESETS[self::DEFAULT_PRESET];

        $next = $length['unit'] === 'week'
            ? $start->addWeeks($length['amount'])
            : $start->addMonthsNoOverflow($length['amount']);

        return $next->subDay();
    }

    /**
     * The nearest day a project can start on (a later one on a tie). The
     * company sets its demo days as ISO weekdays; GS never demos on a Monday
     * or a Friday, so Monday and Sunday move on to Tuesday, Friday and
     * Saturday back to Thursday. Never before $notBefore (today): then the
     * next demo day on or after $day instead.
     *
     * @param  list<int>  $demoDays
     */
    public static function demoDay(CarbonImmutable $day, array $demoDays = [2, 3, 4], ?CarbonImmutable $notBefore = null): CarbonImmutable
    {
        for ($distance = 0; $distance <= 3; $distance++) {
            foreach ([$day->addDays($distance), $day->subDays($distance)] as $candidate) {
                if (in_array($candidate->dayOfWeekIso, $demoDays, true) && (! $notBefore || $candidate->greaterThanOrEqualTo($notBefore->startOfDay()))) {
                    return $candidate;
                }
            }
        }

        for ($distance = 4; $distance < 7; $distance++) {
            if (in_array($day->addDays($distance)->dayOfWeekIso, $demoDays, true)) {
                return $day->addDays($distance);
            }
        }

        return $day;
    }

    /**
     * The start that leaves $prepWeeks of work before the cabinets: the demo
     * day of the week $prepWeeks before the delivery week (GS: its Tuesday).
     *
     * @param  list<int>  $demoDays
     */
    public static function startBeforeCabinets(CarbonImmutable $cabinetsArrive, int $prepWeeks, array $demoDays = [2, 3, 4]): CarbonImmutable
    {
        return self::demoDay($cabinetsArrive->subWeeks($prepWeeks), $demoDays);
    }

    /** Whole weeks from the week $start falls in to the cabinet week, or null when it is not before it. */
    public static function weeksBeforeCabinets(CarbonImmutable $start, CarbonImmutable $cabinetMonday): ?int
    {
        $weeks = (int) round(self::cabinetWeek($start)->diffInDays($cabinetMonday) / 7);

        return $weeks > 0 ? $weeks : null;
    }

    /**
     * Cabinets come in a delivery week, not on a day: the Monday of the
     * calendar row (Sunday first) that $day sits in.
     */
    public static function cabinetWeek(CarbonImmutable $day): CarbonImmutable
    {
        return $day->startOfWeek(CarbonImmutable::SUNDAY)->addDay();
    }

    /** "1st week of December": the month and count of the week's Thursday, so a week split across months goes where most of it falls. */
    public static function weekOfMonth(CarbonImmutable $monday): string
    {
        $thursday = $monday->addDays(3);

        return Number::ordinal((int) ceil($thursday->day / 7)).' week of '.$thursday->format('F');
    }

    public static function calendarDays(CarbonImmutable $start, CarbonImmutable $finish): int
    {
        return (int) $start->diffInDays($finish) + 1;
    }

    /**
     * Monday–Friday from $start to $finish, inclusive, less the days off: the
     * ones given (the holidays the homeowner cannot have us), or by default
     * the crew's holidays.
     *
     * @param  list<string>|null  $daysOff  Y-m-d dates
     */
    public static function workingDays(CarbonImmutable $start, CarbonImmutable $finish, ?array $daysOff = null): int
    {
        $off = array_flip($daysOff ?? ConstructionHolidays::crewOffWeekdays($start, $finish));
        $working = 0;

        foreach (CarbonPeriodImmutable::create($start, $finish) as $day) {
            if (! $day->isWeekend() && ! isset($off[$day->toDateString()])) {
                $working++;
            }
        }

        return $working;
    }
}
