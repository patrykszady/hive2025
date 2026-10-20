<?php

namespace App\Http\Requests\Api\App;

use App\Models\LineItem;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/app/v1/line-items — mirrors App\Livewire\Forms\LineItemForm's
 * own rules exactly. Authorized directly against LineItem::class's 'create'
 * ability (the only ability LineItemPolicy implements for writes; 'update'
 * is an unused stub on the web too — see App\Livewire\Forms\LineItemForm).
 */
class StoreLineItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('create', LineItem::class);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:3'],
            'desc' => ['required', 'string', 'min:3'],
            'notes' => ['nullable', 'string', 'min:3'],
            'category' => ['required', 'string', 'min:3'],
            'sub_category' => ['nullable', 'string', 'min:3'],
            'unit_type' => ['required', 'string'],
            'cost' => ['required', 'numeric', 'regex:/^-?\d+(\.\d{1,2})?$/'],
            'allowances' => ['nullable', 'array'],
            'allowances.*.id' => ['nullable', 'integer'],
            'allowances.*.description' => ['nullable', 'string'],
            'allowances.*.pricing_mode' => ['nullable', 'in:per_unit,lump_sum'],
            'allowances.*.unit_amount' => ['nullable', 'numeric'],
            'allowances.*.amount' => ['nullable', 'numeric'],
        ];
    }

    public function messages(): array
    {
        return [
            'cost.regex' => 'Amount format is incorrect. Use up to 2 decimal places.',
        ];
    }
}
