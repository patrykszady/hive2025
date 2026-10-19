<?php

namespace App\Http\Requests\Api\App;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * "New Client" — App\Livewire\Clients\ClientCreate::save() +
 * App\Livewire\Forms\ClientForm::store(). Either `existing_client_id` is
 * given (the contact already belongs to one of our clients — the web's
 * "Existing Clients" picker just takes you there, nothing is created or
 * attached) or the full person + business details are, and a new client
 * is created. Resource-level authorization (admin-only) happens in the
 * controller.
 */
class StoreClientRequest extends FormRequest
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
            'existing_client_id' => ['nullable', 'integer'],

            'cell_phone' => ['required_without:existing_client_id', 'digits:10'],
            'first_name' => ['nullable', 'string', 'min:2'],
            'last_name' => ['nullable', 'string', 'min:2'],
            'email' => ['nullable', 'email', 'min:4'],
            'nickname' => ['nullable', 'string', 'max:255'],
            'preferred_language' => ['nullable', 'string', 'max:255'],

            'business_name' => ['nullable', 'string', 'min:3'],
            'address' => ['nullable', 'string', 'max:255'],
            'address_2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'max:255'],
            'zip_code' => ['nullable', 'string', 'max:20'],
            'source' => ['nullable', 'string', 'min:1'],
        ];
    }
}
