<?php

namespace App\Livewire\Timesheets;

use App\Models\Hour;
use App\Models\Timesheet;
use App\Models\User;
use Carbon\Carbon;
use Flux\DateRange;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

// #[Lazy]
class TimesheetsIndex extends Component
{
    use AuthorizesRequests, WithPagination;

    /**
     * How many skeleton rows the loading placeholder should paint — the card's
     * page size, so the skeleton is the same height as the table that replaces
     * it (no jump on load). Callers that can cheaply COUNT the real rows pass
     * the smaller of the two.
     */
    public static function placeholderRows(): int
    {
        return 20;
    }

    /**
     * Column defs for the confirmed-timesheets table — the loading skeleton
     * renders from the same array as the real header row, so widths can never
     * drift apart.
     *
     * @return array<int, array{label: string, width: string, skeleton?: string, skeletonWidth?: string}>
     */
    public static function columnDefs(): array
    {
        return [
            ['label' => 'Date', 'width' => 'w-[13%]', 'skeletonWidth' => 'w-16'],
            ['label' => 'Name', 'width' => 'w-[22%] min-w-0', 'skeletonWidth' => 'w-24'],
            ['label' => 'Projects', 'width' => 'w-[27%] min-w-0', 'skeletonWidth' => 'w-32'],
            ['label' => 'Hours', 'width' => 'w-[11%]', 'skeletonWidth' => 'w-10'],
            ['label' => 'Amount', 'width' => 'w-[13%]', 'skeletonWidth' => 'w-16'],
            ['label' => 'Status', 'width' => 'w-[14%]', 'skeleton' => 'badge'],
        ];
    }

