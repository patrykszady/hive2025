<?php

namespace App\Services\Sms;

use App\Models\Client;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Concerns\SearchesKnownContacts;
use Illuminate\Support\Collection;

/**
 * The New Message picker's one "Name or Number" box (App\Livewire\Sms\
 * SmsNewThread): the people the company can text — each person on its
 * clients (or its vendors), the whole household (or company) to text them
 * all, and a typed number. Same scoping and number handling as the "Add to
 * Call" search (SearchesKnownContacts). Patryk, 2026-10-26: "we need to see
 * individual users we can text, not just a client group".
 */
class TextRecipientSearch
{
    use SearchesKnownContacts;

    /** Shortest search term that searches at all (a typed number is longer anyway). */
    public const SEARCH_MIN_LENGTH = 2;

    /** Most results rendered at once (Flux options are not free). */
    public const SEARCH_LIMIT = 20;

    /**
     * @param  'client'|'vendor'  $type
     * @return list<array{key: string, name: string, detail: string, kind: string, phone: ?string}>
     */
    public function search(User $user, string $type, string $query): array
    {
        $query = trim($query);

        if (mb_strlen($query) < self::SEARCH_MIN_LENGTH) {
            return [];
        }

        $needle = mb_strtolower($query);
        $digits = preg_replace('/\D/', '', $query);
        $results = collect();
        $seenPhones = collect();

        $groups = $type === 'vendor' ? $this->vendorGroups($user, $needle) : $this->clientGroups($needle);

        foreach ($this->people($user, $type, $needle, $digits) as $person) {
            $phone = $this->normalizedPhoneDigits($person['phone']);
            if ($phone && ! $seenPhones->contains($phone)) {
                $seenPhones->push($phone);
                $results->push($person);
            }
        }

        // The household (or company) after its people: texting one person is the common case.
        $results = $results->merge($groups);

        $typed = $this->typedPhoneCandidate($query);
        $typedDigits = $typed ? $this->normalizedPhoneDigits($typed) : null;
        if ($typed && $typedDigits && ! $seenPhones->contains($typedDigits)) {
            $results->push([
                'key' => 'number:'.$typed,
                'name' => phone_display($typed),
                'detail' => 'Text this number',
                'kind' => 'number',
                'phone' => $typed,
            ]);
        }

        return $results->take(self::SEARCH_LIMIT)->values()->all();
    }

    /**
     * One chosen result, re-checked against the same scoping search() offers
     * under — never trusting a key from the browser.
     *
     * @param  'client'|'vendor'  $type
     * @return array{kind: 'person', user: User, client_id: ?int, vendor_id: ?int}|array{kind: 'client', id: int}|array{kind: 'vendor', id: int}|array{kind: 'number', phone: string}|null
     */
    public function resolve(User $user, string $type, string $key): ?array
    {
        [$kind, $identifier] = array_pad(explode(':', $key, 2), 2, null);

        if ($kind === 'number' && is_string($identifier)) {
            $phone = $this->typedPhoneCandidate($identifier);

            return $phone && $phone === $identifier ? ['kind' => 'number', 'phone' => $phone] : null;
        }

        if (! is_string($identifier) || ! ctype_digit($identifier)) {
            return null;
        }

        $id = (int) $identifier;

        return match ($kind) {
            'client' => $type === 'client' && Client::query()->whereKey($id)->exists() ? ['kind' => 'client', 'id' => $id] : null,
            'vendor' => $type === 'vendor' && $this->vendorQuery($user)->whereKey($id)->exists() ? ['kind' => 'vendor', 'id' => $id] : null,
            'user' => $this->resolvePerson($user, $type, $id),
            default => null,
        };
    }

    /**
     * @return array{kind: 'person', user: User, client_id: ?int, vendor_id: ?int}|null
     */
    protected function resolvePerson(User $user, string $type, int $id): ?array
    {
        $person = $this->peopleQuery($user, $type)->whereKey($id)->first();

        if (! $person) {
            return null;
        }

        return [
            'kind' => 'person',
            'user' => $person,
            'client_id' => $type === 'client' ? $this->clientIdsOf($person)->first() : null,
            'vendor_id' => $type === 'vendor' ? $this->vendorIdsOf($user, $person)->first() : null,
        ];
    }

    /**
     * @return Collection<int, array{key: string, name: string, detail: string, kind: string, phone: ?string}>
     */
    protected function people(User $user, string $type, string $needle, string $digits): Collection
    {
        $people = $this->peopleQuery($user, $type)
            ->where(function ($match) use ($needle, $digits) {
                $match->whereRaw("LOWER(CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, ''))) LIKE ?", ['%'.$needle.'%'])
                    ->orWhereRaw("LOWER(CONCAT(COALESCE(nickname, ''), ' ', COALESCE(last_name, ''))) LIKE ?", ['%'.$needle.'%']);
                if (strlen($digits) >= 3) {
                    $match->orWhere('cell_phone', 'like', '%'.$digits.'%');
                }
            })
            ->with($type === 'vendor' ? ['vendors' => fn ($vendors) => $vendors->withoutGlobalScopes()] : ['clients' => fn ($clients) => $clients->withoutGlobalScopes()])
            ->orderBy('first_name')
            ->limit(self::SEARCH_LIMIT)
            ->get();

        $clientIds = $type === 'client' ? Client::query()->pluck('clients.id')->flip() : collect();
        $vendorIds = $type === 'vendor' ? $this->vendorQuery($user)->pluck('vendors.id')->flip() : collect();

        return $people->map(function (User $person) use ($type, $clientIds, $vendorIds) {
            $detail = $type === 'vendor'
                ? $person->vendors->first(fn (Vendor $vendor) => $vendorIds->has($vendor->id))?->name
                : $person->clients->first(fn (Client $client) => $clientIds->has($client->id))?->name;

            return [
                'key' => 'user:'.$person->id,
                'name' => self::personName($person),
                'detail' => (string) ($detail ?? ''),
                'kind' => $type === 'vendor' ? 'Vendor' : 'Client',
                'phone' => (string) $person->cell_phone,
            ];
        });
    }

