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

    /**
     * Column defs for the weekly-timesheet cards — one source of truth for the
     * header and the cells, same contract as TimesheetShow::columnDefs.
     * Widths sum to 100.
     *
     * @return array<int, array{label: string, width: string}>
     */
    public static function timesheetColumnDefs(): array
    {
        return [
            ['label' => 'Amount', 'width' => 'w-[26%] min-w-0'],
            ['label' => 'Hours', 'width' => 'w-[18%] min-w-0'],
            ['label' => 'Project', 'width' => 'w-[56%] min-w-0'],
        ];
    }

    /**
     * Expense rows settled by this check (paid expenses, vendor/user
     * reimbursements): Amount / Date / Vendor / Project.
     *
     * @return array<int, array{label: string, width: string}>
     */
    public static function expenseColumnDefs(): array
    {
        return [
            ['label' => 'Amount', 'width' => 'w-[22%] min-w-0'],
            ['label' => 'Date', 'width' => 'w-[20%] min-w-0'],
            ['label' => 'Vendor', 'width' => 'w-[29%] min-w-0'],
            ['label' => 'Project', 'width' => 'w-[29%] min-w-0'],
        ];
    }

    /**
     * Reimbursements this check paid off for another team member.
     *
     * @return array<int, array{label: string, width: string}>
     */
    public static function reimbursementColumnDefs(): array
    {
        return [
            ['label' => 'Amount', 'width' => 'w-[22%] min-w-0'],
            ['label' => 'Date', 'width' => 'w-[20%] min-w-0'],
            ['label' => 'Team Member', 'width' => 'w-[29%] min-w-0'],
            ['label' => 'Vendor', 'width' => 'w-[29%] min-w-0'],
        ];
    }

    /**
     * Expenses the payee paid back (no date column — grouped by vendor).
     *
     * @return array<int, array{label: string, width: string}>
     */
    public static function paidBackColumnDefs(): array
    {
        return [
            ['label' => 'Amount', 'width' => 'w-[24%] min-w-0'],
            ['label' => 'Vendor', 'width' => 'w-[38%] min-w-0'],
            ['label' => 'Project', 'width' => 'w-[38%] min-w-0'],
        ];
    }

    /**
     * Distribution rows paid by this check.
     *
     * @return array<int, array{label: string, width: string}>
     */
    public static function distributionColumnDefs(): array
    {
        return [
            ['label' => 'Amount', 'width' => 'w-[28%] min-w-0'],
            ['label' => 'Distribution', 'width' => 'w-[72%] min-w-0'],
        ];
    }

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
                ->where('reimbursment', (string) $this->check->user_id)
                ->whereNull('paid_by')
                ->orderBy('date', 'DESC')
                ->get()
                ->keyBy('id')
            : collect();

        // Vendor reimbursements settled by this check — expenses the company
        // paid on the payee vendor's behalf (reimbursment = 'V:{vendor_id}'),
        // deducted from the check total by Check::recalculateAmount().
        $vendor_reimbursement_expenses = $this->check->vendor_id
            ? Expense::whereIn('id', $allExpenseIds)
                ->where('reimbursment', 'V:'.$this->check->vendor_id)
                ->orderBy('date', 'DESC')
                ->get()
            : collect();

        // Scanned check images cropped from bank statements
        $check_images = $this->check->checkImages()->orderBy('check_date')->get();

        return view('livewire.checks.show', [
            'check_images' => $check_images,
            'vendor_expenses' => $vendor_expenses,
            'vendor_reimbursement_expenses' => $vendor_reimbursement_expenses,
            'user_paid_expenses' => $user_paid_expenses,
            'user_reimbursement_expenses' => $user_reimbursement_expenses,
            'user_paid_by_reimbursements' => $user_paid_by_reimbursements,
            'weekly_timesheets' => $weekly_timesheets,
            'employee_weekly_timesheets' => $employee_weekly_timesheets,
            'user_distributions' => $user_distributions,
        ]);
    }
}