    /**
     * Status of one per-project timesheet row, shared by single-project weeks
     * and the split-style sub-rows. Lives here (not a blade closure) because
     * the table renders inside an island, which compiles to its own view —
     * a closure defined outside it never reaches that scope.
     *
     * @return array{0: string, 1: string, 2: ?string} [label, color, href]
     */
    public static function rowStatus(Timesheet $row): array
    {
        if (! is_null($row->paid_by)) {
            return ['Paid By', 'yellow', null];
        }

        if (! is_null($row->check_id)) {
            return ['Paid', 'green', route('checks.show', $row->check_id)];
        }

        return ['Pay', 'yellow', auth()->user()->vendor_role === 'Admin'
            ? route('timesheets.payment', $row->user_id)
            : null];
    }

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: null)]
    public $user_id = null;

    #[Url(except: [])]
    public array $paid_statuses = [];

    public ?DateRange $date_range = null;

    public $employees = [];

    public string $sortBy = 'date';

    public string $sortDirection = 'desc';

    public int $paginate_number = 20;

    public function mount(): void
    {
        $userIds = Timesheet::query()->distinct()->pluck('user_id')
            ->merge(Hour::query()->whereNull('timesheet_id')->distinct()->pluck('user_id'))
            ->filter()
            ->unique()
            ->values();

        $this->employees = User::withoutGlobalScopes()
            ->whereIn('id', $userIds)
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name']);

    }

    public function updating(): void
    {
        $this->resetPage();
    }

    public function sort(string $column): void
    {
        if ($this->sortBy === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDirection = 'desc';
        }
    }

    /**
     * Meilisearch filter conditions built from the active filters.
     *
     * @return array<int, string>
     */
    private function buildFilterConditions(): array
    {
        $conditions = [];

        if (! is_null($this->user_id) && $this->user_id !== '') {
            $conditions[] = 'user_id = ' . (int) $this->user_id;
        }

        $hasConfirmed = in_array('Confirmed', $this->paid_statuses, true);
        $hasUnpaid = in_array('Unpaid', $this->paid_statuses, true);

        if ($hasConfirmed && ! $hasUnpaid) {
            $conditions[] = 'is_paid = true';
        } elseif ($hasUnpaid && ! $hasConfirmed) {
            $conditions[] = 'is_paid = false';
        }

        if ($this->date_range && $this->date_range->start() && $this->date_range->end()) {
            $start = $this->date_range->start()->copy()->startOfDay()->timestamp;
            $end = $this->date_range->end()->copy()->endOfDay()->timestamp;
            $conditions[] = "date >= {$start} AND date <= {$end}";
        }

        return $conditions;
    }

    /**
     * Timesheet rows matching the active filters — tenancy and the member
     * own-rows restriction come from the global TimesheetScope, so this is the
     * same visibility as the old per-row search. Text search still goes
     * through Meilisearch (name / note / amount, including the numeric
     * handling), constraining the SQL query to the matching row ids.
     */
    private function filteredRows(): \Illuminate\Database\Eloquent\Builder
    {
        $query = Timesheet::query();

        if (! is_null($this->user_id) && $this->user_id !== '') {
            $query->where('user_id', (int) $this->user_id);
        }

        $hasConfirmed = in_array('Confirmed', $this->paid_statuses, true);
        $hasUnpaid = in_array('Unpaid', $this->paid_statuses, true);

        if ($hasConfirmed && ! $hasUnpaid) {
            $query->whereNotNull('check_id');
        } elseif ($hasUnpaid && ! $hasConfirmed) {
            $query->whereNull('check_id');
        }

        if ($this->date_range && $this->date_range->start() && $this->date_range->end()) {
            $query->whereBetween('date', [
                $this->date_range->start()->copy()->startOfDay(),
                $this->date_range->end()->copy()->endOfDay(),
            ]);
        }

        if ($this->search !== '') {
            $query->whereIn('id', Timesheet::scopedSearch(
                $this->search,
                $this->buildFilterConditions(),
            )->take(5000)->keys());
        }

        return $query;
    }

    /**
     * One row per person-week: the per-project rows are summed here and broken
     * down on /timesheets/{id} — mirroring the Confirm Weekly Timesheets card
     * above, instead of the old rowspan-grouped per-project rows that were
     * hard to scan.
     */
    #[Computed]
    public function timesheets()
    {
        $sortColumn = match ($this->sortBy) {
            'hours' => 'total_hours',
            'amount' => 'total_amount',
            default => 'date',
        };

        $groups = $this->filteredRows()
            ->selectRaw('user_id, date')
            ->selectRaw('SUM(hours) as total_hours')
            ->selectRaw('SUM(amount) as total_amount')
            ->selectRaw('SUM(CASE WHEN check_id IS NULL AND paid_by IS NULL THEN 1 ELSE 0 END) as unpaid_count')
            ->selectRaw('SUM(CASE WHEN check_id IS NOT NULL THEN 1 ELSE 0 END) as paid_count')
            ->selectRaw('COUNT(DISTINCT check_id) as distinct_checks')
            ->selectRaw('MIN(check_id) as single_check_id')
            ->selectRaw('MIN(id) as first_timesheet_id')
            ->groupBy('user_id', 'date')
            ->orderBy($sortColumn, $this->sortDirection === 'asc' ? 'asc' : 'desc')
            ->when($sortColumn !== 'date', fn ($q) => $q->orderBy('date', 'desc'))
            ->orderBy('user_id')
            ->paginate($this->paginate_number);

        if ($groups->isEmpty() && $groups->currentPage() > 1) {
            $this->resetPage();

            return $this->timesheets();
        }

        if ($groups->count() > 0) {
            $this->hydrateGroups($groups->getCollection());
        }

        return $groups;
    }

    /**
     * Attach display data (member name, that week's per-project rows) to the
     * current page of groups — one query for the users, one for the rows. The
     * rows render as shaded sub-rows under the week row, same as expense
     * splits under a main expense.
     */
    private function hydrateGroups($groups): void
    {
        $users = User::withoutGlobalScopes()
            ->whereIn('id', $groups->pluck('user_id')->unique())
            ->get(['id', 'first_name', 'last_name'])
            ->keyBy('id');

        $rows = $this->filteredRows()
            ->where(function ($query) use ($groups) {
                foreach ($groups as $group) {
                    $query->orWhere(fn ($q) => $q
                        ->where('user_id', $group->user_id)
                        ->where('date', $group->date->format('Y-m-d')));
                }
            })
            ->with('project:id,project_name,address')
            ->get()
            ->groupBy(fn ($row) => $row->user_id.'|'.$row->date->format('Y-m-d'));

        foreach ($groups as $group) {
            $user = $users->get($group->user_id);
            $group->member_name = trim(($user->first_name ?? '').' '.($user->last_name ?? ''));
            $group->week_rows = $rows
                ->get($group->user_id.'|'.$group->date->format('Y-m-d'), collect())
                ->values();
        }
    }

    /**
     * Unconfirmed hours grouped by employee, then by week, respecting the
     * existing timesheet creation flow.
     */
    private function weeklyHoursToConfirm()
    {
        return Hour::query()
            ->orderBy('date', 'DESC')
            ->whereNull('timesheet_id')
            ->with('user:id,first_name,last_name')
            ->get()
            ->groupBy(fn ($item) => $item->user->first_name)
            ->toBase()
            ->transform(function ($item) {
                return $item->groupBy(function ($hour) {
                    return Carbon::parse($hour->date)->startOfWeek()->toFormattedDateString();
                })->each(function ($group) {
                    $group->timesheet_id = $group->first()->id;
                    $group->sum_hours = $group->sum('hours');
                });
            });
    }

    #[Title('Timesheets')]
    public function render()
    {
        $this->authorize('viewAny', Timesheet::class);

        // $timesheets is intentionally NOT passed here: the table lives in a
        // lazy island and reads $this->timesheets, so the search query only
        // runs when the island renders.
        return view('livewire.timesheets.index', [
            'weekly_hours_to_confirm' => $this->weeklyHoursToConfirm(),
        ]);
    }
}
