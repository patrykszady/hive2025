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
     * inline error. "Create a new vendor for this person" (the web's
     * VendorCreate::viaVendor() step) is the app's own
     * POST /vendors (business_type 1099, owner_cell_phone = this same
     * phone) called BEFORE this one — see lookupForTeamMember() and
     * UserController::store()'s docblock; its id is simply passed here as
     * via_vendor_id, exactly as UserCreate::ViaVendorId() hands it back to
     * the form before save().
     *
     * @param  array{cell_phone: string, first_name?: ?string, last_name?: ?string, email?: ?string, nickname?: ?string, preferred_language?: ?string, role: int, hourly_rate?: ?float, via_vendor_id?: ?int}  $data
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

        // Routing pay through a vendor (role 2 only — the web hides the
        // picker once role is 1/Admin) is only ever onto a vendor this
        // PERSON already has a pivot on (an existing 1099/DBA of theirs, or
        // the one just created for them with them attached as its owner —
        // VendorCreate::viaVendor()'s hardcoded role_id 1/$0 attach, which
        // AppVendorDirectory::createVendor reuses) and never a Sub, exactly
        // the candidates the web's $via_vendors list offers
        // (UserCreate::user_cell_find()/ViaVendorId()).
        $viaVendorId = (int) $data['role'] === 1 ? null : ($data['via_vendor_id'] ?? null);

        if ($viaVendorId !== null) {
            // withoutGlobalScopes(): VendorScope limits a plain vendors()
            // query to companies already linked to the ACTING admin's own
            // vendor, but a 1099/DBA this person owns is typically its own
            // unrelated tenant — the pivot on $person is what matters here,
            // not whether the acting admin's company can otherwise see it.
            $eligible = $person->vendors()
                ->withoutGlobalScopes()
                ->where('vendors.id', $viaVendorId)
                ->where('vendors.business_type', '!=', 'Sub')
                ->exists();

            if (! $eligible) {
                throw ValidationException::withMessages([
                    'via_vendor_id' => 'Choose a vendor already linked to this person.',
                ]);
            }
        }

        if (! $person->vendors()->where('vendor_id', $vendor->id)->exists()) {
            $person->vendors()->attach($vendor->id, [
                'role_id' => $data['role'],
                'hourly_rate' => $data['hourly_rate'] ?? null,
                'start_date' => today()->format('Y-m-d'),
                'via_vendor_id' => $viaVendorId,
            ]);
        }

        return $person;
    }

    /**
     * "Add Member"'s phone lookup, before the role/pay-routing step —
     * UserCreate::user_cell_find() for the vendor branch, plus the
     * $via_vendors list it (and ViaVendorId()) populate: vendors this
     * person already has a pivot on, excluding Subs, the same candidates
     * the web's "Member Type" cards offer.
     *
     * @return array{user: ?array{id: int, first_name: string, last_name: string, email: ?string}, already_employed: bool, via_vendor_options: list<array{id: int, name: string, business_type: string}>}
     */
    public function lookupForTeamMember(Vendor $vendor, string $cellPhone): array
    {
        $person = $this->people->findByPhone($cellPhone);

        if (! $person) {
            return ['user' => null, 'already_employed' => false, 'via_vendor_options' => []];
        }

        return [
            'user' => ['id' => $person->id, 'first_name' => $person->first_name, 'last_name' => $person->last_name, 'email' => $person->email],
            'already_employed' => $vendor->users()->where('user_id', $person->id)->employed()->exists(),
            'via_vendor_options' => $person->vendors()
                ->withoutGlobalScopes()
                ->where('vendors.business_type', '!=', 'Sub')
                ->get()
                ->map(fn (Vendor $v) => ['id' => $v->id, 'name' => $v->business_name, 'business_type' => $v->business_type])
                ->values()
                ->all(),
        ];
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
        // on that pivot (showPosition on the web) AND the caller actually
        // sent a `position` key. The web always sends it once showPosition
        // is true (the field stays rendered, pre-filled from the pivot,
        // whether or not it's touched); a caller that omits the key
        // entirely — e.g. a client that hides the field for some other
        // reason — must never wipe a title that was never shown to clear.
        // array_key_exists (not `$data['position'] ?? null`) is what makes
        // "omitted" distinguishable from "sent empty/null" here.
        $authVendorId = $actingUser->vendor?->id;
        if ($authVendorId && array_key_exists('position', $data) && $user->vendors()->where('vendor_id', $authVendorId)->exists()) {
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
