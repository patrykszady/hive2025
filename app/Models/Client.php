<?php

namespace App\Models;

use App\Scopes\ClientScope;
use App\Scopes\VendorScope;
use App\Traits\HasAddress;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;
use Laravel\Scout\Builder as ScoutBuilder;
use Laravel\Scout\Searchable;

class Client extends Model
{
    use HasFactory, HasAddress, Searchable;

    protected $fillable = ['business_name', 'address', 'address_2', 'city', 'state', 'zip_code', 'home_phone', 'source', 'created_at', 'updated_at'];

    protected static function booted()
    {
        static::addGlobalScope(new ClientScope);
    }

    public function searchableAs(): string
    {
        return app()->environment('local') ? 'clients_index_dev' : 'clients_index';
    }

    public function toSearchableArray(): array
    {
        $users = $this->relationLoaded('users')
            ? $this->users
            : $this->users()->select('users.id', 'first_name', 'last_name', 'nickname')->get();

        $vendors = $this->relationLoaded('vendors')
            ? $this->vendors
            : $this->vendors()->select('vendors.id', 'vendors.business_name')->get();

        $clientUserFirstNames = $users->pluck('first_name')->filter()->values()->all();
        $clientUserLastNames = $users->pluck('last_name')->filter()->values()->all();
        $clientUserFullNames = $users
            ->map(fn ($user) => trim((string) ($user->first_name ?? '').' '.(string) ($user->last_name ?? '')))
            ->filter()
            ->values()
            ->all();

        // Nicknames search too: "Dick" finds Richard Egger (Patryk, 2026-10-27),
        // alone and with the last name, the way people say it.
        $clientUserNicknames = $users->pluck('nickname')->filter()->map(fn ($n) => trim((string) $n))->filter()->values()->all();
        $clientUserNicknameFullNames = $users
            ->filter(fn ($user) => trim((string) ($user->nickname ?? '')) !== '')
            ->map(fn ($user) => trim((string) $user->nickname.' '.(string) ($user->last_name ?? '')))
            ->values()
            ->all();

        $vendorIds = $vendors->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        $vendorBusinessNames = $vendors->pluck('business_name')->filter()->values()->all();

        $clientSearch = trim(implode(' ', array_filter(array_merge(
            [$this->name, $this->business_name, $this->first_names, $this->last_names],
            $clientUserFullNames,
            $clientUserFirstNames,
            $clientUserLastNames,
            $clientUserNicknames,
            $clientUserNicknameFullNames
        ))));

        $fullAddress = trim(implode(' ', array_filter([
            $this->address ?? '',
            $this->address_2 ?? '',
            $this->city ?? '',
            $this->state ?? '',
            $this->zip_code ?? '',
        ])));

        return [
            'id' => (int) $this->id,
            'business_name' => $this->business_name,
            'name' => $this->name,
            'first_names' => $this->first_names,
            'last_names' => $this->last_names,
            'address' => $this->address,
            'city' => $this->city,
            'state' => $this->state,
            'zip_code' => $this->zip_code,
            'full_address' => $fullAddress,
            'client_search' => $clientSearch,
            'client_user_first_names' => $clientUserFirstNames,
            'client_user_last_names' => $clientUserLastNames,
            'client_user_full_names' => $clientUserFullNames,
            'client_user_nicknames' => $clientUserNicknames,
            'client_user_nickname_full_names' => $clientUserNicknameFullNames,
            'vendor_ids' => $vendorIds,
            'vendor_business_names' => $vendorBusinessNames,
            'created_at' => $this->created_at?->timestamp ?? 0,
        ];
    }

    protected function makeAllSearchableUsing(Builder $query): Builder
    {
        return $query->with([
            'users:id,first_name,last_name,nickname',
            'vendors:id,business_name',
        ]);
    }

