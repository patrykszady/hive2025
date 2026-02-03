<?php

namespace App\Livewire\Forms;

use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\Rule;

use Livewire\Attributes\Validate;
use Livewire\Form;

class UserForm extends Form
{
    use AuthorizesRequests;

    public ?User $user = null;
    public ?int $user_id = null;

    #[Validate('required|min:2')]
    public $first_name = null;

    #[Validate('required|min:2')]
    public $last_name = null;

    #[Validate]
    public $email = null;

    #[Validate]
    public $cell_phone = null;

    #[Validate('nullable')]
    public $role = null;

    #[Validate('required_with:role')]
    public $hourly_rate = null;

    #[Validate('nullable')]
    public $via_vendor = null;

    public function rules()
    {
        return [
            'email' => [
                'required',
                'email',
                'min:4',
                Rule::unique('users', 'email')->ignore($this->user?->id),
            ],
            'cell_phone' => [
                'nullable',
                'digits:10',
                Rule::unique('users', 'cell_phone')->ignore($this->user?->id),
            ],
        ];
    }

    public function messages()
    {
        return [
            'email.unique' => 'This email is already in use by another user.',
            'cell_phone.unique' => 'This phone number is already in use by another user.',
        ];
    }

    // #[Rule('required')]
    // public $cell_phone = NULL;

    //         'user.cell_phone' => [
    //             'required',
    //             'digits:10',
    //             Rule::unique('users', 'cell_phone')->ignore($this->user->id),
    //         ],
    // #[Rule('required|digits:10')]
    // public $cell_phone = NULL;


    public function setUser(User $user)
    {
        $this->user = $user;
        $this->user_id = $user->id;

        $this->first_name = $user->first_name;
        $this->last_name = $user->last_name;
        $this->email = $user->email;
        $this->cell_phone = $user->cell_phone;
    }

    public function store()
    {
        $this->validate();

        // Check if user with this email already exists
        $existingUser = User::where('email', $this->email)->first();
        
        if ($existingUser) {
            // Update existing user with new information if needed
            $existingUser->update([
                'first_name' => $this->first_name,
                'last_name' => $this->last_name,
                'cell_phone' => $this->component->user_cell ?: $existingUser->cell_phone,
            ]);
            
            return $existingUser;
        }

        // Create new user if doesn't exist
        try {
            $user = User::create([
                'first_name' => $this->first_name,
                'last_name' => $this->last_name,
                'email' => $this->email,
                'cell_phone' => $this->component->user_cell,
            ]);
            
            return $user;
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            // Race condition - user was created between our check and insert
            // Fetch and return the existing user
            return User::where('email', $this->email)->firstOrFail();
        }
    }

    public function update()
    {
        $this->validate();

        $user = $this->user->update([
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'email' => $this->email,
            // 'cell_phone' => $this->component->user_cell,
        ]);

        return $user;
    }

    /**
     * Update only contact information (email and cell phone).
     * Used by client users editing other client members.
     */
    public function updateContactInfo(?string $cellPhone = null): bool
    {
        $rules = [
            'email' => [
                'required',
                'email',
                'min:4',
                Rule::unique('users', 'email')->ignore($this->user->id),
            ],
        ];

        // Validate cell phone for uniqueness if provided
        if ($cellPhone && strlen($cellPhone) === 10) {
            $this->cell_phone = $cellPhone;
            $rules['cell_phone'] = [
                'required',
                'digits:10',
                Rule::unique('users', 'cell_phone')->ignore($this->user->id),
            ];
        }

        $this->validate($rules);

        $data = [
            'email' => $this->email,
        ];

        if ($cellPhone && strlen($cellPhone) === 10) {
            $data['cell_phone'] = $cellPhone;
        }

        return $this->user->update($data);
    }
}
