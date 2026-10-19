<?php

namespace App\Http\Requests\Api\App;

use App\Models\Expense;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Validates a new expense from the app's create form — the same rules
 * ExpenseForm's #[Validate] attributes and rules() enforce (amount format,
 * date window, project-required-unless-split), plus the splits array the
 * app's own simplified split editor sends.
 *
 * authorize() mirrors ExpenseForm::store()'s ordering exactly: it calls
 * `$this->authorize('create', Expense::class)` BEFORE `$this->validate()`,
 * so a non-admin gets 403 even when the body would otherwise fail
 * validation (e.g. no project_id) — checking here, not in the controller,
 * is what gives the same before-validation ordering.
 */
class StoreExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('create', Expense::class);
    }

    /**
     * A receipt photo attached alongside JSON fields means the request is
     * multipart/form-data, which can't carry a nested `splits` array
     * natively — the app JSON-encodes it into a single field in that case.
     * Decode it back into an array before validation either way.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('splits'))) {
            $decoded = json_decode((string) $this->input('splits'), true);
            $this->merge(['splits' => is_array($decoded) ? $decoded : []]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'regex:/^-?\d+(\.\d{1,2})?$/'],
            'date' => ['required', 'date', 'before_or_equal:today', 'after:2017-01-01'],
            'vendor_id' => ['required', 'integer', 'exists:vendors,id'],
            'project_id' => ['nullable', 'integer'],
            'note' => ['nullable', 'string', 'max:2000'],
            'invoice' => ['nullable', 'string', 'max:255'],
            'paid_by' => ['nullable', 'integer', 'exists:users,id'],
            'reimbursement' => ['nullable', 'string', 'max:255'],
            'splits' => ['nullable', 'array'],
            'splits.*.amount' => ['required', 'numeric', 'regex:/^-?\d+(\.\d{1,2})?$/', 'not_in:0'],
            'splits.*.project_id' => ['required', 'integer'],
            'splits.*.note' => ['nullable', 'string', 'max:2000'],
            'splits.*.reimbursement' => ['nullable', 'string', 'max:255'],
            'receipt' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:20480'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'amount.regex' => 'Amount format is incorrect. Use e.g. 2145.36, no commas and at most two decimal digits.',
            'date.before_or_equal' => 'Date cannot be in the future.',
            'date.after' => 'Date cannot be before 2017-01-01.',
            'receipt.mimes' => 'Receipt must be a PDF, JPG, or PNG.',
            'receipt.max' => 'Receipt must be less than 20MB.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $splits = $this->input('splits', []);
            $hasSplits = is_array($splits) && count($splits) > 0;

            if ($hasSplits) {
                $sum = round(array_sum(array_map(fn ($split) => (float) ($split['amount'] ?? 0), $splits)), 2);
                $amount = round((float) $this->input('amount', 0), 2);

                if (abs($amount - $sum) >= 0.01) {
                    $validator->errors()->add('splits', 'Split amounts must add up to the expense amount.');
                }

                return;
            }

            if (! $this->filled('project_id')) {
                $validator->errors()->add('project_id', 'Project is required unless the expense is split.');
            }
        });
    }
}
