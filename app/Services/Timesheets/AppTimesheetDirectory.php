<?php

namespace App\Services\Timesheets;

use App\Models\Hour;
use App\Models\Timesheet;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Support\Collection;

/**
 * The app's Timesheets tab: the same confirmed-timesheets list, week
 * detail, text search, sortable columns and "Revert Timesheet" as the
 * web's TimesheetsIndex/TimesheetShow — see those Livewire components for
 * the table/card this mirrors. TimesheetScope (applied automatically on
 * every Timesheet query) already limits a Member to their own rows and an
 * Admin to their whole company, exactly like the web; nothing here
 * re-derives that. Creating a Timesheet from Hours and paying one stay
 * web-only (admin-only multi-step forms, not simple read views or a single
 * confirm).
 */
class AppTimesheetDirectory
{
    private const PER_PAGE = 20;

    /**
     * Everyone who has ever appeared in a Timesheet row or an unconfirmed
     * Hour row — TimesheetsIndex::mount()'s own `$employees` list for the
     * member filter, deliberately NOT limited to currently-employed staff:
     * a terminated member's history should still be filterable by name,
     * unlike App\Services\Users\AppTeamDirectory::teamMembers() (the Team
     * tab's own employed-only roster).
     *
     * @return list<array{id: int, first_name: ?string, last_name: ?string}>
     */
    public function employees(): array
    {
        $userIds = Timesheet::query()->distinct()->pluck('user_id')
            ->merge(Hour::query()->whereNull('timesheet_id')->distinct()->pluck('user_id'))
            ->filter()
            ->unique()
            ->values();

        return User::withoutGlobalScopes()
            ->whereIn('id', $userIds)
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name'])
            ->map(fn (User $user): array => [
                'id' => $user->id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
            ])
            ->values()
            ->all();
    }

    /**
     * One row per person-week (the per-project rows summed) — mirrors
     * TimesheetsIndex::timesheets()'s grouping, including its text search
     * (Meilisearch — user name/note/invoice/amount, same as $search there),
     * sortable columns (date/hours/amount, same mapping as
     * TimesheetsIndex::$sortBy) and its date range filter
     * (TimesheetsIndex::$date_range — inclusive, whole days).
     */
    public function groups(?int $userId, ?string $paid, string $search = '', string $sortBy = 'date', string $sortDirection = 'desc', ?string $startDate = null, ?string $endDate = null): CursorPaginator
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

        if ($startDate !== null && $endDate !== null) {
            $query->whereBetween('date', [
                \Illuminate\Support\Carbon::parse($startDate)->startOfDay(),
                \Illuminate\Support\Carbon::parse($endDate)->endOfDay(),
            ]);
        }

        if ($search !== '') {
            $this->applySearch($query, $search);
        }

