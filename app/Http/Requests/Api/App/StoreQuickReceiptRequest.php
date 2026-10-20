<?php

namespace App\Http\Requests\Api\App;

use App\Models\Expense;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/app/v1/expenses/quick-receipt — the app's "Upload Receipt"
 * quick-capture flow. Same file rule and authorization ordering as
 * StoreExpenseRequest (ExpenseCreate::uploadReceipt() authorizes
 * `create`, Expense::class, the same gate the web's "Upload Receipt"
 * button sits behind, before validating).
 */
class StoreQuickReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('create', Expense::class);
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('is_material_order')) {
            $this->merge(['is_material_order' => $this->boolean('is_material_order')]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:20480'],
            'is_material_order' => ['nullable', 'boolean'],
            'belongs_to_vendor_id' => ['nullable', 'integer'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Please select a file to upload.',
            'file.mimes' => 'File must be a PDF, JPG, or PNG.',
            'file.max' => 'File must be less than 20MB.',
        ];
    }
}
