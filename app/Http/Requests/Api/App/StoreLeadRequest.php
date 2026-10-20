<?php

namespace App\Http\Requests\Api\App;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/app/v1/leads — mirrors App\Livewire\Leads\LeadCreate::save()'s
 * own validation exactly: a name, and at least one of email/phone to reach
 * them. `force` resubmits past a duplicate-contact warning (see
 * LeadController::store() and App\Services\Leads\LeadDuplicateFinder),
 * matching the web's "Create anyway" button.
 */
class StoreLeadRequest extends FormRequest
{
    /**
     * Real authorization (LeadPolicy's viewAny — see LeadsIndex::render(),
     * which every Lead action on the web sits behind) happens in the
     * controller, same pattern as UpdateProjectStatusRequest.
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
            'full_name' => ['required', 'string', 'max:120'],
            'email' => ['nullable', 'email'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:255'],
            'message' => ['nullable', 'string'],
            // No 'origin' rule: like LeadCreate's disabled Origin field, a
            // hand-entered lead's origin is always "Manual" — never
            // client-chosen (see LeadController::store()).
            // An existing user to link BEFORE provisioning runs, like
            // LeadCreate's "User" dropdown (userChoices()/attachUser()) —
            // LeadController::store() resolves it through the same
            // vendor-scoped candidate query the dropdown itself lists.
            'user_id' => ['nullable', 'integer'],
            'consult_within_the_hour' => ['nullable', 'boolean'],
            'force' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function ($validator) {
            // A picked `user_id` (LeadCreate::attachUser()'s create-mode
            // branch) may still supply an email/phone the typed form left
            // blank — LeadController::store() resolves that candidate and
            // re-checks reachability itself once the fallback has run,
            // same as the web (attachUser() fills the properties save()'s
            // own check reads, before this validation would ever fire).
            if ($this->filled('user_id')) {
                return;
            }

            if (blank($this->input('email')) && blank($this->input('phone'))) {
                $validator->errors()->add('email', 'An email or phone number is needed to reach them.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'full_name.required' => 'Enter a name.',
        ];
    }
}
