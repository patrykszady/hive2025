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
        $start_date = Carbon::today()->startOfMonth()->subMonths(12);

        // Create a period between the start and end dates
        $period = CarbonPeriod::create($start_date, '1 month', $end_date);

        foreach ($period as $month) {
            $this->months[$month->format('M y')] = [];
        }

        $this->months = array_reverse($this->months);

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

        foreach ($monthly_payments as $month => $payments) {
            $this->months[$month]['monthly_payments'] = $payments;
        }

        $monthly_expenses =
            Expense::whereBetween('date', [$start_date, $end_date])
                ->orderBy('date', 'DESC')
                ->get()
                ->groupBy(function ($expense) {
                    return $expense->date->format('M y');
                })
                ->toBase();

        foreach ($monthly_expenses as $month => $expenses) {
            $this->months[$month]['monthly_expenses'] = $expenses;
        }

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

        foreach ($monthly_timesheets as $month => $timesheets) {
            $this->months[$month]['monthly_timesheets'] = $timesheets;
        }

        foreach ($this->months as $month => $this_month) {
            $this->months[$month]['monthly_total_expenses'] = (isset($this_month['monthly_expenses']) ? $this_month['monthly_expenses']->sum('amount') : '0.00') + (isset($this_month['monthly_timesheets']) ? $this_month['monthly_timesheets']->sum('amount') : '0.00');
        }

        $last_year_monthly_payments =
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

        foreach ($last_year_monthly_payments as $month => $payments) {
            $this->months[$month]['last_year_monthly_payments'] = $payments;
        }
    }

    public function render()
    {
        return view('livewire.sheets.monthly');
    }
}
