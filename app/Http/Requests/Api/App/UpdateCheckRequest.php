<?php

namespace App\Http\Requests\Api\App;

use App\Models\Check;
use App\Services\Checks\CheckUpdateService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /api/app/v1/checks/{check} — mirrors App\Livewire\Forms\CheckForm's
 * own rules() exactly, via the same App\Services\Checks\CheckUpdateService
 * the Livewire form now delegates to, so both validate (and the check
 * number's per-bank-account uniqueness) identically.
 */
class UpdateCheckRequest extends FormRequest
{
    /**
     * Real authorization (CheckPolicy::update()) happens in the controller
     * — same pattern as StorePaymentRequest. CheckController's own route
     * parameter stays a plain int (not an implicitly-bound model, so its
     * visibleCheck() can tell a missing check apart from one outside the
     * tenant), so this looks the check up itself for the rules that need it.
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
        $check = Check::withoutGlobalScopes()->find($this->route('check'));
        abort_unless($check, 404);

        $companyVendorId = $this->user()->vendor->id;
        $bankAccountId = $this->integer('bank_account_id') ?: null;

        return app(CheckUpdateService::class)->rules($check, $companyVendorId, $bankAccountId);
    }
}
