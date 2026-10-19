<?php

namespace App\Http\Requests\Api\App;

/**
 * Editing an expense validates identically to creating one — see
 * StoreExpenseRequest, which this reuses wholesale (mirrors ExpenseForm,
 * whose update() runs the same rules() as store()).
 */
class UpdateExpenseRequest extends StoreExpenseRequest
{
}
