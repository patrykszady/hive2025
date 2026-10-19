<?php

namespace App\Services\Dashboard;

use App\Models\Expense;
use App\Models\Payment;
use App\Models\Timesheet;
use Illuminate\Support\Carbon;

/**
 * The 12-month payments/expenses chart behind the web Hub's admin-only
 * "Payments & Expenses" card (App\Livewire\Sheets\SheetMonthly) — extracted
 * so the Hive Contractors app's Home tab can show the same numbers without
 * forking the query. SheetMonthly's own mount() now just delegates here.
 *
 * $today is passed in for the same reason as VendorTaskAgenda: the web
 * caller passes browser_today(), the app has no such cookie and passes
 * Carbon::now('America/Chicago').
 */
class MonthlyFinancialSummary
{
    /**
     * @return list<array{month_year: string, this_year_payments: float, last_year_payments: float, monthly_total_expenses: float}>
     */
    public function months(Carbon $today): array
    {
        $endDate = $today->copy()->endOfMonth();
        $startDate = $today->copy()->startOfMonth()->subMonths(11);

        $period = \Carbon\CarbonPeriod::create($startDate, '1 month', $endDate);

        $monthlyPayments = Payment::whereBetween('date', [$startDate, $endDate])
            ->whereHas('project', fn ($query) => $query->whereHas(
                'latestStatus',
                fn ($subQuery) => $subQuery->where('status_code', '!=', 11) // Exclude "VIEW ONLY" projects.
            ))
            ->orderByDesc('date')
            ->get()
            ->groupBy(fn (Payment $payment) => $payment->date->format("M 'y"));

        $monthlyExpenses = Expense::whereBetween('date', [$startDate, $endDate])
            ->orderByDesc('date')
            ->get()
            ->groupBy(fn (Expense $expense) => $expense->date->format("M 'y"))
            ->toBase();

        $monthlyTimesheets = Timesheet::whereHas('hours', fn ($query) => $query->whereBetween('date', [$startDate, $endDate]))
            ->orderByDesc('date')
            ->get()
            ->groupBy(fn (Timesheet $timesheet) => $timesheet->date->format("M 'y"))
            ->toBase();

        $lastYearPayments = Payment::whereBetween('date', [$startDate->copy()->subYear(), $endDate->copy()->subYear()])
            ->whereHas('project', fn ($query) => $query->whereHas(
                'latestStatus',
                fn ($subQuery) => $subQuery->where('status_code', '!=', 11)
            ))
            ->orderByDesc('date')
            ->get()
            ->groupBy(fn (Payment $payment) => $payment->date->copy()->addYear()->format("M 'y"));

        $months = [];

        foreach ($period as $month) {
            $monthKey = $month->format("M 'y");

            $months[] = [
                'month_year' => $monthKey,
                'this_year_payments' => (float) ($monthlyPayments[$monthKey] ?? collect())->sum('amount'),
                'last_year_payments' => (float) ($lastYearPayments[$monthKey] ?? collect())->sum('amount'),
                'monthly_total_expenses' => (float) (
                    ($monthlyExpenses[$monthKey] ?? collect())->sum('amount')
                    + ($monthlyTimesheets[$monthKey] ?? collect())->sum('amount')
                ),
            ];
        }

        return $months;
    }
}
