<?php

namespace App\Livewire\Checks;

use App\Models\Check;
use App\Models\Expense;
use App\Models\Timesheet;
use App\Models\Vendor;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

use Livewire\Attributes\Title;
use Livewire\Component;

class CheckShow extends Component
{
    use AuthorizesRequests;

    public Check $check;

    protected $listeners = ['refreshComponent' => '$refresh'];

    #[Title('Check')]
    public function render()
    {
        $this->authorize('view', $this->check);
        //paid_by user_id 60 = Robert, find vendor_id on vendor_user table "team members"(include previous/all

        // Get expense IDs from both check_id and many-to-many relationship
        $expenseIdsFromCheckId = Expense::where('check_id', $this->check->id)->pluck('id');
        $expenseIdsFromPivot = $this->check->expensesMany()->pluck('expenses.id');
        $allExpenseIds = $expenseIdsFromCheckId->merge($expenseIdsFromPivot)->unique();

        // EXPENSES
        $vendor_expenses =
            Expense::whereIn('id', $allExpenseIds)
                ->where(function ($query) {
                    $query->whereNull('reimbursment')
                        ->orWhere('reimbursment', 'Client');
                })
                ->whereNull('distribution_id')
                ->whereNull('paid_by')
                ->get();

        $weekly_timesheets = $this->check->user_id
            ? Timesheet::where('check_id', $this->check->id)
                ->where('user_id', $this->check->user_id)
                ->get()
            : collect();

        $employee_weekly_timesheets = $this->check->user_id
            ? Timesheet::where('paid_by', $this->check->user_id)
                ->whereNotNull('paid_by')
                ->where('check_id', $this->check->id)
                ->get()
                ->groupBy(['user_id', 'date'])
            : collect();

        $user_distributions =
            Expense::whereIn('id', $allExpenseIds)
                ->whereNotNull('distribution_id')
                ->whereNull('paid_by')
                ->whereNull('reimbursment')
                ->get();

        // Paid Expenses
        $user_paid_expenses = $this->check->user_id
            ? Expense::whereIn('id', $allExpenseIds)
                ->where('paid_by', $this->check->user_id)
                ->whereNotNull('paid_by')
                ->where(function ($query) {
                    $query->whereNull('reimbursment')
                        ->orWhere('reimbursment', 'Client')
                        ->orWhere(function ($q) {
                            $q->whereRaw('NOT (reimbursment REGEXP "^[0-9]+$")')
                                ->whereRaw('LEFT(reimbursment, 2) != "V:"');
                        });
                })
                ->get()
            : collect();

        $user_paid_by_reimbursements = $this->check->user_id
            ? Expense::whereIn('id', $allExpenseIds)
                ->where('paid_by', $this->check->user_id)
                ->whereNotNull('paid_by')
                ->where('check_id', $this->check->id)
                ->whereNotNull('reimbursment')
                ->where('reimbursment', '!=', 'Client')
                ->orderBy('date', 'DESC')
                ->get()
            : collect();

        $user_reimbursement_expenses = $this->check->user_id
            ? Expense::whereIn('id', $allExpenseIds)
                ->where('reimbursment', $this->check->user_id)
                ->whereNull('paid_by')
                ->orderBy('date', 'DESC')
                ->get()
                ->keyBy('id')
            : collect();

        return view('livewire.checks.show', [
            'vendor_expenses' => $vendor_expenses,
            'user_paid_expenses' => $user_paid_expenses,
            'user_reimbursement_expenses' => $user_reimbursement_expenses,
            'user_paid_by_reimbursements' => $user_paid_by_reimbursements,
            'weekly_timesheets' => $weekly_timesheets,
            'employee_weekly_timesheets' => $employee_weekly_timesheets,
            'user_distributions' => $user_distributions,
        ]);
    }
}
