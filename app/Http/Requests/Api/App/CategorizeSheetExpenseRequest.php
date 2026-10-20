<?php

namespace App\Http\Requests\Api\App;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /api/app/v1/sheets/categorize/{expense} — mirrors
 * App\Livewire\Sheets\SheetShow::categorizeExpense()'s own parameter.
 */
class CategorizeSheetExpenseRequest extends FormRequest
{
    /**
     * Real authorization (SheetPolicy::viewAny — Admin-only) happens in the
     * controller — same pattern as AssociateExpenseRequest.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'category_id' => ['required', 'integer', 'exists:categories,id'],
        ];
    }
}
