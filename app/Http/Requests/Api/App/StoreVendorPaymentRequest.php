<?php

namespace App\Http\Requests\Api\App;

use App\Models\Check;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/app/v1/vendors/{vendor}/payment — mirrors
 * App\Livewire\Forms\VendorPaymentForm's own rules
 * (date/paid_by/invoice) and App\Traits\HandlesChecks' check rules
 * exactly, reshaped for a `projects` array (id/amount) instead of the
 * web's whole-projects-array component property. Real authorization
 * (CheckPolicy::create) happens in the controller; the check total is
 * recomputed from the submitted projects/reimbursements there (never
 * trusted from the client), same as StorePaymentRequest's own pattern.
 */
class StoreVendorPaymentRequest extends FormRequest
{
    /** Mirrors VendorPaymentCreate::save(), so an invalid submission from a non-Admin/1099 403s before field validation runs. */
    public function authorize(): bool
    {
        return $this->user()->can('create', Check::class);
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
                Rule::unique('checks', 'check_number')->where(function ($query) {
                    $query->where('check_type', 'Check')->whereNull('deleted_at')->where('bank_account_id', $this->input('bank_account_id'));
                }),
            ],
            'paid_by' => [
                'nullable', 'required_without:bank_account_id', 'integer',
                Rule::exists('user_vendor', 'user_id')->where('vendor_id', $vendorId)->where('is_employed', 1),
            ],
            'invoice' => ['required_with:paid_by', 'nullable', 'string', 'max:255'],
            'projects' => ['required', 'array', 'min:1'],
            'projects.*.id' => ['required', 'integer'],
            'projects.*.amount' => ['nullable', 'numeric', 'regex:/^-?\d+(\.\d{1,2})?$/'],
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
            'projects.required' => 'Add at least one project.',
            'projects.min' => 'Add at least one project.',
        ];
    }
}
