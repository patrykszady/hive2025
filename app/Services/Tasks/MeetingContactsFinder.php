<?php

namespace App\Services\Tasks;

use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who can be added as a Meet participant, exactly like
 * App\Livewire\Tasks\TaskCreate's own
 * availableMeetingContacts()/participantSearchResults()/knownUsers()/
 * meetingParticipantDisplayName() — pulled out so the Hive Contractors
 * app's task screen can offer the same contacts-to-pick-from instead of a
 * free-typed email list (toggling a contact on/off, or adding one found by
 * search, never needs a write of its own — same as the web, where it's all
 * in-memory form state until the task itself is saved).
 */
class MeetingContactsFinder
{
    /** Shortest search term that triggers a platform-wide participant search. */
    private const SEARCH_MIN_LENGTH = 2;

    /** Most participant search results rendered at once. */
    private const SEARCH_LIMIT = 20;

    /**
     * The project's client users, team members, and the selected vendor's
     * contact — nothing typed, same as TaskCreate::availableMeetingContacts().
     *
     * @return array<int, array{email: string, name: string, group: string}>
     */
    public function availableContacts(?int $projectId, ?int $vendorId): array
    {
        $contacts = collect();
        $taggedEmails = collect();

        if ($projectId) {
            $project = Project::with('client.users')->find($projectId);
            if ($project?->client) {
                $clientContacts = collect($project->client->users)->map(fn ($user) => [
                    'email' => strtolower(trim($user->email)),
                    'name' => trim(($user->first_name ?? '').' '.($user->last_name ?? '')),
                    'group' => 'Client',
                ])->filter(fn ($c) => $c['email'] !== '');

                $contacts = $contacts->merge($clientContacts);
                $taggedEmails = $taggedEmails->merge($clientContacts->pluck('email'));
            }
        }

        $vendor = auth()->user()?->vendor;
        if ($vendor) {
            $teamContacts = $vendor->users()->employed()->get()->map(fn ($user) => [
                'email' => strtolower(trim($user->email)),
                'name' => trim(($user->first_name ?? '').' '.($user->last_name ?? '')),
                'group' => 'Team',
            ])->filter(fn ($c) => $c['email'] !== '');

            $contacts = $contacts->merge($teamContacts);
            $taggedEmails = $taggedEmails->merge($teamContacts->pluck('email'));
        }

        if ($vendorId) {
            $selectedVendor = Vendor::query()->find($vendorId);
            $vendorEmail = strtolower(trim((string) ($selectedVendor?->email ?? $selectedVendor?->business_email ?? '')));

            if ($vendorEmail !== '' && ! $taggedEmails->contains($vendorEmail)) {
                $contacts->push([
                    'email' => $vendorEmail,
                    'name' => trim((string) ($selectedVendor?->business_name ?? '')),
                    'group' => 'Vendor',
                ]);
                $taggedEmails->push($vendorEmail);
            }
        }

        return $contacts->unique('email')->values()->all();
    }

    /**
     * With nothing typed, exactly availableContacts() (minus $selectedEmails).
     * Once $query is 2+ characters, also matches every user the company
     * knows (knownUsers()) by name or email — mirrors
     * TaskCreate::participantSearchResults() exactly.
     *
     * @param  string[]  $selectedEmails
     * @return array<int, array{email: string, name: string, group: string}>
     */
    public function searchResults(?int $projectId, ?int $vendorId, array $selectedEmails, string $query): array
    {
        $selected = collect($selectedEmails)
            ->map(fn ($email) => strtolower(trim((string) $email)))
            ->all();

        $projectContacts = collect($this->availableContacts($projectId, $vendorId))
            ->reject(fn (array $contact) => in_array($contact['email'], $selected, true));

        $query = trim($query);

        if ($query === '' || mb_strlen($query) < self::SEARCH_MIN_LENGTH) {
            return $projectContacts->values()->all();
        }

        $needle = strtolower($query);
        $matchingProjectContacts = $projectContacts->filter(
            fn (array $contact) => str_contains(strtolower($contact['name']), $needle)
                || str_contains($contact['email'], $needle)
        );

        $excludedEmails = $matchingProjectContacts->pluck('email')->merge($selected)->unique()->all();

        $platformUsers = $this->knownUsers()
            ->where(function ($userQuery) use ($query): void {
                $userQuery->where('email', 'like', "%{$query}%")
                    ->orWhereRaw("LOWER(CONCAT(first_name, ' ', last_name)) LIKE ?", ['%'.strtolower($query).'%']);
            })
            ->whereNotIn('email', $excludedEmails)
            ->orderBy('first_name')
            ->limit(self::SEARCH_LIMIT)
            ->get()
            ->filter(fn (User $user) => $user->hasRoutableEmail())
            ->map(fn (User $user) => [
                'email' => strtolower(trim((string) $user->email)),
                'name' => trim($user->full_name),
                'group' => 'User',
            ])
            ->values();

        return $matchingProjectContacts
            ->merge($platformUsers)
            ->unique('email')
            ->take(self::SEARCH_LIMIT)
            ->values()
            ->all();
    }

    /**
     * The name shown for a participant's email: the project contact's name
     * when there is one, otherwise a user the company knows with that
     * email, otherwise the email itself — mirrors
     * TaskCreate::meetingParticipantDisplayName() exactly.
     */
    public function displayName(string $email, ?int $projectId, ?int $vendorId): string
    {
        $email = strtolower(trim($email));

        $contact = collect($this->availableContacts($projectId, $vendorId))->firstWhere('email', $email);

        if ($contact && filled($contact['name'])) {
            return $contact['name'];
        }

        $name = trim((string) ($this->knownUsers()->where('email', $email)->first()?->full_name));

        return $name !== '' ? $name : $email;
    }

    /**
     * Every user the signed-in company knows: its own people (past ones
     * too), the people of the vendors it works with, and its clients'
     * users — mirrors TaskCreate::knownUsers() exactly.
     *
     * @return Builder<User>
     */
    private function knownUsers(): Builder
    {
        if (! auth()->user()?->vendor) {
            return User::query()->whereRaw('1 = 0');
        }

        $vendorIds = Vendor::query()->pluck('vendors.id');
        $clientIds = Client::query()->pluck('clients.id');

        return User::query()->where(function ($known) use ($vendorIds, $clientIds): void {
            $known->whereHas('vendors', fn ($vendors) => $vendors->withoutGlobalScopes()->whereIn('vendors.id', $vendorIds))
                ->orWhereHas('clients', fn ($clients) => $clients->withoutGlobalScopes()->whereIn('clients.id', $clientIds));
        });
    }
}
