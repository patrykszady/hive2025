<?php

namespace App\Services\Calls;

use App\Models\CallLog;
use App\Models\Client;
use App\Models\User;
use App\Models\Vendor;
use App\Services\GroupSmsService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Who the "Add to Call" search offers for a live conference, and resolving
 * one chosen result back to a dialable phone number — shared by the web's
 * App\Livewire\Sms\CallStatusBadge (participantSearchResults()/
 * inviteParticipant()) and the app's CallParticipantController, so both
 * offer exactly the same people.
 *
 * Priority order: the team roster and anyone already tied to this call are
 * offered as soon as the picker opens; everyone else the company knows (its
 * clients, its vendors, and its vendors' people) — plus a free-typed phone
 * number — only once a search term narrows things down, so this never
 * renders hundreds of options.
 */
class ConferenceParticipantSearch
{
    /** Shortest search term that widens the search past the team + call-tied people. */
    public const SEARCH_MIN_LENGTH = 2;

    /** Most results rendered at once (Flux autocomplete options are not free). */
    public const SEARCH_LIMIT = 20;

    /**
     * @return array<int, array{key: string, name: string, kind: string, phone: string}>
     */
    public function search(User $user, CallLog $callLog, string $query): array
    {
        $query = trim($query);
        $hasQuery = mb_strlen($query) >= self::SEARCH_MIN_LENGTH;

        $joinedAdminIds = array_map('intval', (array) ($callLog->metadata['joined_admin_ids'] ?? []));
        $excludedUserIds = array_unique(array_merge([$user->id], $joinedAdminIds));

        $results = collect();
        $seenPhones = collect();
        $seenUserIds = collect();

        $addUser = function (User $candidate, string $kind) use ($results, $seenPhones, $seenUserIds, $excludedUserIds): void {
            if (in_array($candidate->id, $excludedUserIds, true) || $seenUserIds->contains($candidate->id)) {
                return;
            }

            $digits = $this->normalizedPhoneDigits($candidate->cell_phone);
            if (! $digits || $seenPhones->contains($digits)) {
                return;
            }

            $seenUserIds->push($candidate->id);
            $seenPhones->push($digits);
            $results->push([
                'key' => "user:{$candidate->id}",
                'name' => trim((string) $candidate->full_name) ?: phone_display($candidate->cell_phone),
                'kind' => $kind,
                'phone' => (string) $candidate->cell_phone,
            ]);
        };

        // 1. The team roster — same as the keypad press-9 shortcut.
        foreach ($this->teamRosterUsers() as $candidate) {
            if (! $hasQuery || $this->userMatchesQuery($candidate, $query)) {
                $addUser($candidate, 'Team');
            }
        }

        // 2. People tied to this call: the other party's own client (so a
        //    spouse can be pulled in too), and the project's client when the
        //    call resolves to one.
        foreach ($this->callTiedUsers($callLog) as $candidate) {
            if (! $hasQuery || $this->userMatchesQuery($candidate, $query)) {
                $addUser($candidate, 'Client');
            }
        }

        if ($hasQuery) {
            // 3. Anyone else the company knows: its clients (on any
            //    project), its vendors' people (past employees too) — never
            //    another company's.
            $known = $this->knownUsersQuery($user)
                ->whereNotNull('cell_phone')
                ->where('cell_phone', '!=', '')
                ->where(function (Builder $knownQuery) use ($query): void {
                    $knownQuery->where('cell_phone', 'like', '%'.$query.'%')
                        ->orWhereRaw("LOWER(CONCAT(first_name, ' ', last_name)) LIKE ?", ['%'.mb_strtolower($query).'%']);
                })
                ->orderBy('first_name')
                ->limit(self::SEARCH_LIMIT)
                ->get();

            foreach ($known as $candidate) {
                $addUser($candidate, $candidate->clients()->exists() ? 'Client' : 'Vendor');
            }

            // 4. The company's vendors' own business line.
            $vendors = Vendor::query()
                ->whereNotNull('business_phone')
                ->where('business_phone', '!=', '')
                ->where('business_name', 'like', '%'.$query.'%')
                ->orderBy('business_name')
                ->limit(self::SEARCH_LIMIT)
                ->get();

            foreach ($vendors as $vendor) {
                $digits = $this->normalizedPhoneDigits($vendor->business_phone);
                if (! $digits || $seenPhones->contains($digits)) {
                    continue;
                }

                $seenPhones->push($digits);
                $results->push([
                    'key' => "vendor:{$vendor->id}",
                    'name' => $vendor->short_name ?: $vendor->business_name,
                    'kind' => 'Vendor',
                    'phone' => (string) $vendor->business_phone,
                ]);
            }

            // 5. A free-typed phone number — dialable even if it matches nobody.
            $typedPhone = $this->typedPhoneCandidate($query);
            if ($typedPhone) {
                $digits = $this->normalizedPhoneDigits($typedPhone);
                if ($digits && ! $seenPhones->contains($digits)) {
                    $seenPhones->push($digits);
                    $results->push([
                        'key' => 'number:'.$typedPhone,
                        'name' => phone_display($typedPhone),
                        'kind' => 'number',
                        'phone' => $typedPhone,
                    ]);
                }
            }
        }

        return $results->take(self::SEARCH_LIMIT)->values()->all();
    }

    /**
     * Resolve one search result's opaque key back to a dialable candidate,
     * re-checking the same scoping rules search() offers under rather than
     * trusting a client-supplied phone/name — the app has no server-cached
     * copy of a previous search response to trust the way the Livewire
     * component's own computed property does.
     *
     * @return array{key: string, name: string, kind: string, phone: string, participant_id: ?int}|null
     */
    public function resolve(User $user, CallLog $callLog, string $key): ?array
    {
        [$type, $identifier] = array_pad(explode(':', $key, 2), 2, null);

        if ($type === 'user' && is_string($identifier) && ctype_digit($identifier)) {
            return $this->resolveUser($user, $callLog, (int) $identifier, $key);
        }

        if ($type === 'vendor' && is_string($identifier) && ctype_digit($identifier)) {
            return $this->resolveVendor((int) $identifier, $key);
        }

        if ($type === 'number' && is_string($identifier) && $identifier !== '') {
            return $this->resolveTypedNumber($identifier, $key);
        }

        return null;
    }

    /**
     * @return array{key: string, name: string, kind: string, phone: string, participant_id: ?int}|null
     */
    protected function resolveUser(User $user, CallLog $callLog, int $candidateId, string $key): ?array
    {
        $joinedAdminIds = array_map('intval', (array) ($callLog->metadata['joined_admin_ids'] ?? []));
        if ($candidateId === $user->id || in_array($candidateId, $joinedAdminIds, true)) {
            return null;
        }

        $candidate = User::query()
            ->whereKey($candidateId)
            ->whereNotNull('cell_phone')
            ->where('cell_phone', '!=', '')
            ->first();
        if (! $candidate) {
            return null;
        }

        $isTeamRoster = $this->teamRosterUsers()->contains('id', $candidateId);
        $isCallTied = $this->callTiedUsers($callLog)->contains('id', $candidateId);
        $isKnown = $isTeamRoster || $isCallTied || $this->knownUsersQuery($user)->whereKey($candidateId)->exists();

        if (! $isKnown) {
            return null;
        }

        $kind = $isTeamRoster ? 'Team' : ($isCallTied || $candidate->clients()->exists() ? 'Client' : 'Vendor');

        return [
            'key' => $key,
            'name' => trim((string) $candidate->full_name) ?: phone_display($candidate->cell_phone),
            'kind' => $kind,
            'phone' => (string) $candidate->cell_phone,
            'participant_id' => $candidate->id,
        ];
    }

    /**
     * @return array{key: string, name: string, kind: string, phone: string, participant_id: ?int}|null
     */
    protected function resolveVendor(int $vendorId, string $key): ?array
    {
        $vendor = Vendor::query()
            ->whereKey($vendorId)
            ->whereNotNull('business_phone')
            ->where('business_phone', '!=', '')
            ->first();
        if (! $vendor) {
            return null;
        }

        return [
            'key' => $key,
            'name' => $vendor->short_name ?: $vendor->business_name,
            'kind' => 'Vendor',
            'phone' => (string) $vendor->business_phone,
            'participant_id' => null,
        ];
    }

    /**
     * @return array{key: string, name: string, kind: string, phone: string, participant_id: ?int}|null
     */
    protected function resolveTypedNumber(string $identifier, string $key): ?array
    {
        $phone = $this->typedPhoneCandidate($identifier);

        // Must round-trip exactly: guards against a key carrying something
        // that never normalizes back to the identifier itself.
        if (! $phone || $phone !== $identifier) {
            return null;
        }

        return [
            'key' => $key,
            'name' => phone_display($phone),
            'kind' => 'number',
            'phone' => $phone,
            'participant_id' => null,
        ];
    }

    /**
     * The GS admin roster — the same list the phone "press 9" shortcut and
     * the inbound simulring use (Vendor 1's call_recipients). withoutGlobalScopes:
     * the canonical GS vendor (1) is a fixed config lookup, but this runs in
     * an authenticated context where VendorScope filters vendors to the
     * viewer's own — which would hide vendor 1 (and empty the picker) for
     * any admin who has a vendor of their own.
     *
     * @return Collection<int, User>
     */
    protected function teamRosterUsers(): Collection
    {
        $vendor = Vendor::withoutGlobalScopes()->find(1);
        $recipientIds = array_map('intval', (array) data_get($vendor?->options ?? [], 'call_recipients', []));

        return User::query()
            ->whereIn('id', $recipientIds)
            ->whereNotNull('cell_phone')
            ->where('cell_phone', '!=', '')
            ->orderBy('first_name')
            ->get();
    }

    /**
     * The people tied to this specific call: the other party's own client
     * (covers a spouse at the same address) and, when the call resolves to
     * a project, that project's client. Client::query() carries the
     * company's ClientScope, so a client id from another company (or a
     * stale/foreign value) simply never resolves — this can't leak across
     * tenants.
     *
     * @return Collection<int, User>
     */
    protected function callTiedUsers(CallLog $callLog): Collection
    {
        $clientIds = collect([$callLog->client_id]);

        if ($callLog->project_id) {
            $clientIds->push(\App\Models\Project::find($callLog->project_id)?->client?->id);
        }

        $otherNumber = $callLog->direction === 'incoming' ? $callLog->from_number : $callLog->to_number;
        $otherUser = $otherNumber ? $this->findUserByPhone($otherNumber) : null;
        if ($otherUser) {
            $clientIds = $clientIds->merge($otherUser->clients()->pluck('clients.id'));
        }

        $scopedClientIds = Client::query()->whereIn('id', $clientIds->filter()->unique())->pluck('id');

        if ($scopedClientIds->isEmpty()) {
            return collect();
        }

        return User::query()
            ->whereHas('clients', fn ($q) => $q->whereIn('clients.id', $scopedClientIds))
            ->whereNotNull('cell_phone')
            ->where('cell_phone', '!=', '')
            ->get();
    }

    /**
     * Every user the signed-in company knows: its own people, the people of
     * the vendors it works with, and its clients' users. Never another
     * company's staff or homeowners. Mirrors
     * App\Livewire\Tasks\TaskCreate::knownUsers().
     *
     * @return Builder<User>
     */
    protected function knownUsersQuery(User $user): Builder
    {
        if (! $user->vendor) {
            return User::query()->whereRaw('1 = 0');
        }

        $vendorIds = Vendor::query()->pluck('vendors.id');
        $clientIds = Client::query()->pluck('clients.id');

        return User::query()->where(function ($known) use ($vendorIds, $clientIds): void {
            $known->whereHas('vendors', fn ($vendors) => $vendors->withoutGlobalScopes()->whereIn('vendors.id', $vendorIds))
                ->orWhereHas('clients', fn ($clients) => $clients->withoutGlobalScopes()->whereIn('clients.id', $clientIds));
        });
    }

    protected function findUserByPhone(string $phone): ?User
    {
        $digits = preg_replace('/[^0-9]/', '', $phone);
        $normalized = (strlen($digits) === 11 && str_starts_with($digits, '1')) ? substr($digits, 1) : $digits;
        $last10 = strlen($digits) > 10 ? substr($digits, -10) : $digits;
        $variants = array_values(array_unique([$normalized, '1'.$normalized, $digits, $last10]));

        return User::whereIn('cell_phone', $variants)->first();
    }

    protected function userMatchesQuery(User $candidate, string $query): bool
    {
        $needle = mb_strtolower($query);
        $nameHaystack = mb_strtolower(trim((string) $candidate->full_name));
        $digitsNeedle = preg_replace('/\D/', '', $query);

        if (str_contains($nameHaystack, $needle)) {
            return true;
        }

        return $digitsNeedle !== '' && str_contains((string) $candidate->cell_phone, $digitsNeedle);
    }

    /**
     * A hand-typed phone number from the search box — a plain US 10-digit
     * number, an 11-digit number with the leading country code, or an
     * already-E.164 string. Normalised the same way as everywhere else a
     * person types a phone number (GroupSmsService::formatE164).
     */
    protected function typedPhoneCandidate(string $query): ?string
    {
        $digits = preg_replace('/\D/', '', $query);

        if ($digits === '') {
            return null;
        }

        $looksLikeUsNumber = strlen($digits) === 10 || (strlen($digits) === 11 && str_starts_with($digits, '1'));
        $looksLikeE164 = str_starts_with(trim($query), '+') && strlen($digits) >= 10;

        if (! $looksLikeUsNumber && ! $looksLikeE164) {
            return null;
        }

        return GroupSmsService::formatE164($query);
    }

    protected function normalizedPhoneDigits(?string $phone): ?string
    {
        if (! $phone) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $phone);
        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            $digits = substr($digits, 1);
        }

        return $digits !== '' ? $digits : null;
    }
}
