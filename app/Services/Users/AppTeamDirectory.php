<?php

namespace App\Services\Users;

use App\Models\User;
use App\Models\Vendor;
use App\Services\People\AppPersonResolver;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The app's Team (Users) tab — reuses the exact rules
 * App\Livewire\Users\UsersIndex, the vendor branch of
 * App\Livewire\Users\UserCreate, App\Livewire\Forms\UserForm and
 * App\Livewire\Users\UserNotificationSettings apply on the web.
 */
class AppTeamDirectory
{
    public function __construct(private AppPersonResolver $people)
    {
    }

    /**
     * Employed members of $vendor, in the same shape the web's team card
     * loads — App\Livewire\Users\UsersIndex::loadUsers() for the
     * 'vendors.show' view, which only ever shows employed rows.
     *
     * @return Collection<int, User>
     */
    public function teamMembers(Vendor $vendor): Collection
    {
        return $vendor->users()->employed()->get();
    }

    /**
     * Add a team member — the vendor branch of
     * App\Livewire\Users\UserCreate::save() for an existing vendor id (not
     * NEW; the app never creates a brand-new company this way). A phone
     * already employed here is refused exactly like user_cell_find's
     * inline error. The "via vendor" (1099/Sub payroll routing) and
     * "create a new vendor for this person" branches stay web-only — see
     * AppUsersTest and the task report.
     *
     * @param  array{cell_phone: string, first_name?: ?string, last_name?: ?string, email?: ?string, nickname?: ?string, preferred_language?: ?string, role: int, hourly_rate?: ?float}  $data
     */
    public function addTeamMember(Vendor $vendor, array $data): User
    {
        $existingByPhone = $this->people->findByPhone($data['cell_phone']);

        if ($existingByPhone && $vendor->users()->where('user_id', $existingByPhone->id)->employed()->exists()) {
            throw ValidationException::withMessages([
                'cell_phone' => $existingByPhone->first_name.' already belongs to Vendor.',
            ]);
        }

        $person = $this->people->resolveOrCreate($data);

        if (! $person->vendors()->where('vendor_id', $vendor->id)->exists()) {
            $person->vendors()->attach($vendor->id, [
                'role_id' => $data['role'],
                'hourly_rate' => $data['hourly_rate'] ?? null,
                'start_date' => today()->format('Y-m-d'),
                'via_vendor_id' => null,
            ]);
        }

        return $person;
    }

    /**
     * Edit a user's own identity fields — App\Livewire\Forms\UserForm::update().
     * Cell phone is deliberately never touched here: the web's edit form
     * disables that field once the person already exists, so this mirrors
     * that by simply never writing it.
     *
     * @param  array{first_name: string, last_name: string, nickname?: ?string, preferred_language?: ?string, email: string, position?: ?string}  $data
     */
    public function updateUser(User $user, User $actingUser, array $data): User
    {
        $user->update([
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'nickname' => $data['nickname'] ?? null,
            'preferred_language' => $data['preferred_language'] ?: 'English',
            'email' => $data['email'],
        ]);

        // Business title lives on the user<->vendor pivot for the ACTING
        // admin's vendor — only written when the edited user actually sits
        // on that pivot (showPosition on the web).
        $authVendorId = $actingUser->vendor?->id;
        if ($authVendorId && $user->vendors()->where('vendor_id', $authVendorId)->exists()) {
            $user->vendors()->updateExistingPivot($authVendorId, [
                'position' => trim((string) ($data['position'] ?? '')) ?: null,
            ]);
        }

        return $user;
    }

    /**
     * "Remove User from Vendor" — App\Livewire\Users\UserCreate::removeMember(),
     * exactly: ends employment on $user's own PRIMARY vendor (never an
     * arbitrary one), today, and clears is_employed. The controller
     * authorizes against that same vendor (VendorPolicy::update) before
     * calling this, same gate the web's removeMember() uses.
     */
    public function removeTeamMember(User $user): void
    {
        $user->vendor->users()->wherePivot('is_employed', '1')
            ->updateExistingPivot($user->id, [
                'end_date' => today()->format('Y-m-d'),
                'is_employed' => 0,
                'updated_at' => now(),
            ]);
    }

    /**
     * Current notification settings for $user, seeded from the vendor's
     * business hours the same way UserNotificationSettings::mount() does.
     *
     * @return array{realtime_email: bool, morning_email: bool, morning_sms: bool, evening_email: bool, evening_sms: bool, realtime_start: string, realtime_end: string}
     */
    public function notificationSettings(User $user): array
    {
        $hours = $user->vendor?->businessHours();

        $defaults = [
            'realtime_email' => true,
            'morning_email' => false,
            'morning_sms' => false,
            'evening_email' => false,
            'evening_sms' => false,
            'realtime_start' => $hours['start'] ?? '07:00',
            'realtime_end' => $hours['end'] ?? '18:00',
        ];

        $setting = $user->notificationSetting;

        if (! $setting) {
            return $defaults;
        }

        return [
            'realtime_email' => (bool) $setting->realtime_email,
            'morning_email' => (bool) $setting->morning_email,
            'morning_sms' => (bool) $setting->morning_sms,
            'evening_email' => (bool) $setting->evening_email,
            'evening_sms' => (bool) $setting->evening_sms,
            'realtime_start' => $setting->realtime_start ?? $defaults['realtime_start'],
            'realtime_end' => $setting->realtime_end ?? $defaults['realtime_end'],
        ];
    }

    /**
     * App\Livewire\Users\UserNotificationSettings::save() — realtime SMS is
     * always forced on, same as the web (a task change must always be able
     * to reach the crew by text).
     *
     * @param  array{realtime_email: bool, morning_email: bool, morning_sms: bool, evening_email: bool, evening_sms: bool, realtime_start: string, realtime_end: string}  $data
     */
    public function saveNotificationSettings(User $user, array $data): void
    {
        $user->notificationSetting()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'realtime_email' => $data['realtime_email'],
                'realtime_sms' => true,
                'morning_email' => $data['morning_email'],
                'morning_sms' => $data['morning_sms'],
                'evening_email' => $data['evening_email'],
                'evening_sms' => $data['evening_sms'],
                'realtime_start' => $data['realtime_start'],
                'realtime_end' => $data['realtime_end'],
            ]
        );
    }
}
