<?php

namespace App\Services\Sheets;

use App\Models\Category;
use App\Models\Check;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Timesheet;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The profit-and-loss figures behind App\Livewire\Sheets\SheetShow, ported
 * 1:1 so the app API's SheetController reads exactly the same numbers —
 * see that class for the original computed properties this mirrors.
 * Exporting (CSV/PDF) and in-place category assignment stay web-only; this
 * class only ever reads.
 *
 * Note: `$basis` ('cash'|'accrual') carries over SheetShow's own behaviour
 * exactly, including a quirk worth flagging rather than "fixing" here —
 * getCashBasisExpenseIds()/applyCashBasisFilter() exist but no computed
 * property actually calls applyCashBasisFilter(), so today `$basis` changes
 * none of the dollar figures below (only the exported file's header
 * label). Kept as-is for parity with the web.
 */
class ProfitAndLossFigures
{
    private ?Collection $cachedTransactionsNoAssociations = null;

    private ?Collection $cachedMaterialVendorIds = null;

    private ?Collection $cachedSubVendorIds = null;

    private ?Collection $cachedCostOfLaborVendors = null;

    private ?Collection $cachedCostOfMaterialsVendors = null;

    private ?Collection $cachedGeneralExpenseCategories = null;

    private ?array $cashBasisExpenseIds = null;

    /**
     * @param  list<int>  $bankAccountIds
     */
    public function __construct(
        private string $startDate,
        private string $endDate,
        private array $bankAccountIds,
        private string $cash = 'include',
        private string $basis = 'accrual',
    ) {
    }

    private function getCashBasisExpenseIds(): array
    {
        if ($this->cashBasisExpenseIds === null) {
            $directIds = DB::table('transactions')
                ->whereIn('bank_account_id', $this->bankAccountIds)
                ->whereNotNull('expense_id')
                ->pluck('expense_id')
                ->toArray();

            $checkIds = DB::table('transactions')
                ->whereIn('bank_account_id', $this->bankAccountIds)
                ->whereNotNull('check_id')
                ->pluck('check_id')
                ->toArray();

            $checkExpenseIds = $checkIds
                ? DB::table('expenses')->whereIn('check_id', $checkIds)->pluck('id')->toArray()
                : [];

            $this->cashBasisExpenseIds = array_unique(array_merge($directIds, $checkExpenseIds));
        }

        return $this->cashBasisExpenseIds;
    }

    public function transactionsNoAssociations(): Collection
    {
        if ($this->cachedTransactionsNoAssociations !== null) {
            return $this->cachedTransactionsNoAssociations;
        }

        if (! $this->endDate || empty($this->bankAccountIds)) {
            return $this->cachedTransactionsNoAssociations = collect();
        }

        return $this->cachedTransactionsNoAssociations = Transaction::whereBetween('transaction_date', [$this->startDate, $this->endDate])
            ->whereIn('bank_account_id', $this->bankAccountIds)
            ->whereNull('check_id')
            ->whereNull('expense_id')
            ->where(function ($q) {
                $q->whereNull('deposit')
                    ->orWhere('deposit', 0)
                    ->orWhere(function ($qq) {
                        $qq->where('deposit', 1)->doesntHave('payments');
                    });
            })
            ->orderBy('transaction_date')
            ->get();
    }

    public function revenue(): float
    {
        $base = Payment::whereBetween('date', [$this->startDate, $this->endDate])
            ->whereHas('project', function ($query) {
                $query->whereHas('latestStatus', function ($query) {
                    $query->where('status_code', '!=', 11);
                });
            });

        if ($this->cash === 'hide') {
            return (float) (clone $base)
                ->where('reference', '!=', 'Cash')
                ->whereHas('transaction', function ($query) {
                    $query->whereIn('bank_account_id', $this->bankAccountIds);
                })
                ->sum('amount');
        }

        $nonCash = (clone $base)
            ->where('reference', '!=', 'Cash')
            ->whereHas('transaction', function ($query) {
                $query->whereIn('bank_account_id', $this->bankAccountIds);
            })
            ->sum('amount');

        $cashSum = (clone $base)->where('reference', '=', 'Cash')->sum('amount');

        return (float) ($nonCash + $cashSum);
    }

