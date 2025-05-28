<?php

namespace App\Livewire\Users;

use App\Models\Check;
use App\Models\Expense;
use App\Models\Timesheet;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class UserFinances extends Component
{
    use AuthorizesRequests;

    public User $user;

    public $year = 2025;

    public $timesheets_paid = 0;
    public $timesheets_paid_by = 0;
    public $timesheets_paid_others = 0;
    public $expenses_paid = 0;
    public $distribution_checks = 0;
    public $checks_written = 0;
    public $user_reimbursement_expenses = 0;
    public $user_reimbursement_paid_by = 0;
    public $paid_other_user_reimbursements = 0;
    public $distribution_expenses = 0;
    public $difference = 0;

    public function mount()
    {
        if ($this->user->this_vendor) {
            $user_distribution = $this->user->distributions->first()->id ?? null;

            //Checks Written
            $this->checks_written =
                Check::where('user_id', $this->user->id)
                    ->whereYear('date', $this->year)
                    ->where('belongs_to_vendor_id', $this->user->this_vendor->id)
                    // ->withWhereHas('expenses')
                    // ->withWhereHas('timesheets')
                    ->get();

            //Timesheets Paid
            $this->timesheets_paid =
                Timesheet::
                    where('user_id', $this->user->id)
                    ->where('vendor_id', $this->user->this_vendor->id)
                    ->whereNull('paid_by')
                    ->whereHas('check', function ($query){
                        return $query->whereYear('date', $this->year);
                    })
                    ->get();

            //Timesheets Paid Others
            $this->timesheets_paid_others =
                //whereNot('user_id', $this->user->id)
                // where('user_id', 212)
                Timesheet::whereNot('user_id', $this->user->id)
                    ->where('paid_by', $this->user->id)
                    ->where('vendor_id', $this->user->this_vendor->id)
                    ->whereHas('check', function ($query) {
                        return $query->whereYear('date', $this->year);
                    })
                    ->get();

            // Timesheets Paid By
            $this->timesheets_paid_by =
                Timesheet::withoutGlobalScopes()
                    ->where('user_id', $this->user->id)
                    ->where('vendor_id', $this->user->this_vendor->id)
                    ->whereNotNull('paid_by')
                    ->whereHas('check', function ($query) {
                        return $query->withoutGlobalScopes()->whereYear('date', $this->year);
                    })
                    ->get();

            // Distribution Checks
            $this->distribution_checks = $user_distribution
                ? Expense::where('distribution_id', $user_distribution)
                    // ->whereNull('reimbursment')
                    ->whereHas('check', function ($query) {
                        return $query->whereYear('date', $this->year);
                    })
                    ->get()
                : collect();

            // Expenses Paid
            $this->expenses_paid =
                Expense::where('paid_by', $this->user->id)
                    // ->whereYear('date', $year)
                    // ->whereNotNull('check_id')
                    // ->whereNull('reimbursment')
                    ->whereHas('check', function ($query) {
                        return $query->whereYear('date', $this->year);
                    })
                    ->get();

            // User Reimbursement Expenses Paid
            $this->user_reimbursement_expenses =
                Expense::whereNull('paid_by')
                    ->where('reimbursment', $this->user->id)
                    ->whereHas('check', function ($query) {
                        return $query->whereYear('date', $this->year);
                    })
                    ->get();

            // User Reimbursement Expenses Paid By Others
            $this->user_reimbursement_paid_by =
                Expense::whereNotNull('paid_by')
                    ->where('reimbursment', $this->user->id)
                    ->whereHas('check', function ($query) {
                        return $query->whereYear('date', $this->year);
                    })
                    ->get();

            // Paid Other User Reimbursement Expenses
            $this->paid_other_user_reimbursements =
                Expense::where('paid_by', $this->user->id)
                    ->whereRaw('reimbursment REGEXP "^[0-9]+$"')
                    ->whereHas('check', function ($query) {
                        return $query->whereYear('date', $this->year);
                    })
                    ->get();
            // dd($paid_other_user_reimbursements->sum('amount'));

            // Distribution Expenses
            $this->distribution_expenses = $user_distribution
                ? Expense::where('distribution_id', $user_distribution)
                    ->whereNull('check_id')
                    ->whereYear('date', $this->year)
                    // ->whereNotIn('id', $this->paid_other_user_reimbursements->pluck('id')->toArray())
                    // whereHas('transactions') ...transaction_date = $year
                    ->get()
                : collect();
        }
    }

    public function getCheckDifference()
    {
        return round(
            round($this->checks_written->sum('amount'), 2) -
            round($this->timesheets_paid->sum('amount'), 2) +
            round($this->user_reimbursement_expenses->sum('amount'), 2) +
            round($this->paid_other_user_reimbursements->sum('amount'), 2) -
            round($this->timesheets_paid_others->sum('amount'), 2) -
            round($this->distribution_checks->sum('amount'), 2) -
            round($this->expenses_paid->sum('amount'), 2),
            2
        );
    }

    public function render()
    {
        return view('livewire.users.finances');
    }
}
