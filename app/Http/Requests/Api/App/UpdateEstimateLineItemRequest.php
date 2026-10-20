<?php

namespace App\Http\Requests\Api\App;

/**
 * PUT /api/app/v1/estimates/{estimate}/line-items/{lineItem} — same rules as
 * creating one; the catalog line item itself (name) never changes on an
 * update, same as App\Livewire\Forms\EstimateLineItemForm::update().
 */
class UpdateEstimateLineItemRequest extends StoreEstimateLineItemRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['line_item_id']);

        return $rules;
    }
}
