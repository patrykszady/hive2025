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
            ['label' => 'Project', 'width' => 'w-[27%] min-w-0', 'skeletonWidth' => 'w-32'],
            ['label' => 'Hours', 'width' => 'w-[11%]', 'skeletonWidth' => 'w-10'],
            ['label' => 'Amount', 'width' => 'w-[13%]', 'skeletonWidth' => 'w-16'],
            ['label' => 'Status', 'width' => 'w-[14%]', 'skeleton' => 'badge'],
        ];
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

    #[Computed]
    public function timesheets()
    {
        $timesheets = Timesheet::scopedSearch(
            $this->search,
            $this->buildFilterConditions(),
            $this->sortBy,
            $this->sortDirection,
        )->paginateWithSearchData($this->paginate_number);

        if ($timesheets->isEmpty() && $timesheets->currentPage() > 1) {
            $this->resetPage();

            return $this->timesheets();
        }

        if ($timesheets->count() > 0) {
            $timesheets->getCollection()->load([
                'user:id,first_name,last_name',
                'project:id,project_name,address',
                'check:id,check_number',
                'paidBy:id,first_name,last_name',
            ]);
        }

        return $timesheets;
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
