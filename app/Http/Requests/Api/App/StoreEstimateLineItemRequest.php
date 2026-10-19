<?php

namespace App\Http\Requests\Api\App;

use App\Models\LineItem;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/app/v1/estimates/{estimate}/line-items — mirrors
 * App\Livewire\Forms\EstimateLineItemForm's own rules exactly. Authorized
 * directly against the LineItem catalog's 'create' ability, the same gate
 * App\Livewire\LineItems\EstimateLineItemCreate uses for every line-item
 * write on an estimate (not EstimatePolicy — adding a line item is gated by
 * "can this user manage the catalog", not by estimate ownership).
 */
class StoreEstimateLineItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('create', LineItem::class);
    }

    public function rules(): array
    {
        return [
            'section_id' => ['required', 'integer'],
            'line_item_id' => ['required', 'integer'],
            'category' => ['required', 'string', 'min:3'],
            'sub_category' => ['nullable', 'string', 'min:3'],
            'unit_type' => ['required', 'string'],
            'quantity' => ['required', 'numeric', 'min:0.1'],
            'cost' => ['required', 'numeric'],
            'desc' => ['required', 'string', 'min:3'],
            'notes' => ['nullable', 'string', 'min:3'],
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
            'category.min' => 'Category must be at least 3 characters.',
            'desc.required' => 'Description is required.',
            'cost.required' => 'Amount is required.',
        ];
    }
}
