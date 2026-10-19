<?php

namespace App\Http\Requests\Api\App;

use App\Models\Vendor;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/** POST /api/app/v1/categories/vendors/{vendor}/reassign — VendorCategoryCard::reassignExpenseCategory(). */
class ReassignVendorExpenseCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('viewOptions', Vendor::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Nullable: the "Uncategorized" group (expenses with no
            // category_id) is a valid reassignment source.
            'from_category_id' => ['nullable', 'integer'],
            'to_category_id' => ['required', 'integer', 'exists:categories,id'],
        ];
    }
}