    public function materialVendorIds(): Collection
    {
        return $this->cachedMaterialVendorIds ??= Vendor::where('sheets_type', 'Materials')->pluck('id');
    }

    public function subVendorIds(): Collection
    {
        return $this->cachedSubVendorIds ??= Vendor::whereNot('business_type', 'Retail')->pluck('id');
    }

    /**
     * Vendor name => collection of checks/net-timesheet-entries, sorted by
     * total descending — see SheetShow::costOfLaborVendors() for why this
     * single query-plus-loop shape exists (ported verbatim).
     */
    public function costOfLaborVendors(): Collection
    {
        if ($this->cachedCostOfLaborVendors !== null) {
            return $this->cachedCostOfLaborVendors;
        }

        $companyVendorId = auth()->user()->vendor->id;

        $checksQuery = Check::whereBetween('date', [$this->startDate, $this->endDate])
            ->whereHas('vendor', function ($query) use ($companyVendorId) {
                $query->where('business_type', '!=', 'Retail')
                    ->where('id', '!=', $companyVendorId);
            });

        $checkVendors = $checksQuery
            ->with('vendor:id,business_name')
            ->get()
            ->groupBy('vendor.business_name')
            ->toBase();

        $viaVendorUsers = DB::table('user_vendor')
            ->where('vendor_id', $companyVendorId)
            ->whereNotNull('via_vendor_id')
            ->get(['user_id', 'via_vendor_id']);

        $timesheetVendors = collect();

        if ($viaVendorUsers->isNotEmpty()) {
            $userIds = $viaVendorUsers->pluck('user_id')->unique()->toArray();

            $viaVendorsById = Vendor::whereIn('id', $viaVendorUsers->pluck('via_vendor_id')->unique()->filter())
                ->get()
                ->keyBy('id');
            $usersById = User::with('distributions')
                ->whereIn('id', $userIds)
                ->get()
                ->keyBy('id');

            $periodStart = $this->startDate;
            $periodEnd = $this->endDate;
            $userIdStrings = array_map('strval', $userIds);
            $distributionIds = $usersById
                ->map(fn ($u) => $u->distributions->first()->id ?? null)
                ->filter()
                ->unique()
                ->values()
                ->all();

            $inCheckWindow = fn ($query) => $query->whereBetween('date', [$periodStart, $periodEnd]);

            $timesheetsPaidByUser = Timesheet::whereIn('user_id', $userIds)
                ->where('vendor_id', $companyVendorId)
                ->whereNull('paid_by')
                ->whereHas('check', $inCheckWindow)
                ->groupBy('user_id')
                ->selectRaw('user_id, SUM(amount) as total')
                ->pluck('total', 'user_id');

            $timesheetsPaidByOtherByUser = Timesheet::withoutGlobalScopes()
                ->whereIn('user_id', $userIds)
                ->where('vendor_id', $companyVendorId)
                ->whereNotNull('paid_by')
                ->whereHas('check', fn ($query) => $query->withoutGlobalScopes()->whereBetween('date', [$periodStart, $periodEnd]))
                ->groupBy('user_id')
                ->selectRaw('user_id, SUM(amount) as total')
                ->pluck('total', 'user_id');

            $reimbursementsByUser = Expense::whereNull('paid_by')
                ->whereIn('reimbursment', $userIdStrings)
                ->whereHas('check', $inCheckWindow)
                ->groupBy('reimbursment')
                ->selectRaw('reimbursment, SUM(amount) as total')
                ->pluck('total', 'reimbursment');

            $reimbursementsPaidByOtherByUser = Expense::whereNotNull('paid_by')
                ->whereIn('reimbursment', $userIdStrings)
                ->whereHas('check', $inCheckWindow)
                ->groupBy('reimbursment')
                ->selectRaw('reimbursment, SUM(amount) as total')
                ->pluck('total', 'reimbursment');

            $expensesPaidByUser = Expense::whereIn('paid_by', $userIds)
                ->whereHas('check', $inCheckWindow)
                ->groupBy('paid_by')
                ->selectRaw('paid_by, SUM(amount) as total')
                ->pluck('total', 'paid_by');

            $distributionChecksById = empty($distributionIds) ? collect() : Expense::whereIn('distribution_id', $distributionIds)
                ->whereHas('check', $inCheckWindow)
                ->groupBy('distribution_id')
                ->selectRaw('distribution_id, SUM(amount) as total')
                ->pluck('total', 'distribution_id');

            $distributionExpensesById = empty($distributionIds) ? collect() : Expense::whereIn('distribution_id', $distributionIds)
                ->whereNull('check_id')
                ->whereBetween('date', [$periodStart, $periodEnd])
                ->groupBy('distribution_id')
                ->selectRaw('distribution_id, SUM(amount) as total')
                ->pluck('total', 'distribution_id');

            foreach ($userIds as $userId) {
                $userVendorRel = $viaVendorUsers->where('user_id', $userId)->first();
                if (! $userVendorRel) {
                    continue;
                }

                $viaVendor = $viaVendorsById->get($userVendorRel->via_vendor_id);
                if (! $viaVendor) {
                    continue;
                }

                $timesheetsPaid = (float) ($timesheetsPaidByUser[$userId] ?? 0);
                $timesheetsPaidBy = (float) ($timesheetsPaidByOtherByUser[$userId] ?? 0);
                $userReimbursementExpenses = (float) ($reimbursementsByUser[(string) $userId] ?? 0);
                $userReimbursementPaidBy = (float) ($reimbursementsPaidByOtherByUser[(string) $userId] ?? 0);

                $user = $usersById->get($userId);
                $userDistribution = $user?->distributions->first()->id ?? null;
                $distributionChecks = $userDistribution ? (float) ($distributionChecksById[$userDistribution] ?? 0) : 0;

                $totalChecksForUser = $timesheetsPaid
                    + $timesheetsPaidBy
                    + $distributionChecks
                    - $userReimbursementExpenses
                    - $userReimbursementPaidBy;

                $expensesPaid = (float) ($expensesPaidByUser[$userId] ?? 0);

                if ($expensesPaid > 0) {
                    $distributionExpenses = $userDistribution ? (float) ($distributionExpensesById[$userDistribution] ?? 0) : 0;

                    $finalAmount = $timesheetsPaid
                        - $expensesPaid
                        + $distributionChecks
                        + $distributionExpenses
                        + $timesheetsPaidBy;
                } else {
                    $finalAmount = $totalChecksForUser;
                }

                if ($finalAmount > 0) {
                    $netEntry = (object) [
                        'amount' => $finalAmount,
                        'user_id' => $userId,
                        'vendor_id' => $viaVendor->id,
                    ];

                    if (! $timesheetVendors->has($viaVendor->business_name)) {
                        $timesheetVendors->put($viaVendor->business_name, collect());
                    }

                    $timesheetVendors->get($viaVendor->business_name)->push($netEntry);
                }
            }
        }

        $allVendors = $checkVendors;
        foreach ($timesheetVendors as $vendorName => $timesheets) {
            if ($allVendors->has($vendorName)) {
                $allVendors->get($vendorName)->push(...$timesheets);
            } else {
                $allVendors->put($vendorName, $timesheets);
            }
        }

        return $this->cachedCostOfLaborVendors = $allVendors->sortByDesc(fn ($vendor) => $vendor->sum('amount'))->toBase();
    }

