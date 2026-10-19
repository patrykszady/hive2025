<?php

namespace App\Http\Requests\Api\App;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/app/v1/payments — mirrors App\Livewire\Forms\PaymentForm's own
 * rules exactly (date/invoice/amount), reshaped for a `projects` array
 * instead of a client-side-filtered component property. A row with a blank
 * amount is fine (not every eligible project need be paid); a row with an
 * explicit 0.00 is rejected, same as the web.
 */
class StorePaymentRequest extends FormRequest
{
    /**
     * Real authorization (Payment::class 'create') happens in the
     * controller, mirroring PaymentCreate::mount()'s
     * $this->authorize('create', Payment::class) — same pattern as
     * UpdateProjectStatusRequest.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string|\Closure>
     */
    public function rules(): array
    {
        return [
            'date' => ['required', 'date', 'before_or_equal:today', 'after:2017-01-01'],
            'reference' => ['required', 'string', 'max:255'],
            'note' => ['nullable', 'string'],
            'projects' => ['required', 'array', 'min:1'],
            'projects.*.id' => ['required', 'integer'],
            'projects.*.amount' => [
                'nullable',
                'numeric',
                'regex:/^-?\d+(\.\d{1,2})?$/',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value === null || $value === '') {
                        return;
                    }
                    if ((float) $value == 0.0) {
                        $fail('Amount cannot be 0.00');
                    }
                },
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'date.before_or_equal' => 'The date cannot be in the future.',
            'date.after' => 'That date is too far in the past.',
            'reference.required' => 'Enter a reference or invoice number.',
            'projects.required' => 'Add at least one project.',
            'projects.min' => 'Add at least one project.',
        ];
    }
}