    /**
     * Each of the company's clients matching the name, to text the whole household.
     *
     * @return Collection<int, array{key: string, name: string, detail: string, kind: string, phone: ?string}>
     */
    protected function clientGroups(string $needle): Collection
    {
        return Client::cachedDropdownList()
            ->filter(fn (Client $client) => str_contains(mb_strtolower((string) $client->name), $needle))
            ->map(function (Client $client) {
                $textable = $client->users->filter(fn (User $person) => trim((string) $person->getRawOriginal('cell_phone')) !== '')->count();

                return $textable > 0 ? [
                    'key' => 'client:'.$client->id,
                    'name' => (string) $client->name,
                    'detail' => $textable === 1 ? 'Household · 1 person' : "Household · {$textable} people",
                    'kind' => 'Household',
                    'phone' => null,
                ] : null;
            })
            ->filter()
            ->take(5)
            ->values();
    }

    /**
     * Each of the company's vendors matching the name, to text its people (or its office line).
     *
     * @return Collection<int, array{key: string, name: string, detail: string, kind: string, phone: ?string}>
     */
    protected function vendorGroups(User $user, string $needle): Collection
    {
        // The short name lives in the options JSON: narrowed with a LIKE, decided in PHP.
        return $this->vendorQuery($user)
            ->where(fn ($match) => $match->whereRaw('LOWER(business_name) LIKE ?', ['%'.$needle.'%'])->orWhereRaw('LOWER(options) LIKE ?', ['%'.$needle.'%']))
            ->withCount(['users' => fn ($users) => $users->whereNotNull('cell_phone')->where('cell_phone', '!=', '')])
            ->orderBy('business_name')
            ->limit(20)
            ->get()
            ->filter(fn (Vendor $vendor) => str_contains(mb_strtolower((string) $vendor->business_name), $needle) || str_contains(mb_strtolower((string) $vendor->short_name), $needle))
            ->take(5)
            ->map(fn (Vendor $vendor) => [
                'key' => 'vendor:'.$vendor->id,
                'name' => (string) ($vendor->short_name ?: $vendor->business_name),
                'detail' => $vendor->users_count > 0
                    ? 'Company · '.$vendor->users_count.' '.($vendor->users_count === 1 ? 'person' : 'people')
                    : 'Company · office line',
                'kind' => 'Company',
                'phone' => null,
            ])
            ->values();
    }

    /** The people the company can text on that side: client contacts, or its vendors' people (not its own team). */
    protected function peopleQuery(User $user, string $type)
    {
        $query = User::query()->whereNotNull('cell_phone')->where('cell_phone', '!=', '');

        if ($type === 'vendor') {
            $vendorIds = $this->vendorQuery($user)->pluck('vendors.id');

            return $query->whereHas('vendors', fn ($vendors) => $vendors->withoutGlobalScopes()->whereIn('vendors.id', $vendorIds));
        }

        $clientIds = Client::query()->pluck('clients.id');

        return $query->whereHas('clients', fn ($clients) => $clients->withoutGlobalScopes()->whereIn('clients.id', $clientIds));
    }

    /** The vendors the company works with, never its own company. */
    protected function vendorQuery(User $user)
    {
        return Vendor::query()->when($user->vendor?->id, fn ($query, $own) => $query->where('vendors.id', '!=', $own));
    }

    /** @return Collection<int, int> */
    protected function clientIdsOf(User $person): Collection
    {
        $scoped = Client::query()->pluck('clients.id');

        return $person->clients()->withoutGlobalScopes()->whereIn('clients.id', $scoped)->orderBy('clients.id')->pluck('clients.id')->map(fn ($id) => (int) $id);
    }

    /** @return Collection<int, int> */
    protected function vendorIdsOf(User $user, User $person): Collection
    {
        $scoped = $this->vendorQuery($user)->pluck('vendors.id');

        return $person->vendors()->withoutGlobalScopes()->whereIn('vendors.id', $scoped)->orderBy('vendors.id')->pluck('vendors.id')->map(fn ($id) => (int) $id);
    }

    /** "Brad Love" — the nickname first, the way the team says it. */
    public static function personName(User $person): string
    {
        $first = trim((string) ($person->nickname ?: $person->first_name));

        return trim($first.' '.trim((string) $person->last_name)) ?: phone_display((string) $person->cell_phone);
    }
}
