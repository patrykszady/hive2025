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

    /**
     * Optional quantity/cost for a partial credit — the web credits whatever
     * the line item's form holds (EstimateLineItemCreate::creditToChangeOrder()).
     * Left out, the whole line is credited.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'quantity' => ['nullable', 'numeric', 'gt:0'],
            'cost' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
