<?php

namespace App\Http\Requests\Api\App;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/app/v1/expenses/{expense}/associate — mirrors
 * App\Livewire\Expenses\ExpensesAssociated's own `associate_expense`
 * rule ('required'); the existence check is left to the controller's
 * Expense::findOrFail() (ExpenseScope-filtered, same as the Livewire
 * component's), so an id outside the tenant 404s rather than failing
 * validation.
 */
class AssociateExpenseRequest extends FormRequest
{
    /**
     * Real authorization (viewing $expense) happens in the controller via
     * ExpensePolicy::view() — same pattern as StorePaymentRequest.
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
            'associate_expense_id' => ['required', 'integer'],
        ];
    }
}
