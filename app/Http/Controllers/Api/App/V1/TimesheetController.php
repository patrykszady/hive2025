<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Controller;
use App\Models\Timesheet;
use App\Services\Timesheets\AppPendingBalance;
use App\Services\Timesheets\AppTimesheetDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The app's Timesheets tab: the same confirmed-timesheets list (with its
 * text search and sortable columns), week detail, "Revert Timesheet", and
 * "what am I owed" balance as the web's Timesheets pages — see
 * App\Services\Timesheets\AppTimesheetDirectory and AppPendingBalance for
 * the shared shaping. Creating a timesheet from Hours and paying one stay
 * web-only.
 */
class TimesheetController extends Controller
{
    public function index(Request $request, AppTimesheetDirectory $directory): JsonResponse
    {
        $this->authorize('viewAny', Timesheet::class);

        $userId = $request->filled('user_id') ? (int) $request->query('user_id') : null;
        $paid = in_array($request->query('status'), ['confirmed', 'unpaid'], true) ? $request->query('status') : null;
        $search = trim((string) $request->query('search', ''));
        $sortBy = in_array($request->query('sort'), ['date', 'hours', 'amount'], true) ? $request->query('sort') : 'date';
        $sortDirection = $request->query('direction') === 'asc' ? 'asc' : 'desc';
        // TimesheetsIndex::$date_range — both ends required, same as the web
        // (a lone start or end is simply ignored rather than half-applied).
        $startDate = $request->filled('start_date') && $request->filled('end_date') ? (string) $request->query('start_date') : null;
        $endDate = $request->filled('start_date') && $request->filled('end_date') ? (string) $request->query('end_date') : null;

        $page = $directory->groups($userId, $paid, $search, $sortBy, $sortDirection, $startDate, $endDate);
        $groups = $page->getCollection();
        $hydrated = $directory->hydrateGroups($groups);

        $viewer = $request->user();

        $data = $groups->map(function (object $group) use ($directory, $hydrated, $viewer): array {
            $key = $group->user_id.'|'.$group->date->format('Y-m-d');
            $extra = $hydrated[$key] ?? ['member_name' => '', 'week_rows' => collect()];
            $weekRows = $extra['week_rows'];
            $singleRow = $weekRows->count() === 1 ? $weekRows->first() : null;

            return [
                'date' => $group->date->format('Y-m-d'),
                'first_timesheet_id' => (int) $group->first_timesheet_id,
                'member_name' => $extra['member_name'],
                'total_hours' => (float) $group->total_hours,
                'total_amount' => (float) $group->total_amount,
                'status' => $directory->groupStatus($group, $singleRow, $viewer),
                'projects' => $singleRow
                    ? [$singleRow->project ? ($singleRow->project->short_address ?? $singleRow->project->name) : null]
                    : $weekRows->map(fn (Timesheet $row) => $row->project?->short_address ?? $row->project?->name)->filter()->values()->all(),
            ];
        })->all();

        return response()->json([
            'data' => $data,
            'next_cursor' => $page->nextCursor()?->encode(),
        ]);
    }

    /**
     * The member filter's options — TimesheetsIndex::mount()'s `$employees`
     * list, which (unlike the Team tab) includes anyone with timesheet/hour
     * history, even a terminated member.
     */
    public function employees(AppTimesheetDirectory $directory): JsonResponse
    {
        $this->authorize('viewAny', Timesheet::class);

        return response()->json(['data' => $directory->employees()]);
    }

    public function show(Request $request, int $timesheet, AppTimesheetDirectory $directory): JsonResponse
    {
        $found = $this->visibleTimesheet($timesheet);

        return response()->json(['data' => $directory->detail($found, $request->user())]);
    }

    /** "Revert Timesheet" — TimesheetShow::revert(). Any row's id for the week works; the whole week is reverted. */
    public function revert(Request $request, int $timesheet, AppTimesheetDirectory $directory): JsonResponse
    {
        $found = $this->visibleTimesheet($timesheet);
        $directory->revert($found, $request->user());

        return response()->json(['data' => ['reverted' => true]]);
    }

    /** The signed-in member's own outstanding balance — TimesheetPaymentCreate's read-only own-page view. */
    public function balance(Request $request, AppPendingBalance $balance): JsonResponse
    {
        $user = $request->user();
        $this->authorize('viewPayment', [Timesheet::class, $user]);

        return response()->json(['data' => $balance->ownBalance($user)]);
    }

    /** Admin-only: every employed team member's outstanding balance — TimesheetPaymentIndex's preview totals. */
    public function payable(Request $request, AppPendingBalance $balance): JsonResponse
    {
        $this->authorize('viewAnyPayment', Timesheet::class);

        return response()->json(['data' => $balance->payable($request->user())]);
    }

    /** A timesheet the person may see: 404 when it doesn't exist, 403 when TimesheetPolicy::view refuses it. */
    private function visibleTimesheet(int $id): Timesheet
    {
        abort_unless(Timesheet::withoutGlobalScopes()->whereKey($id)->exists(), 404);

        $timesheet = Timesheet::withoutGlobalScopes()->whereKey($id)->first();
        abort_unless($timesheet, 404);

        $this->authorize('view', $timesheet);

        return $timesheet;
    }
}
