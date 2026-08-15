<?php

namespace App\Livewire\Users;

use App\Models\Check;
use App\Models\Expense;
use App\Models\Timesheet;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Carbon;
// Session caching removed (was previously used to cache computed finances per session)
use Livewire\Component;

class UserFinances extends Component
{
    use AuthorizesRequests;

    public User $user;

    /**
     * Years to display (2020..current year)
     * @var array<int>
     */
    public array $years = [];

    /**
     * Current year for YTD labeling in UI if needed
     */
    public int $currentYear;

    /**
     * Aggregated monetary sums by year and metric
     * @var array<int, array<string, float>>
     */
    public array $sumsByYear = [];

    /**
     * Visibility flags for optional rows (true if any year has non-zero value)
     * @var array<string, bool>
     */
    public array $visibility = [];

    /**
     * Conflicting distribution details per year for drill-down
     * @var array<int, array<int, array<string, mixed>>>
     */
    public array $prepared_conflicting_by_year = [];

    public function mount(): void
    {
        $this->currentYear = (int) now()->year;
        // Build years range from first employment year with this vendor to current (descending)
        $vendorId = auth()->user()->vendor->id;

        // Always compute fresh (caching removed to ensure real-time accuracy after data changes)
        // Prefer the pivot from the User↔Vendor relationship (table: user_vendor)
        $startYear = null;
        $pivot = $this->user->vendors()
            ->where('vendors.id', $vendorId)
            ->first()?->pivot;

        if ($pivot) {
            $start = $pivot->started_at ?? $pivot->start_date ?? $pivot->created_at ?? null;
            if ($start) {
                $startYear = (int) Carbon::parse($start)->year;
            }
        }

        // Fallback: read directly from pivot tables (first user_vendor, then user_vendors)
        if (!$startYear) {
            $pivotTable = Schema::hasTable('user_vendor') ? 'user_vendor' : (Schema::hasTable('user_vendors') ? 'user_vendors' : null);
            if ($pivotTable) {
                $dateColumn = Schema::hasColumn($pivotTable, 'started_at')
                    ? 'started_at'
                    : (Schema::hasColumn($pivotTable, 'start_date') ? 'start_date' : 'created_at');

                $startYearVal = DB::table($pivotTable)
                    ->where('user_id', $this->user->id)
                    ->where('vendor_id', $vendorId)
                    ->selectRaw('MIN(YEAR(`' . $dateColumn . '`)) as y')
                    ->value('y');
                if (is_numeric($startYearVal)) {
                    $startYear = (int) $startYearVal;
                }
            }
        }

        if (!$startYear) {
            $startYear = $this->currentYear;
        }

        // Years in descending order so the first column is the current year
        $this->years = array_reverse(range($startYear, $this->currentYear));

        // Compute per-year data and sums
        foreach ($this->years as $year) {
            $yearData = $this->computeYearData($year);

            $this->sumsByYear[$year] = [
                'checks_written' => round($yearData['checks_written']->sum('amount'), 2),
                'timesheets_paid' => round($yearData['timesheets_paid']->sum('amount'), 2),
                'timesheets_paid_by' => round($yearData['timesheets_paid_by']->sum('amount'), 2),
                'timesheets_paid_others' => round($yearData['timesheets_paid_others']->sum('amount'), 2),
                'expenses_paid' => round($yearData['expenses_paid']->sum('amount'), 2),
                'distribution_checks' => round($yearData['distribution_checks']->sum('amount'), 2),
                'user_reimbursement_expenses' => round($yearData['user_reimbursement_expenses']->sum('amount'), 2),
                'user_reimbursement_paid_by' => round($yearData['user_reimbursement_paid_by']->sum('amount'), 2),
                'paid_other_user_reimbursements' => round($yearData['paid_other_user_reimbursements']->sum('amount'), 2),
                'distribution_expenses' => round($yearData['distribution_expenses']->sum('amount'), 2),
                'conflicting_distribution_expenses' => round($yearData['conflicting_distribution_expenses']->sum('amount'), 2),
            ];

            // Derived totals
            $this->sumsByYear[$year]['total_checks_for_user'] = round(
                $this->sumsByYear[$year]['timesheets_paid']
                + $this->sumsByYear[$year]['distribution_checks']
                + $this->sumsByYear[$year]['timesheets_paid_by'],
                2
            );
            $this->sumsByYear[$year]['total_for_user'] = round(
                $this->sumsByYear[$year]['total_checks_for_user']
                + $this->sumsByYear[$year]['distribution_expenses'],
                2
            );

            // Difference per year based on original formula
            $this->sumsByYear[$year]['difference'] = $this->computeCheckDifferenceFromSums($this->sumsByYear[$year]);

            // Store prepared conflicting details for drilldown per year
            $this->prepared_conflicting_by_year[$year] = $yearData['prepared_conflicting'];
        }

        // Compute visibility for optional rows (shown if any year has non-zero)
        $optionalKeys = [
            'user_reimbursement_expenses',
            'timesheets_paid_others',
            'timesheets_paid_by',
            'paid_other_user_reimbursements',
            'user_reimbursement_paid_by',
            'distribution_checks',
            'expenses_paid',
            'distribution_expenses',
            'conflicting_distribution_expenses',
        ];

        foreach ($optionalKeys as $key) {
            $this->visibility[$key] = collect($this->years)
                ->some(fn ($yr) => !empty($this->sumsByYear[$yr][$key]) && $this->sumsByYear[$yr][$key] != 0.0);
        }

        // (No caching) — deliberate removal for immediate reflection of financial changes within same session.
    }

