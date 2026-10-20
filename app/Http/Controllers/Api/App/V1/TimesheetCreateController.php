<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\StoreTimesheetFromHourRequest;
use App\Models\Hour;
use App\Services\Timesheets\TimesheetConfirmation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * "Confirm Weekly Timesheets" — the app's version of
 * App\Livewire\Timesheets\TimesheetCreate (/timesheets/create/{hour}): the
 * week an Hour belongs to, grouped day-by-day, with the member's current
 * hourly rate — then, on save, the exact same write as the web's
 * TimesheetForm::store() via App\Services\Timesheets\TimesheetConfirmation.
 *
 * No policy gate beyond app.access: TimesheetPolicy::create() is an
 * unimplemented stub (always false) and TimesheetCreate never actually
 * calls it — see TimesheetConfirmation's docblock. Confirming a week is
 * open to any signed-in team member; only PAYING one is Admin-gated
 * (TimesheetPaymentController).
 */
class TimesheetCreateController extends Controller
{
    /**
     * The "Confirm Weekly Timesheets" card at the top of the web's
     * TimesheetsIndex — every week with unconfirmed Hours this person can
     * see (HourScope), each carrying the Hour id the create screen opens
     * with. See TimesheetConfirmation::pendingWeeks().
     */
    public function pending(TimesheetConfirmation $confirmation): JsonResponse
    {
        return response()->json(['data' => $confirmation->pendingWeeks()]);
    }

    public function show(Request $request, int $hour, TimesheetConfirmation $confirmation): JsonResponse
    {
        $found = $this->visibleHour($hour);

        $weeklyHours = $confirmation->weeklyHours($found->user, $found->date);

        if ($weeklyHours->isEmpty()) {
            return response()->json([
                'message' => 'This week has already been confirmed into a timesheet.',
            ], 409);
        }

        $hourly = (float) ($found->user->vendor_pivot?->hourly_rate ?? 0);
        $hours = (float) $weeklyHours->sum('hours');

        return response()->json([
            'data' => [
                'user' => ['id' => $found->user->id, 'name' => $found->user->full_name],
                'week' => ['start_date' => $found->date->startOfWeek()->format('Y-m-d')],
                'hourly' => $hourly,
                'hours' => $hours,
                'amount' => round($hourly * $hours, 2),
                'days' => $this->daysArray($weeklyHours),
            ],
        ]);
    }

    public function store(StoreTimesheetFromHourRequest $request, int $hour, TimesheetConfirmation $confirmation): JsonResponse
    {
        $found = $this->visibleHour($hour);

        $timesheet = $confirmation->confirm(
            $request->user(),
            $found->user,
            $found->date,
            (float) $request->validated('hourly'),
        );

        if (! $timesheet) {
            return response()->json([
                'message' => 'This week has already been confirmed into a timesheet.',
            ], 409);
        }

        return response()->json(['data' => ['timesheet_id' => $timesheet->id]], 201);
    }

    /** @return list<array{date: string, entries: list<array{id: int, hours: float, project: array{id: int, name: string}|null}>}> */
    private function daysArray(Collection $weeklyHours): array
    {
        return $weeklyHours
            ->sortBy('date')
            ->groupBy(fn (Hour $hour) => $hour->date->format('Y-m-d'))
            ->map(fn (Collection $hours, string $date): array => [
                'date' => $date,
                'entries' => $hours->map(fn (Hour $hour): array => [
                    'id' => $hour->id,
                    'hours' => (float) $hour->hours,
                    'project' => $hour->project ? ['id' => $hour->project->id, 'name' => $hour->project->name] : null,
                ])->values()->all(),
            ])
            ->values()
            ->all();
    }

    /** An Hour the person may see: 404 when it doesn't exist (HourScope already keeps this single-tenant). */
    private function visibleHour(int $id): Hour
    {
        $hour = Hour::with(['project', 'user'])->find($id);
        abort_unless($hour, 404);

        return $hour;
    }
}
