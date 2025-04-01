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
        $end_date = Carbon::today()->endOfMonth();
        $start_date = Carbon::today()->startOfMonth()->subMonths(11);

        // Create a period between the start and end dates
        $period = CarbonPeriod::create($start_date, '1 month', $end_date);

        $monthly_payments =
            Payment::whereBetween('date', [$start_date, $end_date])
                // ->with('project')
                ->whereHas('project', function ($query) {
                    // $query->status('VIEW ONLY');
                    // $query->where('last_status', 'VIEW_ONLY');
                    // $query->with('last_status')->where('last_status.title', '!=', 'VIEW ONLY');
                    // $query->with(['statuses' => function($query) {
                    //     return $query;
                    // $query->with(['statuses' => function ($query){
                    //     return $query->first();
                    //   }]);
                    // }]);
                    // return $query->status(['Active']);
                    $query->whereHas('last_status', function ($query) {
                        // dd($query->where('title', '!=', 'VIEW ONLY')->first());
                        $query->where('title', '!=', 'VIEW ONLY');
                    });
                })
                ->orderBy('date', 'DESC')
                ->get()
                ->groupBy(function ($payment) {
                    return $payment->date->format('M y');
                })
                ->toBase();

        $monthly_expenses =
            Expense::whereBetween('date', [$start_date, $end_date])
                ->orderBy('date', 'DESC')
                ->get()
                ->groupBy(function ($expense) {
                    return $expense->date->format('M y');
                })
                ->toBase();

        $monthly_timesheets =
            Timesheet::whereHas('hours', function ($query) use ($start_date, $end_date) {
                return $query->whereBetween('date', [$start_date, $end_date]);
            })
                ->orderBy('date', 'DESC')
                ->get()
                ->groupBy(function ($timesheet) {
                    return $timesheet->date->format('M y');
                })
                ->toBase();

        $last_year_payments =
            Payment::whereBetween('date', [$start_date->subYear(), $end_date->subYear()])
                ->whereHas('project', function ($query) {
                    $query->whereHas('last_status', function ($query) {
                        $query->where('title', '!=', 'VIEW ONLY');
                    });
                })
                ->orderBy('date', 'DESC')
                ->get()
                ->groupBy(function ($payment) {
                    return $payment->date->addYear()->format('M y');
                })
                ->toBase();

        foreach ($period as $month) {
            if (isset($monthly_expenses[$month->format('M y')])) {
                // Access the array key safely
                $this_year_payments = $monthly_payments[$month->format('M y')]->sum('amount');
            }else{
                $this_year_payments = 0;
            }

            $this->months[] = [
                'month_year' => $month->format('M y'),
                'this_year_payments' => $this_year_payments,
                'last_year_payments' => $last_year_payments[$month->format('M y')]->sum('amount'),
                //$this->months[$month]['monthly_total_expenses'] = (isset($this_month['monthly_expenses']) ? $this_month['monthly_expenses']->sum('amount') : '0.00') + (isset($this_month['monthly_timesheets']) ? $this_month['monthly_timesheets']->sum('amount') : '0.00');
                // 'monthly_total_expenses' =>
                //     ($monthly_expenses[$month->format('M y')]->isNotEmpty() ? $monthly_expenses[$month->format('M y')]->sum('amount') : 0)
                //     + ($monthly_timesheets[$month->format('M y')]->isNotEmpty() ? $monthly_timesheets[$month->format('M y')]->sum('amount') : 0),
                'monthly_total_expenses' =>
                    (isset($monthly_expenses[$month->format('M y')]) ? $monthly_expenses[$month->format('M y')]->sum('amount') : 0)
                    + (isset($monthly_timesheets[$month->format('M y')]) ? $monthly_timesheets[$month->format('M y')]->sum('amount') : 0),
            ];
        }
    }

    public function render()
    {
        return view('livewire.sheets.monthly');
    }
}
