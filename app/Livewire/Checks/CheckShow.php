<?php

namespace App\Livewire\Checks;

use App\Models\Check;
use App\Models\Expense;
use App\Models\Timesheet;
use App\Models\Vendor;
use Livewire\Attributes\Title;
use Livewire\Component;

class CheckShow extends Component
{
    public Check $check;

    protected $listeners = ['refreshComponent' => '$refresh'];

    #[Title('Check')]
    public function render()
    {
        //paid_by 60 = Robert, find vendor_id on vendor_user table "team members"(include previous/all
        // $user_via_vendor = auth()->user()->vendor->users()->where('via_vendor_id', $this->check->vendor_id);

        // if(!$user_via_vendor->get()->isEmpty()){
        //     $via_vendor_user_id = $user_via_vendor->first()->id;

        //     $vendor_paid_expenses =
        //         Expense::
        //             where('paid_by', $via_vendor_user_id)
        //             ->where('check_id', $this->check->id)
        //             ->whereNotNull('distribution_id')
        //             ->get();
        //     if($vendor_paid_expenses->isEmpty()){
        //         $vendor_paid_expenses = NULL;
        //     }
        // }else{
        //     $vendor_paid_expenses = NULL;
        // }
        // dd($vendor_paid_expenses);

        $vendor_expenses =
            Expense::where('check_id', $this->check->id)
                ->whereNull('reimbursment')
                ->whereNull('distribution_id')
                ->whereNull('paid_by')
                ->get();

        $weekly_timesheets =
            Timesheet::where('check_id', $this->check->id)
                ->where('user_id', $this->check->user_id)
                ->get();

        $employee_weekly_timesheets =
            Timesheet::where('paid_by', $this->check->user_id)
                ->where('check_id', $this->check->id)
                ->get()
                ->groupBy(['user_id', 'date']);

        $user_distributions =
            Expense::whereNotNull('distribution_id')
                ->whereNull('reimbursment')
                ->where('check_id', $this->check->id)
                ->get();

        $user_paid_by_reimbursements =
            Expense::
                where('paid_by', $this->check->user_id)
                ->whereNotNull('reimbursment')
                ->where('reimbursment', '!=', 'Client')
                ->where('check_id', $this->check->id)
                ->orderBy('date', 'DESC')
                ->get();

        // Paid Expenses
        $user_paid_expenses =
            Expense::where('paid_by', $this->check->user_id)
                ->where('check_id', $this->check->id)
                ->where(function ($query) {
                    $query->whereNull('reimbursment')
                        ->orWhere('reimbursment', 'Client')
                        ->orWhere(function ($q) {
                            $q->whereRaw('NOT (reimbursment REGEXP "^[0-9]+$")')
                            ->whereRaw('LEFT(reimbursment, 2) != "V:"');
                        });
                })
                ->get();

        $user_reimbursement_expenses = $this->check->user_id
            ? Expense::where('reimbursment', $this->check->user_id)
                ->whereNull('paid_by')
                ->where('check_id', $this->check->id)
                ->orderBy('date', 'DESC')
                ->get()
                ->keyBy('id')
            : collect();

        return view('livewire.checks.show', [
            'vendor_expenses' => $vendor_expenses,
            'user_paid_expenses' => $user_paid_expenses,
            'user_reimbursement_expenses' => $user_reimbursement_expenses,
            'user_paid_by_reimbursements' => $user_paid_by_reimbursements,
            // 'vendor_paid_expenses' => $vendor_paid_expenses,
            'weekly_timesheets' => $weekly_timesheets,
            'employee_weekly_timesheets' => $employee_weekly_timesheets,
            'user_distributions' => $user_distributions,
        ]);
    }
}
