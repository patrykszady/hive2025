<?php

namespace App\Http\Requests\Api\App;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /api/app/v1/users/lookup — "Add Member"'s phone lookup step,
 * before role/pay-routing is chosen (UserCreate::user_cell_find() for the
 * vendor branch). Same gate as actually adding the member
 * (UserPolicy::create_team_member): this also reveals whether a phone
 * number already belongs to someone and which vendors they route pay
 * through, which only an admin who may add team members should be able
 * to probe.
 */
class LookupTeamMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        $vendor = $this->user()?->vendor;

        return $vendor !== null && (bool) $this->user()?->can('create_team_member', [User::class, $vendor->id]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'cell_phone' => ['required', 'digits:10'],
        ];
    }
}
