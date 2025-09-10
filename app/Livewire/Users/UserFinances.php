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

    public $year = 2024;

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
    public $conflicting_distribution_expenses = 0;
    public $difference = 0;
    public $prepared_conflicting = [];

    public function mount()
    {
        $user_distribution = $this->user->distributions->first()->id ?? null;

        //Checks Written
        $this->checks_written =
            Check::where('user_id', $this->user->id)
                ->whereYear('date', $this->year)
                ->where('belongs_to_vendor_id', auth()->user()->vendor->id)
                ->get();

        //Timesheets Paid
        $this->timesheets_paid =
            Timesheet::
                where('user_id', $this->user->id)
                ->where('vendor_id', auth()->user()->vendor->id)
                ->whereNull('paid_by')
                ->whereHas('check', function ($query){
                    return $query->whereYear('date', $this->year);
                })
                ->get();

        //Timesheets Paid Others
        $this->timesheets_paid_others =
            Timesheet::whereNot('user_id', $this->user->id)
                ->where('paid_by', $this->user->id)
                ->where('vendor_id', auth()->user()->vendor->id)
                ->whereHas('check', function ($query) {
                    return $query->whereYear('date', $this->year);
                })
                ->get();

        // Timesheets Paid By
        $this->timesheets_paid_by =
            Timesheet::withoutGlobalScopes()
                ->with('check')
                ->where('user_id', $this->user->id)
                ->where('vendor_id', auth()->user()->vendor->id)
                ->whereNotNull('paid_by')
                ->whereHas('check', function ($query) {
                    return $query->withoutGlobalScopes()->whereYear('date', $this->year);
                })
                ->get();

        // Distribution Checks (only from checks written by this user to avoid cross-user subtraction)
        $checkIdsForUser = $this->checks_written->pluck('id');
        $this->distribution_checks = $user_distribution
            ? Expense::where('distribution_id', $user_distribution)
                ->whereIn('check_id', $checkIdsForUser)
                ->whereNull('paid_by')
                ->whereNull('reimbursment')
                ->get()
            : collect();

        // Expenses Paid
        // Expenses Paid (any expense this user personally paid that is not a numeric reimbursement).
        // We intentionally include distribution expenses with paid_by set; distribution_checks only includes paid_by NULL, so no overlap.
        $this->expenses_paid =
            Expense::where('paid_by', $this->user->id)
                ->where(function ($q) { // exclude numeric reimbursements (those belong to paid_other_user_reimbursements)
                    $q->whereNull('reimbursment')
                      ->orWhereRaw('reimbursment REGEXP "[^0-9]"');
                })
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
                ->with('check')
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
                    return $query->whereYear('date', $this->year)
                        ->where('user_id', $this->user->id); // ensure reimbursement expenses are on this user's checks
                })
                ->get();

        // Distribution Expenses reclassification
        // 1. Start with all distribution expenses for the year.
        // 2. Remove those already counted in distribution_checks (user's own checks).
        // 3. Split remaining into conflicting (on another user's check) and regular (no check or null/this user ownership ambiguity).
        $this->distribution_expenses = collect();
        $this->conflicting_distribution_expenses = collect();
        if ($user_distribution) {
                        $allDistributionExpenses = Expense::with('check')
                            ->where('distribution_id', $user_distribution)
                            ->whereYear('date', $this->year)
                            ->whereNull('paid_by')
                            ->whereNull('reimbursment')
                            ->get();

            $userDistributionCheckExpenseIds = $this->distribution_checks->pluck('id');
            $remaining = $allDistributionExpenses
                ->whereNotIn('id', $userDistributionCheckExpenseIds)
                ->values();

            // Conflicting: has a check and that check belongs to a different user.
            $this->conflicting_distribution_expenses = $remaining->filter(function ($e) {
                return $e->check && $e->check->user_id && $e->check->user_id !== $this->user->id;
            })->values();

            // Regular distribution expenses (exclude conflicting)
            $this->distribution_expenses = $remaining->reject(function ($e) {
                return $e->check && $e->check->user_id && $e->check->user_id !== $this->user->id;
            })->values();

            // Prepare drill-down data
            $this->prepared_conflicting = $this->conflicting_distribution_expenses->map(function($e){
                return [
                    'id' => $e->id,
                    'date' => $e->date?->format('m/d/Y'),
                    'amount' => round((float)$e->amount,2),
                    'check_id' => $e->check?->id,
                    'check_number' => $e->check?->check_number,
                    'expense_link' => route('expenses.show',$e->id),
                    'check_link' => $e->check ? route('checks.show',$e->check->id) : null,
                ];
            })->toArray();
        }
    }

    public function getCheckDifference()
    {
        // New composition (per-check validation):
        // Check Amount ≈ Timesheets Paid (self) + Distribution Checks + Timesheets Paid Others + Expenses Paid
        //                 - Paid Other User Reimbursement Expenses (+ any timesheets_paid_by if applicable)
        $rawChecks = round($this->checks_written->sum('amount'), 2);
        // Composition only includes amounts actually on checks this user wrote.
        // Excludes timesheets_paid_by because those are paid on other users' checks.
        $composed = round($this->timesheets_paid->sum('amount'), 2)
            + round($this->distribution_checks->sum('amount'), 2)
            + round($this->timesheets_paid_others->sum('amount'), 2)
            + round($this->expenses_paid->sum('amount'), 2)
            - round($this->paid_other_user_reimbursements->sum('amount'), 2)
            - round($this->user_reimbursement_expenses->sum('amount'), 2); // deduct company-paid user reimbursements present on checks
        return round($rawChecks - $composed, 2);
    }

    public function render()
    {
        return view('livewire.users.finances');
    }
}
