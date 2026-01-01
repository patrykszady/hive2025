<?php

namespace App\Livewire\Sheets;

use App\Models\Expense;
use App\Models\Payment;
use App\Models\Timesheet;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Livewire\Attributes\Lazy;
use Livewire\Component;

#[Lazy]
class SheetMonthly extends Component
{
    public $months = [];

    public function mount()
    {
        $today = browser_today();

        $end_date = $today->copy()->endOfMonth();
        $start_date = $today->copy()->startOfMonth()->subMonths(11);

        // Create a period between the start and end dates
        $period = CarbonPeriod::create($start_date, '1 month', $end_date);

        $monthly_payments = Payment::whereBetween('date', [$start_date, $end_date])
            ->whereHas('project', fn($query) =>
                $query->whereHas('latestStatus', fn($subQuery) =>
                    $subQuery->where('status_code', '!=', 11) // Exclude projects with latest status "VIEW ONLY"
                )
            )
            ->orderBy('date', 'DESC') // Sort payments by date descending
            ->get()
            ->groupBy(fn($payment) => $payment->date->format('M y'));

        $monthly_expenses = Expense::whereBetween('date', [$start_date, $end_date])
            ->orderBy('date', 'DESC')
            ->get()
            ->groupBy(fn($expense) => $expense->date->format('M y'))
            ->toBase();

        $monthly_timesheets = Timesheet::whereHas('hours', fn($query) =>
            $query->whereBetween('date', [$start_date, $end_date]))
            ->orderBy('date', 'DESC')
            ->get()
            ->groupBy(fn($timesheet) => $timesheet->date->format('M y'))
            ->toBase();

        $last_year_payments = Payment::whereBetween('date', [$start_date->copy()->subYear(), $end_date->copy()->subYear()])
            ->whereHas('project', fn($query) =>
                $query->whereHas('latestStatus', fn($subQuery) =>
                    $subQuery->where('status_code', '!=', 11) // Exclude projects with latest status "VIEW ONLY"
                )
            )
            ->orderBy('date', 'DESC') // Sort payments by date descending
            ->get()
            ->groupBy(fn($payment) => $payment->date->copy()->addYear()->format('M y'));

        foreach ($period as $month) {
            $monthKey = $month->format('M y');

            $this->months[] = [
                'month_year' => $monthKey,
                'this_year_payments' => isset($monthly_payments[$monthKey]) ? $monthly_payments[$monthKey]->sum('amount') : 0,
                'last_year_payments' => isset($last_year_payments[$monthKey]) ? $last_year_payments[$monthKey]->sum('amount') : 0,
                'monthly_total_expenses' =>
                    (isset($monthly_expenses[$monthKey]) ? $monthly_expenses[$monthKey]->sum('amount') : 0)
                    + (isset($monthly_timesheets[$monthKey]) ? $monthly_timesheets[$monthKey]->sum('amount') : 0),
            ];
        }
    }

    public function render()
    {
        return view('livewire.sheets.monthly');
    }
}
