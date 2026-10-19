<?php

namespace App\Http\Requests\Api\App;

use App\Enums\LienWaiverType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/app/v1/lien-waivers — mirrors
 * App\Livewire\LienWaivers\Index::createWaiver()'s own validation exactly:
 * amount is required unless the type is "paid in full" (Unconditional
 * Final), and the payer fields are required only for the GC's own waiver
 * (a sub waiver's payer is the GC itself, filled in by the controller).
 */
class StoreLienWaiverRequest extends FormRequest
{
    /**
     * Real authorization (LienWaiverPolicy's create) happens in the
     * controller, same pattern as StorePaymentRequest.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The amount input formats with thousands separators as you type on the
     * web (createWaiver() strips them before validating); strip them here
     * too, before the 'numeric' rule ever sees the raw string.
     */
    protected function prepareForValidation(): void
    {
        if ($this->filled('amount')) {
            $this->merge(['amount' => str_replace(',', '', (string) $this->input('amount'))]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string|\Closure>
     */
    public function rules(): array
    {
        $isPaidInFull = $this->input('type') === LienWaiverType::UnconditionalFinal->value;
        $isSub = $this->filled('claimant_vendor_id')
            && (int) $this->input('claimant_vendor_id') !== (int) ($this->user()?->vendor?->id ?? 0);

        return [
            'project_id' => ['required', 'integer'],
            'type' => ['required', Rule::in(array_column(LienWaiverType::cases(), 'value'))],
            'claimant_vendor_id' => ['nullable', 'integer'],
            'amount' => [$isPaidInFull ? 'nullable' : 'required', 'nullable', 'numeric', 'gte:0'],
            'through_date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'payer_name' => [$isSub ? 'nullable' : 'required', 'nullable', 'string', 'max:255'],
            'payer_address' => ['nullable', 'string', 'max:255'],
            'payer_city_state_zip' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'amount.gte' => 'Amount cannot be negative.',
        ];
    }
}
