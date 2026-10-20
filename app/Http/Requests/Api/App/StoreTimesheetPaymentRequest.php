<?php

namespace App\Http\Requests\Api\App;

use App\Models\Timesheet;
use App\Services\Checks\CheckNumberSuggester;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/app/v1/timesheets/payment/{user} — mirrors
 * App\Livewire\Forms\TimesheetPaymentForm's own rules
 * (date/paid_by/invoice) and App\Traits\HandlesChecks' check rules
 * (bank_account_id/check_type/check_number) exactly, reshaped for a
 * `selection` of ids instead of client-side-filtered component arrays.
 * Real authorization (TimesheetPolicy::viewAnyPayment) happens in the
 * controller; the payment total is recomputed from the database there too
 * (TimesheetPaymentOptions::totalForSelection) rather than trusted from the
 * client.
 */
class StoreTimesheetPaymentRequest extends FormRequest
{
    /**
     * Mirrors TimesheetPaymentCreate::confirmPayment()/save(), which
     * re-check viewAnyPayment themselves rather than reusing mount()'s
     * looser viewPayment gate — so an incomplete submission from someone
     * who may only VIEW this page (e.g. the member themself) 403s before
     * field validation ever runs, not after.
     */
    public function authorize(): bool
    {
        return $this->user()->can('viewAnyPayment', Timesheet::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string|\Closure>
     */
    public function rules(): array
    {
        $vendorId = $this->user()->vendor->id;

        return [
            'date' => ['required', 'date', 'before_or_equal:today', 'after:2017-01-01'],
            'bank_account_id' => [
                'nullable', 'required_without:paid_by', 'integer',
                Rule::exists('bank_accounts', 'id')->where('vendor_id', $vendorId),
            ],
            'check_type' => ['required_with:bank_account_id', 'nullable', 'in:Check,Transfer,Cash'],
            'check_number' => [
                'required_if:check_type,Check', 'nullable', 'integer',
                // Unique across every account under the same physical bank
                // (CheckNumberSuggester::siblingAccountIds()), not just the
                // one submitted account — a re-linked Plaid item duplicates
                // accounts under one bank.
                app(CheckNumberSuggester::class)->uniqueRule($this->filled('bank_account_id') ? (int) $this->input('bank_account_id') : null),
            ],
            'paid_by' => [
                'nullable', 'required_without:bank_account_id', 'integer',
                Rule::exists('user_vendor', 'user_id')->where('vendor_id', $vendorId)->where('is_employed', 1),
            ],
            'invoice' => ['required_with:paid_by', 'nullable', 'string', 'max:255'],
            'weekly_timesheet_ids' => ['array'],
            'weekly_timesheet_ids.*' => ['integer'],
            'employee_weekly_timesheet_ids' => ['array'],
            'employee_weekly_timesheet_ids.*' => ['integer'],
            'paid_expense_ids' => ['array'],
            'paid_expense_ids.*' => ['integer'],
            'reimbursement_expense_ids' => ['array'],
            'reimbursement_expense_ids.*' => ['integer'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'check_number.unique' => 'Check already recorded.',
        ];
    }
}
