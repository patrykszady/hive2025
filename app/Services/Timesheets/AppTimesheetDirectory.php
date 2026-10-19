<?php

namespace App\Services\Timesheets;

use App\Models\Hour;
use App\Models\Timesheet;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Support\Collection;

/**
 * The app's Timesheets tab: the same confirmed-timesheets list and week
 * detail as the web's TimesheetsIndex/TimesheetShow — see those Livewire
 * components for the table/card this mirrors. TimesheetScope (applied
 * automatically on every Timesheet query) already limits a Member to their
 * own rows and an Admin to their whole company, exactly like the web;
 * nothing here re-derives that. Creating a Timesheet from Hours, paying one,
 * and reverting one stay web-only (admin-only write flows, not simple
 * read views).
 */
class AppTimesheetDirectory
{
    private const PER_PAGE = 20;

    /**
     * One row per person-week (the per-project rows summed), newest first —
     * mirrors TimesheetsIndex::timesheets()'s grouping, minus the Meilisearch
     * text search (not a phone-sized filter) and the sortable columns (a
     * card list, not a sortable table).
     */
    public function groups(?int $userId, ?string $paid): CursorPaginator
    {
        $query = Timesheet::query();

        if ($userId !== null) {
            $query->where('user_id', $userId);
        }

        if ($paid === 'confirmed') {
            $query->whereNotNull('check_id');
        } elseif ($paid === 'unpaid') {
            $query->whereNull('check_id');
        }

        return $query
            ->selectRaw('user_id, date')
            ->selectRaw('SUM(hours) as total_hours')
            ->selectRaw('SUM(amount) as total_amount')
            ->selectRaw('SUM(CASE WHEN check_id IS NULL AND paid_by IS NULL THEN 1 ELSE 0 END) as unpaid_count')
            ->selectRaw('SUM(CASE WHEN check_id IS NOT NULL THEN 1 ELSE 0 END) as paid_count')
            ->selectRaw('COUNT(DISTINCT check_id) as distinct_checks')
            ->selectRaw('MIN(check_id) as single_check_id')
            ->selectRaw('MIN(id) as first_timesheet_id')
            ->groupBy('user_id', 'date')
            ->orderByDesc('date')
            ->orderBy('user_id')
            ->cursorPaginate(self::PER_PAGE);
    }

    /**
     * Display data for one page of groups() rows: the member's name and that
     * week's per-project breakdown — one query for the users, one for the
     * rows, same batching as TimesheetsIndex::hydrateGroups().
     *
     * @param  Collection<int, Timesheet>  $groups
     * @return array<string, array{member_name: string, week_rows: Collection<int, Timesheet>}>
     */
    public function hydrateGroups(Collection $groups): array
    {
        $userIds = $groups->pluck('user_id')->unique();
        $users = User::withoutGlobalScopes()->whereIn('id', $userIds)->get(['id', 'first_name', 'last_name'])->keyBy('id');

        $rows = Timesheet::query()
            ->where(function (Builder $query) use ($groups): void {
                foreach ($groups as $group) {
                    $query->orWhere(fn ($q) => $q->where('user_id', $group->user_id)->where('date', $group->date->format('Y-m-d')));
                }
            })
            ->with('project:id,project_name,address,address_2,city,state,zip_code')
            ->get()
            ->groupBy(fn (Timesheet $row) => $row->user_id.'|'.$row->date->format('Y-m-d'));

        $hydrated = [];

        foreach ($groups as $group) {
            $user = $users->get($group->user_id);
            $key = $group->user_id.'|'.$group->date->format('Y-m-d');
            $hydrated[$key] = [
                'member_name' => trim(($user->first_name ?? '').' '.($user->last_name ?? '')),
                'week_rows' => $rows->get($key, collect())->values(),
            ];
        }

        return $hydrated;
    }

    /**
     * The per-project row status, label + Flux color — mirrors
     * TimesheetsIndex::rowStatus() exactly (the list page's own wording).
     *
     * @return array{label: string, color: string}
     */
    public function listRowStatus(Timesheet $row): array
    {
        if (! is_null($row->paid_by)) {
            return ['label' => 'Paid By', 'color' => 'yellow'];
        }

        if (! is_null($row->check_id)) {
            return ['label' => 'Paid', 'color' => 'green'];
        }

        return ['label' => 'Pay', 'color' => 'yellow'];
    }