        $sortColumn = match ($sortBy) {
            'hours' => 'total_hours',
            'amount' => 'total_amount',
            default => 'date',
        };
        $direction = $sortDirection === 'asc' ? 'asc' : 'desc';

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
            ->orderBy($sortColumn, $direction)
            ->when($sortColumn !== 'date', fn (Builder $q) => $q->orderBy('date', 'desc'))
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
     * paid/unpaid counts), including its $statusHref: "Pay" links to the
     * payment form for an Admin, "Paid" links straight to the check when
     * the whole week settled on one, and "Paid By" never links.
     *
     * @return array{label: string, color: string, link: ?array{type: 'payment'|'check', id: int}}
     */
    public function groupStatus(object $group, ?Timesheet $singleRow, User $viewer): array
    {
        $isAdmin = $viewer->vendor_role === 'Admin';

        if ($singleRow) {
            $status = $this->listRowStatus($singleRow);

            $status['link'] = match ($status['label']) {
                'Pay' => $isAdmin ? ['type' => 'payment', 'id' => (int) $singleRow->user_id] : null,
                'Paid' => $singleRow->check_id ? ['type' => 'check', 'id' => (int) $singleRow->check_id] : null,
                default => null,
            };

            return $status;
        }

        if ((int) $group->unpaid_count > 0) {
            return [
                'label' => 'Pay',
                'color' => 'yellow',
                'link' => $isAdmin ? ['type' => 'payment', 'id' => (int) $group->user_id] : null,
            ];
        }

        if ((int) $group->paid_count > 0) {
            // Link straight to the check only when the whole week settled
            // on one — otherwise the web links to the week itself, which
            // tapping the row already does, so there is nothing extra to link.
            $link = (int) $group->distinct_checks === 1 && $group->single_check_id
                ? ['type' => 'check', 'id' => (int) $group->single_check_id]
                : null;

            return ['label' => 'Paid', 'color' => 'green', 'link' => $link];
        }

        return ['label' => 'Paid By', 'color' => 'yellow', 'link' => null];
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
                // TimesheetShow::mount()'s $not_paid — every row's status
                // (as THIS viewer sees it) is still "Pay". Gates "Revert
                // Timesheet" on the detail screen; revert() below
                // recomputes this itself rather than trusting the client.
                'not_paid' => $this->isNotPaid($weeklyHours, $viewer),
            ],
            'rows' => $weeklyHours->map(function (Timesheet $row) use ($viewer): array {
                $status = $this->detailRowStatus($row, $viewer);

                return [
                    'id' => $row->id,
                    'amount' => (float) $row->amount,
                    'hours' => (float) $row->hours,
                    'project' => $row->project ? ['id' => $row->project->id, 'name' => $row->project->name] : null,
                    'payment' => $this->paymentLabel($row),
                    // The web's Amount/Payment cells both link straight to the
                    // paying check (timesheets/show.blade.php:40-71) — null
                    // once this week has no check yet.
                    'check_id' => $row->check_id,
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

    /**
     * $search against Meilisearch (user name/note/invoice/amount, same as
     * TimesheetsIndex::filteredRows()), falling back to a plain name/note
     * LIKE if that index call fails — same reasoning as
     * AppVendorDirectory::applySearch().
     */
    private function applySearch(Builder $query, string $search): void
    {
        try {
            $ids = Timesheet::scopedSearch($search)->take(5000)->keys();
            $query->whereIn('timesheets.id', $ids);

            return;
        } catch (\Throwable $exception) {
            report($exception);
        }

        $query->where(function (Builder $q) use ($search): void {
            $q->whereHas('user', function (Builder $userQuery) use ($search): void {
                $userQuery->where('first_name', 'like', '%'.$search.'%')
                    ->orWhere('last_name', 'like', '%'.$search.'%');
            })
                ->orWhere('note', 'like', '%'.$search.'%')
                ->orWhere('invoice', 'like', '%'.$search.'%');
        });
    }

    /**
     * Every row in $weeklyHours still shows "Pay" to $viewer — mirrors
     * TimesheetShow::mount()'s `$this->weekly_hours->pluck('status')->every(...)`.
     *
     * @param  Collection<int, Timesheet>  $weeklyHours
     */
    private function isNotPaid(Collection $weeklyHours, User $viewer): bool
    {
        return $weeklyHours->isNotEmpty()
            && $weeklyHours->every(fn (Timesheet $row) => $this->detailRowStatus($row, $viewer)['label'] === 'Pay');
    }

    /**
     * "Revert Timesheet" — TimesheetShow::revert() exactly: every Hour that
     * made up the week goes back to unconfirmed (timesheet_id = null) and
     * the week's Timesheet rows are deleted outright. Only when every row
     * is still "Pay" for $viewer (TimesheetShow's own $not_paid gate,
     * recomputed here rather than trusted from the client — the button is
     * hidden client-side, but the write must refuse it independently).
     */
    public function revert(Timesheet $timesheet, User $viewer): void
    {
        $weeklyHours = Timesheet::where('date', $timesheet->date->format('Y-m-d'))
            ->where('user_id', $timesheet->user_id)
            ->get();

        abort_unless($this->isNotPaid($weeklyHours, $viewer), 403, 'This timesheet can no longer be reverted.');

        foreach ($weeklyHours as $row) {
            // $row->hours is the decimal column (the HasMany relation shares
            // the name) — go through the relation method itself, exactly
            // like TimesheetShow::revert().
            foreach ($row->hours()->get() as $hour) {
                $hour->timesheet_id = null;
                $hour->save();
            }
            $row->delete();
        }
    }
}