    public static function scopedSearch(
        string $query = '',
        array $filterConditions = [],
        string $sortBy = 'created_at',
        string $sortDirection = 'desc',
        ?User $user = null
    ): ScoutBuilder {
        $user ??= Auth::user();

        if (! $user) {
            throw new \RuntimeException('Client::scopedSearch() requires an authenticated user.');
        }

        $filters = [
            'vendor_ids = '.((int) $user->vendor->id),
        ];

        foreach ($filterConditions as $condition) {
            if (is_string($condition) && $condition !== '') {
                $filters[] = $condition;
            }
        }

        $filterString = implode(' AND ', $filters);

        return self::search($query, function ($meilisearch, $searchQuery, $options) use ($filterString, $sortBy, $sortDirection) {
            $options['filter'] = $filterString;
            $options['sort'] = ["{$sortBy}:{$sortDirection}"];
            $options['matchingStrategy'] = 'all';

            return $meilisearch->search($searchQuery, $options);
        });
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->using(ClientUser::class)->withTimestamps();
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class)->withoutGlobalScope(VendorScope::class);
    }

    public function vendors(): BelongsToMany
    {
        return $this->belongsToMany(Vendor::class)->withPivot('source')->withTimestamps();
    }

    /**
     * Get the name attribute based on business name or users
     */
    /**
     * The "every client" list the create/duplicate/new-thread dropdowns
     * render (~300 clients + their users on each of those page mounts).
     * Cached per vendor context; ClientObserver busts on any client change.
     *
     * ClientScope returns a different set of rows per homeowner (their own
     * linked client(s) only, not "every client"), so a vendor-less user must
     * get their own cache entry — a shared "no vendor" bucket would leak one
     * homeowner's client list to the next homeowner who hits the cache.
     */
    public static function cachedDropdownList()
    {
        return \Illuminate\Support\Facades\Cache::remember(
            static::dropdownCacheKey(),
            now()->addMinutes(10),
            fn () => static::with('users')->orderBy('created_at', 'DESC')->get()
        );
    }

    public static function bustDropdownCache(): void
    {
        \Illuminate\Support\Facades\Cache::forget(static::dropdownCacheKey());
    }

    /**
     * Forget the cached list of every company this client belongs to, not
     * only the signed-in user's: people are added to clients from jobs and
     * lead intake too, where nobody is signed in.
     */
    public static function bustDropdownCacheForClient(int $clientId): void
    {
        static::bustDropdownCache();

        $vendorIds = static::withoutGlobalScopes()->find($clientId)?->vendors()->withoutGlobalScopes()->pluck('vendors.id') ?? collect();
        foreach ($vendorIds as $vendorId) {
            \Illuminate\Support\Facades\Cache::forget("clients:dropdown:v{$vendorId}");
        }
    }

    /**
     * A vendor user shares one cache entry per vendor (everyone on that
     * vendor sees the same "every client" list). A user with no vendor
     * (a homeowner in the client portal) gets their own list from
     * ClientScope, so they need their own cache entry, keyed by user id.
     */
    protected static function dropdownCacheKey(): string
    {
        $user = auth()->user();
        $vendorId = $user?->vendor?->id;

        return $vendorId
            ? "clients:dropdown:v{$vendorId}"
            : "clients:dropdown:u{$user?->id}";
    }

    protected function name(): Attribute
    {
        return Attribute::make(
            // Display names prefer nicknames (e.g. "Dick" for Richard);
            // documents use legal_name.
            get: fn ($value, array $attributes) => $this->composeName(
                $attributes,
                fn ($user) => trim((string) ($user->nickname ?: $user->first_name)),
            ),
        )->shouldCache();
    }

    /**
     * The name for documents a client signs or keeps — estimates, contracts,
     * change orders, invoices, lien waivers: built exactly like `name`, but
     * from each person's legal first name, never their nickname ("Zachary
     * Wong", not "Zach Wong"; estimate 273, 2026-10-21).
     */
    protected function legalName(): Attribute
    {
        return Attribute::make(
            get: fn ($value, array $attributes) => $this->composeName(
                $attributes,
                fn ($user) => trim((string) $user->first_name),
            ),
        )->shouldCache();
    }

    /**
     * A business's name before its first comma, or the people's names:
     * "Ann Lee", "Ann & Bob Lee", "Ann Lee & Bob Kim".
     *
     * @param  array<string, mixed>  $attributes
     * @param  callable(User): string  $firstName
     */
    private function composeName(array $attributes, callable $firstName): string
    {
        if (! empty($attributes['business_name'])) {
            return trim(explode(',', $attributes['business_name'])[0]);
        }

        $users = $this->users;

        if ($users->count() == 0) {
            return 'No Name';
        }

        if ($users->count() == 1) {
            return trim($firstName($users->first()).' '.$users->first()->last_name);
        }

        $nameGroups = [];

        foreach ($users->groupBy('last_name') as $lastName => $lastNameGroup) {
            if ($lastNameGroup->count() == 1) {
                // Single person with this last name - use full name
                $nameGroups[] = $firstName($lastNameGroup->first()).' '.$lastName;
            } else {
                // Multiple people with same last name - combine first names
                $nameGroups[] = $this->oxfordJoin($lastNameGroup->map($firstName)->filter()->values()->toArray()).' '.$lastName;
            }
        }

        return $this->oxfordJoin($nameGroups);
    }

    /**
     * Get just the first names for greeting purposes.
     * Prefers nicknames (e.g. "Dick" for Richard) — legal documents build
     * full names from first_name/last_name directly and are unaffected.
     */
    protected function firstNames(): Attribute
    {
        return Attribute::make(
            get: function ($value, array $attributes) {
                // If client has a business_name, use that
                if (!empty($attributes['business_name'])) {
                    $nameParts = explode(',', $attributes['business_name']);
                    return trim($nameParts[0]);
                }

                // Otherwise, get first names from users
                $users = $this->users;

                if ($users->count() == 0) {
                    return 'there';
                }

                $preferredName = fn ($user) => trim((string) ($user->nickname ?: $user->first_name));

                if ($users->count() == 1) {
                    return $preferredName($users->first());
                }

                // Multiple users - combine first names with Oxford comma
                $firstNames = $users->map($preferredName)->filter()->values()->toArray();
                return $this->oxfordJoin($firstNames);
            }
        );
    }

    /**
     * Get just the last name(s) for display purposes
     */
    protected function lastNames(): Attribute
    {
        return Attribute::make(
            get: function ($value, array $attributes) {
                // If client has a business_name, use that
                if (!empty($attributes['business_name'])) {
                    $nameParts = explode(',', $attributes['business_name']);
                    return trim($nameParts[0]);
                }

                // Otherwise, get last names from users
                $users = $this->users;

                if ($users->count() == 0) {
                    return 'No Name';
                }

                // Get unique last names and join with Oxford comma
                $lastNames = $users->pluck('last_name')->unique()->toArray();
                return $this->oxfordJoin($lastNames);
            }
        )->shouldCache();
    }

    /**
     * Get the source attribute from the pivot table
     */
    protected function source(): Attribute
    {
        return Attribute::make(
            get: function ($value, array $attributes) {
                $vendorId = auth()->user()?->vendor?->id;

                if (!$vendorId) {
                    return null;
                }

                return $this->vendors()->wherePivot('vendor_id', $vendorId)->first()?->pivot->source;
            }
        )->shouldCache();
    }

    /**
     * Format business name with title case (First Letter Of Each Word)
     */
    protected function businessName(): Attribute
    {
        return Attribute::make(
            get: function ($value) {
                return $value;
            },
            set: function ($value) {
                if (!$value) {
                    return null;
                }
                
                // Convert to title case (capitalize first letter of each word)
                return ucwords(strtolower($value));
            }
        );
    }

    /**
     * Join an array of strings with commas and "&" (Oxford comma for 3+).
     *
     * 1 item:  "Carrie"
     * 2 items: "Carrie & Debra"
     * 3 items: "Carrie, Debra & Alan"
     */
    private function oxfordJoin(array $items): string
    {
        return collect($items)->join(', ', ' & ');
    }

    /**
     * Format home_phone with proper phone format
     */
    protected function homePhone(): Attribute
    {
        return Attribute::make(
            get: function ($value) {
                if (!$value) {
                    return null;
                }
                
                // Format 10-digit number as (XXX) XXX-XXXX
                if (strlen($value) === 10) {
                    return '(' . substr($value, 0, 3) . ') ' . substr($value, 3, 3) . '-' . substr($value, 6);
                }
                
                return $value;
            },
            set: function ($value) {
                if (!$value) {
                    return null;
                }
                
                // Remove all non-numeric characters
                return preg_replace('/[^0-9]/', '', $value);
            }
        );
    }
}
