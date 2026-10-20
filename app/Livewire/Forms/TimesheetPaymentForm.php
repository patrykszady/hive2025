<?php

namespace App\Livewire\Forms;

use App\Services\Timesheets\TimesheetPaymentRecorder;

use Illuminate\Validation\Rule;
use Livewire\Form;

class TimesheetPaymentForm extends Form
{
    public $first_name = '';
    public $date = null;
    public $paid_by = null;
    public $invoice = null;

    /**
     * Define the validation rules exclusively for form-level fields.
     */
    public function rules(): array
    {
        return [
            'first_name'      => 'nullable',
            'date'            => 'required|date|before_or_equal:today|after:2017-01-01',
            'paid_by' => [
                'nullable',
                "required_if:bank_account_id,\"\"",
                Rule::exists('user_vendor', 'user_id')->where('vendor_id', auth()->user()->vendor->id)->where('is_employed', 1),
            ],
            'invoice' => 'required_with:paid_by',
        ];
    }

    public function setUser($user)
    {
        $this->first_name = $user->first_name;
        // Date will be set by browser's local date via Alpine.js
    }

    /**
     * @see TimesheetPaymentRecorder::record() — the actual write, shared
     * with the app's TimesheetPaymentController. Returns the Check written,
     * or null when the payment settled entirely through `paid_by` (the web
     * component's save() tells the two apart by null, not the old
     * 'timesheets' string sentinel).
     */
    public function store()
    {
        $selection = [
            'weekly_timesheet_ids' => $this->selectedIds($this->component->selectedWeeklyTimesheets),
            'employee_weekly_timesheet_ids' => $this->selectedIds($this->component->selectedEmployeeWeeklyTimesheets),
            'paid_expense_ids' => $this->selectedIds($this->component->selectedUserPaidExpenses),
            'reimbursement_expense_ids' => $this->selectedIds($this->component->selectedUserReimbursementExpenses),
        ];

        return app(TimesheetPaymentRecorder::class)->record(
            auth()->user(),
            $this->component->user,
            $selection,
            (string) $this->date,
            $this->component->check_type ?: null,
            $this->toNullableInt($this->component->check_number),
            $this->toNullableInt($this->component->bank_account_id),
            $this->toNullableInt($this->paid_by),
            $this->invoice,
        );
    }

    /** @return list<int> */
    private function selectedIds(array $selection): array
    {
        return array_keys(array_filter($selection));
    }

    private function toNullableInt(mixed $value): ?int
    {
        return ($value === null || $value === '') ? null : (int) $value;
    }
}