    /**
     * Compute all collections for a given year.
     *
     * @return array<string, mixed>
     */
    protected function computeYearData(int $year): array
    {
        $user_distribution = $this->user->distributions->first()->id ?? null;

        // Checks Written
        $checks_written = Check::where('user_id', $this->user->id)
            ->whereYear('date', $year)
            ->where('belongs_to_vendor_id', auth()->user()->vendor->id)
            ->get();

        // Timesheets Paid
        $timesheets_paid = Timesheet::where('user_id', $this->user->id)
            ->where('vendor_id', auth()->user()->vendor->id)
            ->whereNull('paid_by')
            ->whereHas('check', function ($query) use ($year) {
                return $query->whereYear('date', $year);
            })
            ->get();

        // Timesheets Paid Others
        $timesheets_paid_others = Timesheet::whereNot('user_id', $this->user->id)
            ->where('paid_by', $this->user->id)
            ->where('vendor_id', auth()->user()->vendor->id)
            ->whereHas('check', function ($query) use ($year) {
                return $query->whereYear('date', $year);
            })
            ->get();

        // Timesheets Paid By (checks written by others for this user)
        $timesheets_paid_by = Timesheet::withoutGlobalScopes()
            ->with('check')
            ->where('user_id', $this->user->id)
            ->where('vendor_id', auth()->user()->vendor->id)
            ->whereNotNull('paid_by')
            ->whereHas('check', function ($query) use ($year) {
                return $query->withoutGlobalScopes()->whereYear('date', $year);
            })
            ->get();

        // Distribution Checks (only from checks written by this user to avoid cross-user subtraction)
        $checkIdsForUser = $checks_written->pluck('id');
        $distribution_checks = $user_distribution
            ? Expense::where('distribution_id', $user_distribution)
                ->whereIn('check_id', $checkIdsForUser)
                ->whereNull('paid_by')
                ->whereNull('reimbursment')
                ->get()
            : collect();

        // Expenses Paid (non-numeric reimbursements)
        $expenses_paid = Expense::where('paid_by', $this->user->id)
            ->where(function ($q) {
                $q->whereNull('reimbursment')
                    ->orWhereRaw('reimbursment REGEXP "[^0-9]"');
            })
            ->whereHas('check', function ($query) use ($year) {
                return $query->whereYear('date', $year);
            })
            ->get();

        // User Reimbursement Expenses Paid
        $user_reimbursement_expenses = Expense::whereNull('paid_by')
            ->where('reimbursment', (string) $this->user->id)
            ->whereHas('check', function ($query) use ($year) {
                return $query->whereYear('date', $year);
            })
            ->get();

        // User Reimbursement Expenses Paid By Others
        $user_reimbursement_paid_by = Expense::whereNotNull('paid_by')
            ->with('check')
            ->where('reimbursment', (string) $this->user->id)
            ->whereHas('check', function ($query) use ($year) {
                return $query->whereYear('date', $year);
            })
            ->get();

        // Paid Other User Reimbursement Expenses
        $paid_other_user_reimbursements = Expense::where('paid_by', $this->user->id)
            ->whereRaw('reimbursment REGEXP "^[0-9]+$"')
            ->whereHas('check', function ($query) use ($year) {
                return $query->whereYear('date', $year)
                    ->where('user_id', $this->user->id);
            })
            ->get();

        // Distribution Expenses reclassification
        $distribution_expenses = collect();
        $conflicting_distribution_expenses = collect();
        $prepared_conflicting = [];
        if ($user_distribution) {
            $allDistributionExpenses = Expense::with('check')
                ->where('distribution_id', $user_distribution)
                ->whereYear('date', $year)
                ->whereNull('paid_by')
                ->whereNull('reimbursment')
                ->get();

            $userDistributionCheckExpenseIds = $distribution_checks->pluck('id');
            $remaining = $allDistributionExpenses
                ->whereNotIn('id', $userDistributionCheckExpenseIds)
                ->values();

            // Conflicting: has a check and that check belongs to a different user.
            $conflicting_distribution_expenses = $remaining->filter(function ($e) {
                return $e->check && $e->check->user_id && $e->check->user_id !== $this->user->id;
            })->values();

            // Regular distribution expenses (exclude conflicting)
            $distribution_expenses = $remaining->reject(function ($e) {
                return $e->check && $e->check->user_id && $e->check->user_id !== $this->user->id;
            })->values();

            // Prepare drill-down data
            $prepared_conflicting = $conflicting_distribution_expenses->map(function ($e) {
                return [
                    'id' => $e->id,
                    'date' => $e->date?->format('m/d/Y'),
                    'amount' => round((float) $e->amount, 2),
                    'check_id' => $e->check?->id,
                    'check_number' => $e->check?->check_number,
                    'expense_link' => route('expenses.show', $e->id),
                    'check_link' => $e->check ? route('checks.show', $e->check->id) : null,
                ];
            })->toArray();
        }

        return compact(
            'checks_written',
            'timesheets_paid',
            'timesheets_paid_by',
            'timesheets_paid_others',
            'expenses_paid',
            'distribution_checks',
            'user_reimbursement_expenses',
            'user_reimbursement_paid_by',
            'paid_other_user_reimbursements',
            'distribution_expenses',
            'conflicting_distribution_expenses',
            'prepared_conflicting'
        );
    }

    protected function computeCheckDifferenceFromSums(array $sumsForYear): float
    {
        $rawChecks = round($sumsForYear['checks_written'] ?? 0.0, 2);
        $composed = round($sumsForYear['timesheets_paid'] ?? 0.0, 2)
            + round($sumsForYear['distribution_checks'] ?? 0.0, 2)
            + round($sumsForYear['timesheets_paid_others'] ?? 0.0, 2)
            + round($sumsForYear['expenses_paid'] ?? 0.0, 2)
            - round($sumsForYear['paid_other_user_reimbursements'] ?? 0.0, 2)
            - round($sumsForYear['user_reimbursement_expenses'] ?? 0.0, 2);
        return round($rawChecks - $composed, 2);
    }

    // Kept for backwards compatibility if referenced elsewhere; now unused in the multi-year view
    public function getCheckDifference(): float
    {
        $yr = $this->currentYear;
        return $this->sumsByYear[$yr]['difference'] ?? 0.0;
    }

    public function render()
    {
        return view('livewire.users.finances');
    }
    /**
     * View-based placeholder (skeleton) while lazy loading. Mirrors card/table layout visually.
     */
    public function placeholder()
    {
        return view('livewire.users.finances-placeholder');
    }
}
