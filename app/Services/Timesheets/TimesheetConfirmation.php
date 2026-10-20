<?php

namespace App\Services\Timesheets;

use App\Models\Hour;
use App\Models\Timesheet;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Confirms a week's logged Hours into payable Timesheet rows — one
 * Timesheet per project the person worked that week, each carrying its own
 * share of the hours at the given hourly rate. Extracted from
 * App\Livewire\Forms\TimesheetForm::store() (which now calls here) so the
 * Hive Contractors app's "Confirm Weekly Timesheets" action
 * (App\Http\Controllers\Api\App\V1\TimesheetCreateController) shares the
 * exact same write — the web's own TimesheetCreate::mount() also reads
 * weeklyHours() from here, so both surfaces offer the identical set of
 * unconfirmed hours.
 *
 * Mirrors the web exactly: TimesheetPolicy::create() is an unimplemented
 * stub (always false) and is never actually consulted by TimesheetCreate —
 * confirming a week is open to any signed-in team member, not just an
 * Admin (only PAYING a confirmed timesheet is Admin-gated). Nothing here
 * re-adds a check the web doesn't have.
 */
class TimesheetConfirmation
{
    /**
     * Every Hour for $employee's week of $weekStart not yet rolled into a
     * Timesheet — mirrors TimesheetCreate::mount()'s $weekly_hours query
     * exactly (project eager-loaded for the day-by-day breakdown).
     *
     * @return Collection<int, Hour>
     */
    public function weeklyHours(User $employee, Carbon $weekStart): Collection
    {
        return Hour::with('project')
            ->where('user_id', $employee->id)
            ->whereNull('timesheet_id')
            ->whereBetween('date', [
                $weekStart->copy()->startOfWeek()->format('Y-m-d'),
                $weekStart->copy()->endOfWeek()->format('Y-m-d'),
            ])
            ->get();
    }

    /**
     * Confirms $employee's week of $weekStart at $hourly — mirrors
     * TimesheetForm::store() exactly: updates the employee's hourly_rate
     * pivot for $actingUser's company, then creates one Timesheet per
     * project worked that week and re-points every Hour row at it.
     *
     * Returns null when there is nothing left to confirm (the week's Hours
     * were already rolled up, or there simply aren't any) — the caller
     * decides what that means (the web silently redirects back to the
     * index; the app surfaces a 409).
     */
    public function confirm(User $actingUser, User $employee, Carbon $weekStart, float $hourly): ?Timesheet
    {
        $weeklyHours = $this->weeklyHours($employee, $weekStart);

        if ($weeklyHours->isEmpty()) {
            return null;
        }

        $employee->vendors()->updateExistingPivot($actingUser->vendor->id, ['hourly_rate' => $hourly]);

        $timesheet = null;

        foreach ($weeklyHours->groupBy('project.id') as $projectId => $projectHours) {
            $hours = $projectHours->sum('hours');

            $timesheet = Timesheet::create([
                'date' => $weekStart->copy()->startOfWeek()->format('Y-m-d'),
                'user_id' => $employee->id,
                'vendor_id' => $actingUser->vendor->id,
                'project_id' => $projectId,
                'hours' => $hours,
                'amount' => $hourly * $hours,
                'hourly' => $hourly,
                'created_by_user_id' => $actingUser->id,
            ]);

            foreach ($projectHours as $hour) {
                $hour->timesheet()->associate($timesheet)->save();
            }
        }

        return $timesheet;
    }
}
