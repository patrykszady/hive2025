<?php

namespace App\Http\Requests\Api\App;

use App\Models\LineItem;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/app/v1/estimates/{estimate}/line-items/{lineItem}/credit —
 * mirrors App\Livewire\LineItems\EstimateLineItemCreate::creditToChangeOrder()'s
 * own gate: the LineItem catalog's 'create' ability, not EstimatePolicy.
 */
class CreditEstimateLineItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('create', LineItem::class);
    }

    public function rules(): array
    {
        return [];
    }
}
