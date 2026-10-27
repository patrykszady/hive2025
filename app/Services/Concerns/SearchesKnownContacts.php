<?php

namespace App\Services\Concerns;

use App\Models\Client;
use App\Models\User;
use App\Models\Vendor;
use App\Services\GroupSmsService;
use Illuminate\Database\Eloquent\Builder;

/**
 * Finding the people a company knows by name or phone number — shared by the
 * "Add to Call" search (App\Services\Calls\ConferenceParticipantSearch) and
 * the New Message picker (App\Services\Sms\TextRecipientSearch), so both
 * scope, match and normalise numbers the same way.
 */
trait SearchesKnownContacts
{
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