    public function costOfLaborSum(): float
    {
        return (float) $this->costOfLaborVendors()->flatten()->sum('amount');
    }

    public function costOfMaterialsVendors(): Collection
    {
        if ($this->cachedCostOfMaterialsVendors !== null) {
            return $this->cachedCostOfMaterialsVendors;
        }

        $vendors = Expense::whereBetween('date', [$this->startDate, $this->endDate])
            ->whereIn('vendor_id', $this->materialVendorIds())
            ->with(['vendor'])
            ->get()
            ->groupBy('vendor.business_name')
            ->toBase();

        return $this->cachedCostOfMaterialsVendors = $vendors->sortByDesc(fn ($vendor) => $vendor->sum('amount'))->toBase();
    }

    public function costOfMaterialsSum(): float
    {
        return (float) Expense::whereBetween('date', [$this->startDate, $this->endDate])
            ->whereIn('vendor_id', $this->materialVendorIds())
            ->sum('amount');
    }

    public function generalExpenses(): float
    {
        return (float) Expense::whereBetween('date', [$this->startDate, $this->endDate])
            ->whereNotIn('vendor_id', array_merge($this->materialVendorIds()->toArray(), $this->subVendorIds()->toArray()))
            ->whereNotIn('category_id', [112, 113, 114, 115, 116, 117, 118, 119, 120, 121, 122, 123, 124, 125, 126, 127, 128])
            ->sum('amount');
    }

