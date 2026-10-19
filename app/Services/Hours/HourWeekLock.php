<?php

namespace App\Services\Hours;

use App\Models\Timesheet;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Which calendar dates are "confirmed" for a user — already rolled into a
 * Timesheet, so Hours can no longer be logged or edited there. Shared by the
 * web's HourCreate (which disables those dates on its calendar via
 * `unavailable`) and the app's HourController (which must enforce the same
 * rule server-side, since there is no disabled-calendar UI to rely on).
 *
 * A Timesheet is always dated to the Monday of the week it confirms
 * (TimesheetForm::store()), so "confirmed" means every day of that
 * Monday-to-Sunday week, not just the stored date itself.
 */
class HourWeekLock
{
    /**
     * Every date (inclusive, Monday–Sunday) belonging to a week $user has
     * already confirmed on or after $minDate.
     *
     * @return list<string> sorted, de-duplicated "Y-m-d" strings
     */
    public function confirmedWeekDates(User $user, Carbon $minDate): array
    {
        /** @var Collection<string, Collection<int, Timesheet>> $weeks */
        $weeks = Timesheet::query()
            ->where('user_id', $user->id)
            ->where('date', '>=', $minDate->format('Y-m-d'))
            ->get()
            ->groupBy(fn (Timesheet $timesheet): string => $timesheet->date->format('Y-m-d'));

        $dates = [];

        foreach ($weeks as $week) {
            $weekStart = $week->first()->date->copy()->startOfWeek(Carbon::MONDAY);
            $weekEnd = $weekStart->copy()->endOfWeek(Carbon::SUNDAY);

            for ($day = $weekStart->copy(); $day->lte($weekEnd); $day->addDay()) {
                $dates[] = $day->format('Y-m-d');
            }
        }

        $unique = array_values(array_unique($dates));
        sort($unique);

        return $unique;
    }

    /** Whether $date (a "Y-m-d" string) falls inside one of $user's confirmed weeks on/after $minDate. */
    public function isLocked(User $user, string $date, Carbon $minDate): bool
    {
        return in_array($date, $this->confirmedWeekDates($user, $minDate), true);
    }
}
