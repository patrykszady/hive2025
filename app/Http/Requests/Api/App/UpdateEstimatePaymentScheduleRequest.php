<?php

namespace App\Http\Requests\Api\App;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /api/app/v1/estimates/{estimate}/payment-schedule — mirrors the
 * payments/dates slice of App\Livewire\Estimates\EstimateAccept::save().
 * Real authorization ('update' on the estimate) happens in the controller.
 */
class UpdateEstimatePaymentScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'payments' => ['array'],
            'payments.*.description' => ['nullable', 'string', 'max:255'],
            'payments.*.amount' => ['nullable', 'string', 'max:20'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
        ];
    }
}
