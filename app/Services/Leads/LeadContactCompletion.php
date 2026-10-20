<?php

namespace App\Services\Leads;

use App\Models\Client;
use App\Models\Lead;
use App\Models\User;
use App\Services\LeadContactProvisioner;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * Completing a lead's contact — the Details tab's "what's still missing"
 * tools, shared by App\Livewire\Leads\LeadCreate (the web modal) and the
 * Hive Contractors app's LeadController: entering a missing phone number,
 * picking one of the address candidates CompleteLeadAddress found, linking
 * an existing user, or creating one from the name/email/phone on file.
 * Every write here ends by calling App\Services\LeadContactProvisioner
 * exactly like the web does, so a lead completed from the app provisions
 * the same client record a lead completed on the web would.
 */
class LeadContactCompletion
{
    public function __construct(private LeadContactProvisioner $provisioner)
    {
    }

    /**
     * Mirrors LeadCreate::needsPhone(): persisted state only — a half-typed
     * or invalid number never opens the Message tab.
     */
    public function needsPhone(Lead $lead): bool
    {
        if (! $lead->exists) {
            return false;
        }

        if (trim((string) ($lead->lead_data['phone'] ?? '')) !== '') {
            return false;
        }

        return ! ($lead->user?->hasRoutablePhone() ?? false);
    }

    /** @param  array<string, mixed>  $parts */
    public function addressIsComplete(array $parts): bool
    {
        return trim((string) ($parts['address'] ?? '')) !== ''
            && trim((string) ($parts['city'] ?? '')) !== ''
            && trim((string) ($parts['state'] ?? '')) !== ''
            && trim((string) ($parts['zip_code'] ?? '')) !== '';
    }

    /**
     * Mirrors LeadCreate::missingContactInfo(): what's still missing before
     * this lead can be replied to.
     *
     * @return array<int, string>
     */
    public function missingContactInfo(Lead $lead, ?Client $client): array
    {
        if (! $lead->exists) {
            return [];
        }

        $data = $lead->lead_data;
        $contact = $lead->user;

        $missing = [];

        $email = trim((string) ($data['email'] ?? ''));
        if ($email === '' && ! ($contact?->hasRoutableEmail() ?? false)) {
            $missing[] = 'an email address';
        }

        if ($this->needsPhone($lead)) {
            $missing[] = 'a phone number';
        }

        $addressComplete = $this->addressIsComplete([
            'address' => $data['address'] ?? null,
            'city' => $data['city'] ?? null,
            'state' => $data['state'] ?? null,
            'zip_code' => $data['zip'] ?? null,
        ]) || ($client && $this->addressIsComplete([
            'address' => $client->address,
            'city' => $client->city,
            'state' => $client->state,
            'zip_code' => $client->zip_code,
        ]));

        if (! $addressComplete) {
            $missing[] = 'a full address';
        }

        return $missing;
    }

    /**
     * Mirrors LeadCreate::addressCandidates(): stored candidates only
     * (CompleteLeadAddress writes them; this never geocodes inline).
     *
     * @return array<int, array{address: string, city: string, state: string, zip_code: string, miles: float}>
     */
    public function addressCandidates(Lead $lead): array
    {
        if (! $lead->exists || ! in_array('a full address', $this->missingContactInfo($lead, $lead->resolveClient()), true)) {
            return [];
        }

        $stored = $lead->lead_data['address_candidates'] ?? null;

        if (is_iterable($stored)) {
            $stored = collect($stored)->map(fn ($c) => (array) $c)->all();

            if ($stored !== []) {
                return $stored;
            }
        }

        return [];
    }

    /**
     * Validates a hand-entered phone number — the same 10-digit + uniqueness
     * rule as LeadCreate::saveContactPhone()'s phoneEntry box.
     *
     * @return array{ok: true, digits: string}|array{ok: false, error: string}
     */
    public function validatePhoneEntry(?string $phoneEntry, ?int $excludeUserId): array
    {
        $digits = preg_replace('/\D/', '', (string) $phoneEntry) ?? '';

        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            $digits = substr($digits, 1);
        }

        if (strlen($digits) !== 10) {
            return ['ok' => false, 'error' => 'Enter a 10-digit phone number.'];
        }

        $taken = User::query()
            ->where('cell_phone', $digits)
            ->when($excludeUserId, fn ($q) => $q->whereKeyNot($excludeUserId))
            ->exists();

        if ($taken) {
            return ['ok' => false, 'error' => 'Another contact already uses this number.'];
        }