    public function generalExpenseCategories(): Collection
    {
        if ($this->cachedGeneralExpenseCategories !== null) {
            return $this->cachedGeneralExpenseCategories;
        }

        return $this->cachedGeneralExpenseCategories = Expense::whereBetween('date', [$this->startDate, $this->endDate])
            ->whereNotIn('category_id', [112, 113, 114, 115, 116, 117, 118, 119, 120, 121, 122, 123, 124, 125, 126, 127, 128])
            ->whereNotIn('vendor_id', array_merge($this->materialVendorIds()->toArray(), $this->subVendorIds()->toArray()))
            ->with(['category', 'vendor'])
            ->get()
            ->groupBy('category.friendly_primary')
            ->toBase();
    }

    /**
     * @return array<string, array{subcategories: list<array{name: string, sum: float, vendors: list<array{name: string, sum: float, vendor_id: ?int}>}>, sum: float, name: string}>
     */
    public function sortedExpenseCategories(): array
    {
        $sortedCategories = [];

        foreach ($this->generalExpenseCategories() as $categoryPrimaryName => $generalExpenseCategory) {
            $sortedDetailedCategories = [];

            foreach ($generalExpenseCategory->groupBy('category.friendly_detailed') as $categoryFriendlyDetailed => $categoryFriendlyExpenses) {
                $sortedVendors = [];

                foreach ($categoryFriendlyExpenses->groupBy('vendor.business_name') as $vendorName => $generalExpenseVendorExpenses) {
                    $sortedVendors[] = [
                        'name' => $vendorName ?: 'Unknown Vendor',
                        'sum' => (float) $generalExpenseVendorExpenses->sum('amount'),
                        'vendor_id' => $generalExpenseVendorExpenses->first()->vendor_id ?? null,
                    ];
                }

                usort($sortedVendors, fn ($a, $b) => $b['sum'] <=> $a['sum']);

                $sortedDetailedCategories[] = [
                    'name' => $categoryFriendlyDetailed ?: 'Uncategorized',
                    'sum' => (float) $categoryFriendlyExpenses->sum('amount'),
                    'vendors' => $sortedVendors,
                ];
            }

            usort($sortedDetailedCategories, fn ($a, $b) => $b['sum'] <=> $a['sum']);

            $sortedCategories[$categoryPrimaryName] = [
                'subcategories' => $sortedDetailedCategories,
                'sum' => (float) $generalExpenseCategory->sum('amount'),
                'name' => (string) $categoryPrimaryName,
            ];
        }

        uasort($sortedCategories, fn ($a, $b) => $b['sum'] <=> $a['sum']);

        return $sortedCategories;
    }

    public function uncategorizedExpenses(): Collection
    {
        if (! $this->endDate) {
            return collect();
        }

        return Expense::whereBetween('date', [$this->startDate, $this->endDate])
            ->whereNull('category_id')
            ->with(['vendor'])
            ->orderByDesc('amount')
            ->get();
    }

    public function uncategorizedTransactionsSum(): float
    {
        return round((float) $this->transactionsNoAssociations()->sum('amount'), 2);
    }

    public function availableCategories(): Collection
    {
        return Category::orderBy('friendly_primary')->orderBy('friendly_detailed')->get();
    }
}
