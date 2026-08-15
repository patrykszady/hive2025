<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laragear\WebAuthn\Contracts\WebAuthnAuthenticatable;
use Laragear\WebAuthn\WebAuthnAuthentication;
use Laragear\WebAuthn\WebAuthnData;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements WebAuthnAuthenticatable, \Illuminate\Contracts\Translation\HasLocalePreference
{
    use HasApiTokens, HasFactory, Notifiable, WebAuthnAuthentication;

    /** Map a stored preferred_language label to an app locale code. */
    public static function localeFromLanguage(?string $language): string
    {
        return match ($language) {
            'Polish' => 'pl',
            'Spanish' => 'es',
            default => 'en',
        };
    }

    /**
     * Laravel uses this automatically for Mail::to($user) and notifications,
     * so every mailable renders in the recipient's language.
     */
    public function preferredLocale(): string
    {
        return static::localeFromLanguage($this->preferred_language);
    }

    /**
     * Domain stamped on contacts we know of but have no address for — the
     * second person on a household lead, say. users.email is NOT NULL UNIQUE,
     * so such a contact still needs a value; .invalid is reserved by RFC 2606
     * and never resolves, so nothing can be delivered to a real stranger.
     * Guard recipient lists with hasRoutableEmail().
     */
    public const PLACEHOLDER_EMAIL_DOMAIN = 'no-email.invalid';

    /**
     * Leading digits of the stand-in numbers used for contacts we have no
     * phone for (0000000001, 0000000008, … — the convention already in the
     * data). users.cell_phone is NOT NULL UNIQUE, so such a contact still
     * needs a distinct value. No real US number starts this way.
     */
    public const PLACEHOLDER_PHONE_PREFIX = '0000000';

    /**
     * Languages a user can choose as their preferred communication language.
     *
     * @var list<string>
     */
    public const PREFERRED_LANGUAGES = [
        'English',
        'Spanish',
        'Polish',
        'Ukrainian',
        'Portuguese',
        'French',
        'German',
        'Italian',
        'Russian',
        'Mandarin',
        'Vietnamese',
        'Arabic',
    ];

    /**
     * Is this user's email one we can actually send to? False for the
     * placeholder stamped on contacts provisioned without an address.
     */
    public function hasRoutableEmail(): bool
    {
        $email = trim((string) $this->email);

        return $email !== ''
            && ! str_ends_with(strtolower($email), '@'.self::PLACEHOLDER_EMAIL_DOMAIN);
    }

    /**
     * Is this user's number one we can actually text? False for the stand-in
     * numbers, so nothing tries to SMS +10000000008.
     */
    public function hasRoutablePhone(): bool
    {
        $digits = preg_replace('/\D/', '', (string) $this->cell_phone) ?? '';

        return $digits !== '' && ! str_starts_with($digits, self::PLACEHOLDER_PHONE_PREFIX);
    }

    protected $fillable = [
        'first_name',
        'last_name',
        'nickname',
        'preferred_language',
        'cell_phone',
        'email',
        'password',
        'email_verified_at',
        'primary_vendor_id',
        'remember_token',
        'created_at',
        'updated_at',
        'hourly_rate',
        'role',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'registration' => 'array',
            'email_verified_at' => 'datetime',
        ];
    }

    /**
     * Return WebAuthn data for passkey registration.
     */
    public function webAuthnData(): WebAuthnData
    {
        $displayName = trim((string) $this->full_name);

        if ($displayName === '') {
            $displayName = (string) $this->email;
        }

        if ($displayName === '') {
            $displayName = 'Hive User ' . $this->id;
        }

        $userName = (string) $this->email;

        if ($userName === '') {
            $userName = 'user-' . $this->id;
        }

        return new WebAuthnData(
            name: $userName,
            displayName: $displayName,
        );
    }

    /**
     * Boot the model and register events
     */
    protected static function booted(): void
    {
        // Register observer for update events
        static::observe(\App\Observers\UserObserver::class);
    }

    //Vendors USER belongs to
    public function vendors(): BelongsToMany
    {
        return $this->belongsToMany(Vendor::class)
            ->using(UserVendor::class)
            ->withTimestamps()
            ->withPivot(['is_employed', 'role_id', 'position', 'via_vendor_id', 'start_date', 'end_date', 'hourly_rate', 'options']);
    }

    //User's default/logged in vendor
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'primary_vendor_id')->withoutGlobalScopes();
    }

    /**
     * Get the pivot data for the primary vendor
     */
    protected function vendorPivot(): Attribute
    {
        return Attribute::make(
            get: function () {
                // if (!$this->vendor->id) {
                //     return null;
                // }
                
                // Use the vendors() relationship to get pivot data
                return $this->vendors()
                    ->where('vendor_id', auth()->user()->vendor->id)
                    ->first()
                    ?->pivot;
            }
        )->shouldCache();
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    public function clients(): BelongsToMany
    {
        return $this->belongsToMany(Client::class)->withTimestamps();
    }

    public function timesheets(): HasMany
    {
        return $this->hasMany(Timesheet::class);
    }

    public function task(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function distributions(): HasMany
    {
        return $this->hasMany(Distribution::class);
    }

    public function notificationSetting(): HasOne
    {
        return $this->hasOne(NotificationSetting::class);
    }
    
    /**
     * Get the user's vendor relationship for the primary vendor
     */
    // protected function primaryVendor(): Attribute
    /**
     * Get the relationship data between this user and their primary vendor
     */
    // protected function primaryVendorRelationship(): Attribute
    // {
    //     return Attribute::make(
    //         get: function () {
    //             if (!$this->primary_vendor_id) {
    //                 return null;
    //             }
                
    //             return $this->vendors()
    //                 ->where('vendor_id', $this->primary_vendor_id)
    //                 ->first();
    //         }
    //     );
    // }

    // /**
    //  * Get the vendor for the currently authenticated user
    //  */
    // protected function thisVendor(): Attribute
    // {
    //     return Attribute::make(
    //         get: function () {
    //             $authVendorId = auth()->user()?->vendor?->id;
    //             return $this->vendors->where('id', $authVendorId)->first();
    //         }
    //     );
    // }
    
    // public function via_vendor(): BelongsTo
    // {
    //     return $this->belongsTo(Vendor::class, 'primary_vendor_id')->withoutGlobalScopes();
    // }


    /**
     * Check if user is registered
     */
    protected function isRegistered(): Attribute
    {
        return Attribute::make(
            get: fn () => !($this->registration?->registered ?? false)
        );
    }
    /**
     * Get role name mapping from ID
     * 
     * @return array<int,string>
     */
    protected function roleNames(): array
    {
        return [
            1 => 'Admin',
            2 => 'Member',
        ];
    }
    
    /**
     * Get role for user's primary vendor relationship
    */
    protected function vendorRole(): Attribute
    {
        // shouldCache: every policy check reads this, and without caching each
        // one re-ran the pivot query (12x on a single project page).
        return Attribute::make(
            get: function () {
                if (!$this->vendor || !$this->vendor->id) {
                    return 'No Role';
                }
                
                // Use getRoleForVendor which is already optimized
                return $this->getRoleForVendor($this->vendor->id);
            }
        )->shouldCache();
    }

    /**
     * Get user's role for any vendor
    */
    /** Per-instance memo, keyed by vendor id — roles can't change mid-request. */
    protected array $roleForVendorMemo = [];

    /** @var array<string, \Illuminate\Support\Collection<int, int>> */
    protected array $otherVendorIdsMemo = [];

    /**
     * Vendor ids this user belongs to, excluding the given one. Read straight
     * from the pivot (the `vendors` relation carries VendorScope, which can
     * hide the user's own company). Memoized: CheckScope calls this on every
     * check query.
     *
     * @return \Illuminate\Support\Collection<int, int>
     */
    public function otherVendorIds($excludeVendorId): \Illuminate\Support\Collection
    {
        $key = (string) $excludeVendorId;

        return $this->otherVendorIdsMemo[$key] ??= \Illuminate\Support\Facades\DB::table('user_vendor')
            ->where('user_id', $this->id)
            ->where('vendor_id', '!=', $excludeVendorId)
            ->pluck('vendor_id')
            ->map(fn ($id) => (int) $id)
            ->values();
    }

    public function getRoleForVendor($vendorId): string
    {
        $memoKey = (string) $vendorId;

        if (isset($this->roleForVendorMemo[$memoKey])) {
            return $this->roleForVendorMemo[$memoKey];
        }

        // Check if vendors are already loaded to prevent extra query
        if ($this->relationLoaded('vendors')) {
            $vendor = $this->vendors->firstWhere('id', $vendorId);
        } else {
            $vendor = $this->vendors()->where('vendors.id', $vendorId)->first();
        }
        
        if (!$vendor) {
            return $this->roleForVendorMemo[$memoKey] = 'No Role';
        }
        
        $roleId = $vendor->pivot->role_id ?? null;
        return $this->roleForVendorMemo[$memoKey] = ($this->roleNames()[$roleId] ?? 'No Role');
    }
    
    /**
     * Convert a role ID to a readable role name
     * 
     * @param int|null $roleId
     * @return string
     */
    protected function getRoleNameFromId(?int $roleId): string
    {
        return match($roleId) {
            1 => 'Admin',
            2 => 'Member',
            default => 'No Role'
        };
    }

    /**
     * Normalize cell_phone to store only digits.
     */
    protected function cellPhone(): Attribute
    {
        return Attribute::make(
            set: function ($value) {
                if (!$value) {
                    return null;
                }
                
                // Remove all non-numeric characters
                return preg_replace('/[^0-9]/', '', $value);
            }
        );
    }
    
    /**
     * Get/set the user's first name with proper capitalization.
     */
    protected function firstName(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => static::titleCaseName($value),
            set: fn ($value) => static::titleCaseName($value)
        );
    }

    /**
     * Get/set the user's last name with proper capitalization.
     */
    protected function lastName(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => static::titleCaseName($value),
            set: fn ($value) => static::titleCaseName($value)
        );
    }

    /**
     * Title-case a person's name, capitalizing after spaces, hyphens and
     * apostrophes (O'Brien, Jean-Luc) without mangling accented letters.
     */
    protected static function titleCaseName(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        return preg_replace_callback(
            "/(?:^|[\s\-\x{2019}'])\p{L}/u",
            fn ($m) => mb_strtoupper($m[0], 'UTF-8'),
            mb_strtolower($value, 'UTF-8')
        );
    }

    /**
     * Get the user's full name.
     */
    protected function fullName(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->first_name . ' ' . $this->last_name,
        );
    }

    //on vendor->user queries
    public function scopeEmployed($query)
    {
        return $query->where('is_employed', 1);
    }

    public function isEmployed(): bool
    {
        $currentUser = auth()->user();

        if (! $currentUser?->vendor) {
            return false;
        }

        return $this->vendors()
            ->where('vendors.id', $currentUser->vendor->id)
            ->wherePivot('is_employed', 1)
            ->exists();
    }

    public function routeNotificationForTelnyx()
    {
        if (!$this->cell_phone) {
            return null;
        }

        // Stand-in numbers exist so a phone-less contact can have a user row;
        // they are not reachable. Returning null here keeps every Telnyx
        // notification path from attempting a send to them.
        if (! $this->hasRoutablePhone()) {
            return null;
        }

        $phone = preg_replace('/[^0-9]/', '', $this->cell_phone);

        // If it's a 10-digit US number, add +1
        if (strlen($phone) === 10) {
            return '+1' . $phone;
        }

        // Default: add + to whatever we have
        return '+' . $phone;
    }

    /**
     * Every storage variant of this user's cell phone that could appear as an
     * SMS thread participant (+1XXXXXXXXXX, 1XXXXXXXXXX, bare 10-digit, raw).
     *
     * Single source of truth for client-user thread visibility — used by
     * SmsGroupThread::scopeAccessibleTo and anywhere phone-participant
     * matching is needed.
     *
     * @return array<int, string>
     */
    public function smsParticipantPhoneVariants(): array
    {
        $rawPhone = $this->routeNotificationForTelnyx();

        if (! is_string($rawPhone) || $rawPhone === '') {
            return [];
        }

        $digits = preg_replace('/\D/', '', $rawPhone);
        if (! is_string($digits) || $digits === '') {
            return [];
        }

        if (strlen($digits) === 10) {
            return array_values(array_unique([$rawPhone, '+1' . $digits, '1' . $digits, $digits]));
        }

        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            $tenDigit = substr($digits, 1);

            return array_values(array_unique([$rawPhone, '+' . $digits, $digits, '+1' . $tenDigit, $tenDigit]));
        }

        return array_values(array_unique([$rawPhone, '+' . $digits, $digits]));
    }

    /**
     * Get the via vendor for this user based on current vendor context
     */
    protected function viaVendor(): Attribute
    {
        return Attribute::make(
            get: function () {
                $currentUser = auth()->user();

                if (! $currentUser?->vendor) {
                    return null;
                }

                // Get user's relationship with the current vendor context
                $userVendorPivot = $this->vendors()
                    ->where('vendors.id', $currentUser->vendor->id)
                    ->first();
                
                // Return null if no pivot found or no via_vendor_id
                if (!$userVendorPivot || !$userVendorPivot->pivot->via_vendor_id) {
                    return null;
                }
                
                // Fetch the via vendor model
                return Vendor::find($userVendorPivot->pivot->via_vendor_id);
            }
        )->shouldCache();
    }

    /**
     * Check if this user is a client-only user (no vendor relationships).
     */
    protected function isClientUser(): Attribute
    {
        // shouldCache: membership can't change mid-request, and per-card
        // blade checks were re-running these COUNT queries hundreds of
        // times per planner render.
        return Attribute::make(
            get: fn () => $this->vendors()->count() === 0 && $this->clients()->count() > 0
        )->shouldCache();
    }

    /**
     * Check if this user is currently browsing as a client.
     * True when: client-only user, OR vendor user without a primary vendor selected who has clients.
     */
    protected function isBrowsingAsClient(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->is_client_user || (!$this->primary_vendor_id && $this->clients()->exists())
        )->shouldCache();
    }

    /**
     * Check if this user has any vendor relationships.
     */
    protected function isVendorUser(): Attribute
    {
        // shouldCache: membership can't change mid-request (see isClientUser).
        return Attribute::make(
            get: fn () => $this->vendors()->count() > 0
        )->shouldCache();
    }

    /**
     * Get the primary client for a client-only user.
     */
    protected function primaryClient(): Attribute
    {
        // shouldCache: without it a null result re-queries on every access.
        return Attribute::make(
            get: fn () => $this->clients()->first()
        )->shouldCache();
    }
}