    /**
     * The week-level aggregate status shown on the grouped row — mirrors
     * timesheets/index.blade.php's inline logic exactly (a single-project
     * week takes that row's own status; a multi-project week summarizes by
     * paid/unpaid counts).
     *
     * @return array{label: string, color: string}
     */
    public function groupStatus(object $group, ?Timesheet $singleRow): array
    {
        if ($singleRow) {
            return $this->listRowStatus($singleRow);
        }

        if ((int) $group->unpaid_count > 0) {
            return ['label' => 'Pay', 'color' => 'yellow'];
        }

        if ((int) $group->paid_count > 0) {
            return ['label' => 'Paid', 'color' => 'green'];
        }

        return ['label' => 'Paid By', 'color' => 'yellow'];
    }

    /**
     * One confirmed week's full detail: the per-project rows (mirrors
     * TimesheetShow::mount()'s $weekly_hours) and the day-by-day hours that
     * made it up ($daily_hours).
     *
     * @return array{week: array<string, mixed>, rows: list<array<string, mixed>>, days: list<array{date: string, hours: list<array<string, mixed>>}>}
     */
    public function detail(Timesheet $timesheet, User $viewer): array
    {
        $weeklyHours = Timesheet::with(['check', 'project:id,project_name,address,address_2,city,state,zip_code'])
            ->orderByDesc('date')
            ->where('date', $timesheet->date->format('Y-m-d'))
            ->where('user_id', $timesheet->user_id)
            ->get();

        $timesheetIds = $weeklyHours->pluck('id')->all();

        $dailyHours = Hour::orderBy('date')
            ->whereIn('timesheet_id', $timesheetIds)
            ->get()
            ->groupBy(fn (Hour $hour) => $hour->date->format('Y-m-d'));

        return [
            'week' => [
                'date' => $timesheet->date->format('Y-m-d'),
                'total_amount' => (float) $weeklyHours->sum('amount'),
                'total_hours' => (float) $weeklyHours->sum('hours'),
                'hourly' => (float) $timesheet->hourly,
                'user' => ['id' => $timesheet->user->id, 'name' => $timesheet->user->full_name],
            ],
            'rows' => $weeklyHours->map(function (Timesheet $row) use ($viewer): array {
                $status = $this->detailRowStatus($row, $viewer);

                return [
                    'id' => $row->id,
                    'amount' => (float) $row->amount,
                    'hours' => (float) $row->hours,
                    'project' => $row->project ? ['id' => $row->project->id, 'name' => $row->project->name] : null,
                    'payment' => $this->paymentLabel($row),
                    'status' => $status,
                ];
            })->values()->all(),
            'days' => $dailyHours->map(fn (Collection $hours, string $date): array => [
                'date' => $date,
                'hours' => $hours->map(fn (Hour $hour): array => [
                    'id' => $hour->id,
                    'hours' => (float) $hour->hours,
                    'project' => $hour->project ? ['id' => $hour->project->id, 'name' => $hour->project->name] : null,
                ])->values()->all(),
            ])->values()->all(),
        ];
    }

    /**
     * A per-project row's status on the week-detail page — mirrors
     * TimesheetShow::mount() exactly, which (unlike the list page) shows
     * "Not Paid" rather than "Pay" to anyone but an Admin.
     *
     * @return array{label: string, color: string}
     */
    private function detailRowStatus(Timesheet $row, User $viewer): array
    {
        if (! is_null($row->paid_by)) {
            return ['label' => 'Paid By', 'color' => 'yellow'];
        }

        if (! is_null($row->check_id)) {
            return ['label' => 'Paid', 'color' => 'green'];
        }

        return $viewer->vendor_role === 'Admin'
            ? ['label' => 'Pay', 'color' => 'yellow']
            : ['label' => 'Not Paid', 'color' => 'red'];
    }

    /** The payment column's display string — the check's number/type when one exists, otherwise null. */
    private function paymentLabel(Timesheet $row): ?string
    {
        if (! $row->check) {
            return null;
        }

        return $row->check->check_type !== 'Check'
            ? substr((string) $row->check->check_type, 0, 1).' #'.$row->check->id
            : (string) $row->check->check_number;
    }
}
