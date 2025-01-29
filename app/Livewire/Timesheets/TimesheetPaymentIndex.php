<?php

namespace App\Livewire\Timesheets;

use App\Models\Expense;
use App\Models\Timesheet;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

class TimesheetPaymentIndex extends Component
{
    use AuthorizesRequests, WithPagination;

    #[Title('Timesheet Payments')]
    public function render()
    {
        $this->authorize('viewPayment', Timesheet::class);

        $user = auth()->user();
        $vendor_users = $user->vendor->users()->where('is_employed', 1)->get();
        foreach ($vendor_users as $index => $user) {
            // $test_user_amount = ;
            // $this->dispatch('refresh_test')->to('timesheets.timesheet-payment-create');
            // dd();
            // dd($user);

            $weekly_timesheets =
            Timesheet::where('user_id', $user->id)
                ->whereNull('check_id')
                ->whereNull('paid_by')
                ->whereNull('deleted_at')
                ->orderBy('date', 'DESC')
                ->get()
                ->each(function ($item, $key) {
                    $item->checkbox = true;
                })
                ->keyBy('id');
            // ->groupBy(function($data) {
            //     // ->startOfWeek()->toFormattedDateString()
            //     return $data->date->format('Y-m-d');
            // }, true)
            // ->toBase();

            $employee_weekly_timesheets =
                    Timesheet::with('user')
                        ->where('paid_by', $user->id)
                        ->whereNull('check_id')
                        ->whereNull('deleted_at')
                        ->orderBy('date', 'DESC')
                        ->get()
                        ->each(function ($item, $key) {
                            $item->checkbox = true;
                        })
                        ->keyBy('id');

            $user_paid_expenses =
                Expense::where('paid_by', $user->id)
                    ->where(function ($query) {
                        $query->whereNull('reimbursment')->orWhere('reimbursment', 'Client');
                    })
                    ->whereNull('check_id')
                    ->orderBy('date', 'DESC')
                    ->get()
                    ->each(function ($item, $key) {
                        $item->checkbox = true;
                    })
                    ->keyBy('id');

            $user_reimbursement_expenses =
                Expense::where('reimbursment', $user->id)
                    ->whereNull('paid_by')
                    ->whereNull('check_id')
                    ->orderBy('date', 'DESC')
                    ->get()
                    ->each(function ($item, $key) {
                        $item->checkbox = true;
                    })
                    ->keyBy('id');
            // dd($this->user_reimbursement_expenses);

            $user_paid_by_reimbursements =
                Expense::where('paid_by', $user->id)
                    ->whereNotIn('reimbursment', ['', 'Client'])
                    ->whereNull('check_id')
                    ->orderBy('date', 'DESC')
                    ->get()
                    ->each(function ($item, $key) {
                        $item->checkbox = true;
                        // $item->amount = -$item->amount;
                    })
                    ->keyBy('id');
            // dd($this->user_paid_by_reimbursements);
            // // foreach($this->user_paid_by_reimbursements as $user_paid_by_reimbursement_expense){
            // //     $user_paid_by_reimbursement_expense->amount = '-' . $user_paid_by_reimbursement_expense->amount;
            // //     // dd($user_paid_by_reimbursement_expense->amount);
            // // }

            // // dd($this->user_paid_by_reimbursements);
            // // dd($this->user_paid_by_reimbursements->first()->reimbursment);

            if ($weekly_timesheets->isEmpty()) {
                $weekly_timesheets = collect();
            }

            if ($employee_weekly_timesheets->isEmpty()) {
                $employee_weekly_timesheets = collect();
            }

            if ($user_paid_expenses->isEmpty()) {
                $user_paid_expenses = collect();
            }

            if ($user_reimbursement_expenses->isEmpty()) {
                $user_reimbursement_expenses = collect();
            }

            if ($user_paid_by_reimbursements->isEmpty()) {
                $user_paid_by_reimbursements = collect();
            }
            //SAME on getWeeklyTimesheetsTotalProperty on TimesheetPaymentCreate
            $total = 0;
            $confirm_disable = [];
            //weekly_timesheets
            $total += $weekly_timesheets->where('checkbox', true)->sum('amount');

            //employee_weekly_timesheets
            $employee_weekly_timesheets_total = $employee_weekly_timesheets->where('checkbox', true)->sum('amount');
            if ($employee_weekly_timesheets_total != '0.00') {
                $confirm_disable[] = true;
            }
            $total += $employee_weekly_timesheets_total;

            //user_paid_expenses
            $user_paid_expenses_total = $user_paid_expenses->where('checkbox', true)->sum('amount');
            if ($user_paid_expenses_total != '0.00') {
                $confirm_disable[] = true;
            }
            $total += $user_paid_expenses_total;

            //user_reimbursement_expenses
            $total -= $user_reimbursement_expenses->where('checkbox', true)->sum('amount');

            // //user_paid_by_reimbursements
            $user_paid_by_reimbursements = $user_paid_by_reimbursements->where('checkbox', true)->sum('amount');
            if ($user_paid_by_reimbursements != '0.00') {
                $confirm_disable[] = true;
            }
            // dd($user_paid_by_reimbursements);
            $total -= $user_paid_by_reimbursements;

            $vendor_users[$index]->total = $total;
            // dd($total);
        }

        // $user_timesheets =
        //     Timesheet::
        //         orderBy('date', 'DESC')
        //         // ->where('user_id', auth()->user()->id)
        //         ->whereNull('check_id')
        //         ->whereNull('paid_by')
        //         ->get()
        //         ->groupBy('user_id');
        //         // ->groupBy('date');

        return view('livewire.timesheets.payment-index', [
            // 'user_timesheets' => $user_timesheets,
            'vendor_users' => $vendor_users,
        ]);
    }
}
