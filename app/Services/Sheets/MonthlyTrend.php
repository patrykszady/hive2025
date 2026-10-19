<?php

namespace App\Services\Sheets;

use App\Models\Expense;
use App\Models\Payment;
use App\Models\Timesheet;
use Carbon\Carbon;
use Carbon\CarbonPeriod;

/**
 * The trailing-12-month payments/expenses trend behind
 * App\Livewire\Sheets\SheetMonthly, shared with the app API's
 * SheetController@monthly.
 */
class MonthlyTrend
{
    /**
     * @return list<array{month_year: string, this_year_payments: float, last_year_payments: float, monthly_total_expenses: float}>
     */
    public function trailingMonths(): array
    {
        $today = browser_today();

        $end_date = $today->copy()->endOfMonth();
        $start_date = $today->copy()->startOfMonth()->subMonths(11);

        $period = CarbonPeriod::create($start_date, '1 month', $end_date);

        $monthly_payments = Payment::whereBetween('date', [$start_date, $end_date])
            ->whereHas('project', fn ($query) => $query->whereHas('latestStatus', fn ($subQuery) => $subQuery->where('status_code', '!=', 11)))
            ->orderBy('date', 'DESC')
            ->get()
            ->groupBy(fn ($payment) => $payment->date->format("M 'y"));

        $monthly_expenses = Expense::whereBetween('date', [$start_date, $end_date])
            ->orderBy('date', 'DESC')
            ->get()
            ->groupBy(fn ($expense) => $expense->date->format("M 'y"))
            ->toBase();

        $monthly_timesheets = Timesheet::whereHas('hours', fn ($query) => $query->whereBetween('date', [$start_date, $end_date]))
            ->orderBy('date', 'DESC')
            ->get()
            ->groupBy(fn ($timesheet) => $timesheet->date->format("M 'y"))
            ->toBase();

        $last_year_payments = Payment::whereBetween('date', [$start_date->copy()->subYear(), $end_date->copy()->subYear()])
            ->whereHas('project', fn ($query) => $query->whereHas('latestStatus', fn ($subQuery) => $subQuery->where('status_code', '!=', 11)))
            ->orderBy('date', 'DESC')
            ->get()
            ->groupBy(fn ($payment) => $payment->date->copy()->addYear()->format("M 'y"));

        $months = [];

        foreach ($period as $month) {
            $monthKey = $month->format("M 'y");

            $months[] = [
                'month_year' => $monthKey,
                'this_year_payments' => (float) (isset($monthly_payments[$monthKey]) ? $monthly_payments[$monthKey]->sum('amount') : 0),
                'last_year_payments' => (float) (isset($last_year_payments[$monthKey]) ? $last_year_payments[$monthKey]->sum('amount') : 0),
                'monthly_total_expenses' => (float) (
                    (isset($monthly_expenses[$monthKey]) ? $monthly_expenses[$monthKey]->sum('amount') : 0)
                    + (isset($monthly_timesheets[$monthKey]) ? $monthly_timesheets[$monthKey]->sum('amount') : 0)
                ),
            ];
        }

        return $months;
    }
}