        return ['ok' => true, 'digits' => $digits];
    }

    /**
     * Persist a validated phone onto the lead and its contact, then
     * provision whatever that unblocks — the persistence half of
     * LeadCreate::saveContactPhone(), for a lead that already exists.
     */
    public function completePhone(Lead $lead, string $digits): void
    {
        $data = $lead->lead_data;
        $data['phone'] = $digits;
        $lead->lead_data = $data;
        $lead->saveQuietly();

        try {
            $this->provisioner->provision($lead->fresh());
        } catch (\Throwable $e) {
            Log::warning('Lead contact provisioning after manual phone entry failed', [
                'lead_id' => $lead->id,
                'error' => $e->getMessage(),
            ]);
        }

        $contact = $lead->fresh()->user;

        if ($contact && ! $contact->hasRoutablePhone()) {
            $contact->forceFill(['cell_phone' => $digits])->save();
        }
    }

    /**
     * Commit one of the stored address candidates as the lead's address,
     * then let provisioning build the client it was waiting on — mirrors
     * LeadCreate::selectAddressCandidate(). Null when the index doesn't
     * match a stored candidate.
     *
     * @return array{address: string, city: string, state: string, zip_code: string, miles: float}|null
     */
    public function selectAddressCandidate(Lead $lead, int $index): ?array
    {
        $candidate = $this->addressCandidates($lead)[$index] ?? null;

        if (! $candidate) {
            return null;
        }

        $data = $lead->lead_data;
        $data['address'] = $candidate['address'];
        $data['city'] = $candidate['city'];
        $data['state'] = $candidate['state'];
        $data['zip'] = $candidate['zip_code'];
        unset($data['address_candidates']);
        $lead->lead_data = $data;
        $lead->saveQuietly();

        try {
            $this->provisioner->provision($lead->fresh());
        } catch (\Throwable $e) {
            Log::warning('Lead contact provisioning after address selection failed', [
                'lead_id' => $lead->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $candidate;
    }

    /**
     * Mirrors LeadCreate::contactCandidates(): the users this company may
     * link a lead to — its own client contacts and the people its other
     * leads are linked to, never its own staff, never another company's.
     */
    public function contactCandidatesQuery(int $vendorId): Builder
    {
        $leadUserIds = Lead::withoutGlobalScopes()
            ->where('belongs_to_vendor_id', $vendorId)
            ->whereNotNull('user_id')
            ->pluck('user_id');

        return User::query()
            ->where(function ($query) use ($vendorId, $leadUserIds) {
                $query->whereHas('clients', fn ($clients) => $clients->withoutGlobalScopes()->whereHas('vendors', fn ($vendors) => $vendors->withoutGlobalScopes()->where('vendors.id', $vendorId)))
                    ->orWhereIn('id', $leadUserIds);
            })
            ->where(fn ($query) => $query->whereNull('primary_vendor_id')->orWhere('primary_vendor_id', '!=', $vendorId))
            ->whereDoesntHave('vendors', fn ($vendors) => $vendors->withoutGlobalScopes()->where('vendors.id', $vendorId));
    }

    /**
     * Mirrors LeadCreate::userChoices(): up to 500 candidates, newest-name
     * first, with the lead's current contact always present.
     *
     * @return list<array{id: int, name: string, contact: string, label: string}>
     */
    public function contactChoices(int $vendorId, ?User $current = null): array
    {
        $choices = $this->contactCandidatesQuery($vendorId)
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->limit(500)
            ->get()
            ->map(fn (User $user) => $this->contactOption($user));

        if ($current && ! $choices->contains('id', (int) $current->id)) {
            $choices->prepend($this->contactOption($current));
        }

        return $choices->values()->all();
    }

    /** @return array{id: int, name: string, contact: string, label: string} */
    public function contactOption(User $user): array
    {
        $name = trim((string) $user->full_name);
        $contact = $user->hasRoutableEmail()
            ? (string) $user->email
            : ($user->cell_phone ? preg_replace('/^(\d{3})(\d{3})(\d{4})$/', '($1) $2-$3', (string) $user->cell_phone) : '');

        return [
            'id' => (int) $user->id,
            'name' => $name,
            'contact' => (string) $contact,
            'label' => $contact !== '' ? "{$name} — {$contact}" : $name,
        ];
    }

    /**
     * Link an existing user as this lead's contact — mirrors the
     * lead-exists branch of LeadCreate::attachUser(): remember the name/
     * email/phone the caller currently has on file (the picked user's name
     * always wins; email/phone only when nothing was on file already), then
     * provisioner->link() points the lead at the user and builds the client
     * at the lead's address when needed.
     */
    public function attachExistingUser(Lead $lead, User $user, string $fullName, ?string $email, ?string $phone): void
    {
        $this->rememberContact($lead, $fullName, $email, $phone);
        $this->provisioner->link($lead->fresh(), $user);
    }

    /**
     * "Add User" — mirrors LeadCreate::addUser(): a user record from the
     * name, email and phone on the lead, linked to it. Null when there's no
     * name, or neither an email nor a phone to reach them.
     */
    public function addUserFromLead(Lead $lead, string $fullName, ?string $email, ?string $phone): ?User
    {
        $this->rememberContact($lead, $fullName, $email, $phone);

        return $this->provisioner->createContactFor($lead->fresh(), $fullName, $email, $phone);
    }

    /**
     * Mirrors LeadCreate::rememberContactOnLead(): the name/email/phone
     * onto the lead itself, so provisioning and the record agree on what
     * was just typed.
     */
    public function rememberContact(Lead $lead, string $fullName, ?string $email, ?string $phone): void
    {
        $fresh = $lead->fresh();
        $data = $fresh->lead_data;
        $data['name'] = trim($fullName) ?: ($data['name'] ?? null);

        if (filled($email)) {
            $data['email'] = trim($email);
        }

        if (filled($phone)) {
            $data['phone'] = preg_replace('/\D/', '', $phone);
        }

        $fresh->lead_data = $data;
        $fresh->saveQuietly();

        if ($lead->is($fresh)) {
            $lead->lead_data = $fresh->lead_data;
        }
    }
}
