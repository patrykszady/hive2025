<?php

namespace App\Services\People;

use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Find-or-create a person by phone number — the shared heart of
 * App\Livewire\Forms\UserForm::store() and the phone lookup
 * App\Livewire\Users\UserCreate::user_cell_find() does before it, reused
 * by both the app's "Add Client Person" and "Add Team Member" flows
 * (App\Services\Clients\AppClientDirectory, App\Services\Users\AppTeamDirectory)
 * exactly as the web reuses the same UserCreate component for both.
 */
class AppPersonResolver
{
    /** The person already on file for this phone number, if any. */
    public function findByPhone(string $cellPhone): ?User
    {
        return User::where('cell_phone', $cellPhone)->first();
    }

    /**
     * A phone match always wins and is returned as-is: the web disables
     * every identity field once an existing phone is found, so whatever
     * name/email came in with this request is never applied to someone
     * else's account. Otherwise creates a new person, enforcing the same
     * required name/unique-email rules UserForm's #[Validate] attributes do.
     *
     * @param  array{cell_phone: string, first_name?: ?string, last_name?: ?string, email?: ?string, nickname?: ?string, preferred_language?: ?string}  $data
     */
    public function resolveOrCreate(array $data): User
    {
        $existingByPhone = $this->findByPhone($data['cell_phone']);

        if ($existingByPhone) {
            return $existingByPhone;
        }

        if (
            ! filled($data['first_name'] ?? null)
            || ! filled($data['last_name'] ?? null)
            || ! filled($data['email'] ?? null)
        ) {
            throw ValidationException::withMessages([
                'first_name' => 'A name and email are required for a new person.',
            ]);
        }

        if (User::where('email', $data['email'])->exists()) {
            throw ValidationException::withMessages([
                'email' => 'This email is already in use by another user.',
            ]);
        }

        try {
            return User::create([
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'nickname' => $data['nickname'] ?? null,
                'preferred_language' => $data['preferred_language'] ?? 'English',
                'email' => $data['email'],
                'cell_phone' => $data['cell_phone'],
            ]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            // Race condition — same recovery as UserForm::store().
            return User::where('email', $data['email'])->firstOrFail();
        }
    }
}
