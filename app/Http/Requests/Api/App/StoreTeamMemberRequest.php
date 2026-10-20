<?php

namespace App\Http\Requests\Api\App;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * "Add Member" on the team — the vendor branch of
 * App\Livewire\Users\UserCreate::save() for an existing vendor id.
 * Resource-level authorization (UserPolicy::create_team_member) happens in
 * the controller.
 */
class StoreTeamMemberRequest extends FormRequest
{
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
            'cell_phone' => ['required', 'digits:10'],
            'first_name' => ['nullable', 'string', 'min:2'],
            'last_name' => ['nullable', 'string', 'min:2'],
            'email' => ['nullable', 'email', 'min:4'],
            'nickname' => ['nullable', 'string', 'max:255'],
            'preferred_language' => ['nullable', 'string', 'max:255'],
            'role' => ['required', Rule::in([1, 2])],
            'hourly_rate' => ['required_with:role', 'numeric', 'min:0'],
            // "Member Type" on the web (form.blade.php's via_vendor radio
            // group): route this member's pay through a 1099/DBA vendor
            // they already own instead of direct payroll. Only meaningful
            // for role 2 (Member) — AppTeamDirectory::addTeamMember ignores
            // it for an Admin, same as the web hides the picker entirely
            // once role is 1. The vendor must already have this person
            // attached (any role) and must not be a Sub — checked in the
            // service, where the resolved person is known.
            'via_vendor_id' => ['nullable', 'integer', 'exists:vendors,id'],
        ];
    }
}
