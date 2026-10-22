<?php

namespace App\Support;

use App\Models\Expense;
use App\Models\ExpenseSplits;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What a project spent with each vendor, counting split expenses the way the
 * project's own finances do (Project::financesForVendor()): an expense on the
 * project, plus every split of an expense allocated to the project. A split
 * expense has no project of its own, so lien waivers that only looked at
 * expenses.project_id missed it — Climate Solutions invoice 68297 (expense
 * 28924, $27,618.69, split $27,198.69 + $420 to project 364) showed as the
 * $656.18 of the one invoice filed straight on the project (2026-10-21).
 */
class ProjectSupplierSpend
{
    /**
     * Total spent per vendor on the project: vendor id => amount.
     *
     * @return Collection<int, float>
     */
    public static function paidByVendor(int $projectId): Collection
    {
        $totals = [];

        Expense::withoutGlobalScopes()
            ->where('project_id', $projectId)
            ->whereNull('deleted_at')
            ->whereNotNull('vendor_id')
            ->selectRaw('vendor_id, SUM(amount) AS total')
            ->groupBy('vendor_id')
            ->pluck('total', 'vendor_id')
            ->each(function ($total, $vendorId) use (&$totals) {
                $totals[(int) $vendorId] = ($totals[(int) $vendorId] ?? 0) + (float) $total;
            });

        self::splitsOnProject($projectId)
            ->selectRaw('expenses.vendor_id AS vendor_id, SUM(expense_splits.amount) AS total')
            ->groupBy('expenses.vendor_id')
            ->pluck('total', 'vendor_id')
            ->each(function ($total, $vendorId) use (&$totals) {
                $totals[(int) $vendorId] = ($totals[(int) $vendorId] ?? 0) + (float) $total;
            });

        return collect($totals);
    }

    /**
     * Every vendor the project spent with, directly or through a split.
     *
     * @return Collection<int, int>
     */
    public static function vendorIds(int $projectId): Collection
    {
        return self::paidByVendor($projectId)->keys()->map(fn ($id) => (int) $id)->values();
    }

    /**
     * One vendor's spend on the project, expense by expense and split by
     * split: amount, date, and the check or invoice that paid it.
     *
     * @return Collection<int, object{amount: float, date: mixed, reference: string}>
     */
    public static function transactions(int $projectId, int $vendorId): Collection
    {
        $reference = fn (Expense $expense) => $expense->check
            ? trim(($expense->check->check_type ?? 'Check').($expense->check->check_number ? ' #'.$expense->check->check_number : ''))
            : ($expense->invoice ?: ($expense->paid_by ?: ''));

        $direct = Expense::withoutGlobalScopes()
            ->with(['check' => fn ($q) => $q->withoutGlobalScopes()])
            ->where('project_id', $projectId)
            ->where('vendor_id', $vendorId)
            ->whereNull('deleted_at')
            ->get()
            ->map(fn (Expense $expense) => (object) [
                'amount' => (float) $expense->amount,
                'date' => $expense->date,
                'reference' => $reference($expense),
            ]);

        $splitAmounts = self::splitsOnProject($projectId)
            ->where('expenses.vendor_id', $vendorId)
            ->get(['expense_splits.expense_id', 'expense_splits.amount']);

        $parents = Expense::withoutGlobalScopes()
            ->with(['check' => fn ($q) => $q->withoutGlobalScopes()])
            ->whereIn('id', $splitAmounts->pluck('expense_id')->unique())
            ->get()
            ->keyBy('id');

        $splits = $splitAmounts->map(fn ($split) => (object) [
            'amount' => (float) $split->amount,
            'date' => $parents->get($split->expense_id)?->date,
            'reference' => $parents->has($split->expense_id) ? $reference($parents->get($split->expense_id)) : '',
        ]);

        return $direct->concat($splits)->values();
    }

    /**
     * What the project paid one vendor, expenses and splits together,
     * optionally only what is dated on or before $through.
     */
    public static function paidToVendor(int $projectId, int $vendorId, ?CarbonInterface $through = null): float
    {
        return (float) self::transactions($projectId, $vendorId)
            ->when($through, fn (Collection $rows) => $rows->filter(
                fn ($row) => $row->date && Carbon::parse($row->date)->toDateString() <= $through->toDateString()
            ))
            ->sum('amount');
    }

    /**
     * Live splits allocated to the project, joined to their (live) expense.
     */
    private static function splitsOnProject(int $projectId): \Illuminate\Database\Eloquent\Builder
    {
        return ExpenseSplits::withoutGlobalScopes()
            ->join('expenses', 'expenses.id', '=', 'expense_splits.expense_id')
            ->where('expense_splits.project_id', $projectId)
            ->whereNull('expense_splits.deleted_at')
            ->whereNull('expenses.deleted_at')
            ->whereNotNull('expenses.vendor_id');
    }
}
