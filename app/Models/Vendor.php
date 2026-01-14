<?php

namespace App\Models;

use App\Traits\HasAddress;
use Laravel\Scout\Searchable;

use App\Scopes\ClientScope;
use App\Scopes\VendorScope;

use App\Collections\SearchableCollection;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Notifications\Notifiable;
use Carbon\Carbon;

class Vendor extends Model
{
    use HasFactory, Notifiable, Searchable, HasAddress;

    protected $fillable = ['business_name', 'business_type', 'sheets_type', 'category_id', 'address', 'address_2', 'city', 'state', 'zip_code', 'business_phone', 'business_email', 'timezone', 'short_name', 'availability_token', 'availability_short_url', 'created_at', 'updated_at'];
    // protected $appends = ['name'];

    protected function casts(): array
    {
        return [
            'registration' => 'array',
            'options'=> 'object',
        ];
    }

    protected static function booted()
    {
        static::addGlobalScope(new VendorScope);
    }

    //Searchable
    public function toSearchableArray(): array
    {
        // Only calculate if not already loaded
        $ytdExpenseSum = $this->ytd_expense_sum ?? $this->expenses()
            ->where('created_at', '>=', today()->subYear())
            ->sum('amount');

        return array_merge($this->toArray(), [
            'id' => (string) $this->id,
            'business_name' => $this->business_name,
            'business_type' => $this->business_type,
            'ytd_expense_sum' => (float) $ytdExpenseSum,
            'created_at' => $this->created_at->timestamp,
        ]);
    }

    // Get the name of the index associated with the model.
    public function searchableAs(): string
    {
        return env('APP_ENV') == 'local' ? 'vendors_index_dev' : 'vendors_index';
    }

    public function getOrCreateAvailabilityToken(): string
    {
        if (! empty($this->availability_token)) {
            return $this->availability_token;
        }

        // 8 bytes = 16 hex chars - short enough for SMS but still unique
        $token = bin2hex(random_bytes(8));
        $this->forceFill(['availability_token' => $token])->saveQuietly();

        return $token;
    }

    public function vendor_categories(): BelongsToMany
    {
        return $this->belongsToMany(VendorCategory::class, 'category_vendor', 'vendor_id', 'vendor_category_id')->withTimestamps();
    }

    //Vendors that belong to Logged in vendor(Company) / via $user->vendor->id
    public function vendors(): BelongsToMany
    {
        return $this->belongsToMany(Vendor::class, 'vendors_vendor', 'belongs_to_vendor_id')->withoutGlobalScopes()->withTimestamps();
    }

    public function vendor(): BelongsToMany
    {
        return $this->belongsToMany(Vendor::class, 'vendors_vendor', 'vendor_id')->withTimestamps();
    }
    
    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class)->withTimestamps();
    }

    public function estimates(): BelongsToMany
    {
        return $this->belongsToMany(Estimate::class)->withTimestamps();
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function receipt_account(): HasOne
    {
        return $this->hasOne(ReceiptAccount::class);
    }

    public function receipt_accounts(): HasMany
    {
        return $this->hasMany(ReceiptAccount::class, 'belongs_to_vendor_id');
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(Receipt::class);
    }

    public function task(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /**
     * Route notifications for the Twilio channel.
     * Returns the business_phone formatted for Twilio.
     */
    public function routeNotificationForTwilio(): ?string
    {
        if (! $this->business_phone) {
            return null;
        }

        $phone = preg_replace('/[^0-9]/', '', $this->business_phone);

        // If it's a 10-digit US number, add +1
        if (strlen($phone) === 10) {
            return '+1'.$phone;
        }

        // Default: add + to whatever we have
        return '+'.$phone;
    }

    /**
     * Get all admin users for this vendor who have cell phones.
     * Used for sending vendor availability SMS notifications.
     *
     * @return \Illuminate\Support\Collection<User>
     */
    public function getAdminUsersWithCellPhones(): \Illuminate\Support\Collection
    {
        return $this->users()
            ->wherePivot('role_id', 1) // role_id 1 = Admin
            ->whereNotNull('cell_phone')
            ->where('cell_phone', '!=', '')
            ->get();
    }

    public function transactions_bulk_match(): HasMany
    {
        return $this->hasMany(TransactionBulkMatch::class);
    }

    public function company_emails(): HasMany
    {
        return $this->hasMany(CompanyEmail::class);
    }

    public function bids(): HasMany
    {
        return $this->hasMany(Bid::class);
    }

    public function vendor_docs(): HasMany
    {
        return $this->hasMany(VendorDoc::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function banks(): HasMany
    {
        return $this->hasMany(Bank::class);
    }

    public function distributions(): HasMany
    {
        return $this->hasMany(Distribution::class);
    }

    public function bank_accounts(): HasMany
    {
        return $this->hasMany(BankAccount::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function checks(): HasMany
    {
        return $this->hasMany(Check::class);
    }

    public function hours(): HasMany
    {
        return $this->hasMany(Hour::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->using(UserVendor::class)
            ->withPivot(['is_employed', 'role_id', 'via_vendor_id', 'start_date', 'end_date', 'hourly_rate']);
    }

    public function clients(): BelongsToMany
    {
        return $this->belongsToMany(Client::class)->withTimestamps();
    }

    //the client this vendor belongs to (like via_vendor )
    public function client(): HasOne
    {
        return $this->hasOne(Client::class)->withoutGlobalScope(ClientScope::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'belongs_to_vendor_id');
    }

    public function scopeHiveVendors($query)
    {
        return $query->withoutGlobalScopes()->where('business_type', 'Sub')->where('registration->registered', true);
    }

    /**
     * Get the name attribute based on business type and name
     */
    protected function name(): Attribute
    {
        return Attribute::make(
            get: function ($value, array $attributes) {
                if (empty($attributes['business_name'])) {
                    return 'NO VENDOR';
                }
                
                // For 1099 vendors, use the first associated user's name
                // Add isset check to prevent "undefined array key" error
                // if (isset($attributes['business_type']) && $attributes['business_type'] == '1099' && $this->users()->exists()) {
                //     $user = $this->users()->first();
                //     return $user->first_name . ' ' . $user->last_name;
                // }
                
                // Extract first part before ',' if available
                $nameParts = explode(',', $attributes['business_name']);
                return trim($nameParts[0]);
            }
        );
    }

    protected function shortName(): Attribute
    {
        return Attribute::make(
            get: function (mixed $value, array $attributes): ?string {
                $shortName = data_get($this->options, 'short_name');

                if (! is_string($shortName) || $shortName === '') {
                    return $this->name;
                }

                return $shortName;
            },
            set: function (mixed $value, array $attributes): array {
                $options = [];

                $rawOptions = $attributes['options'] ?? null;

                if (is_string($rawOptions) && $rawOptions !== '') {
                    $decoded = json_decode($rawOptions, true);
                    if (is_array($decoded)) {
                        $options = $decoded;
                    }
                } elseif (is_array($rawOptions)) {
                    $options = $rawOptions;
                }

                if (! is_string($value) || $value === '') {
                    unset($options['short_name']);
                } else {
                    $options['short_name'] = $value;
                }

                return ['options' => $options];
            }
        );
    }

        /**
     * Get the SKU search URL from the options JSON column
     */
    protected function skuSearchUrl(): Attribute
    {
        return Attribute::make(
            get: function ($value, array $attributes) {
                // Get from options if it exists
                if (isset($this->options) && isset($this->options->sku_search_url)) {
                    return $this->options->sku_search_url;
                }
                
                return null;
            }
        );
    }

    /**
     * Get the year-to-date expense sum
     */
    protected function ytdExpenseSum(): Attribute
    {
        return Attribute::make(
            get: function ($value, array $attributes) {
                // If it's already set (from search results), return it
                if (array_key_exists('ytd_expense_sum', $attributes)) {
                    return $attributes['ytd_expense_sum'];
                }
                
                // Fallback calculation for non-search queries
                return $this->expenses()
                    ->where('created_at', '>=', today()->subYear())
                    ->sum('amount');
            }
        );
    }

    protected function businessPhone(): Attribute
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
    
    protected function businessName(): Attribute
    {
        return Attribute::make(
            get: function ($value) {
                if (is_null($value)) {
                    return 'NO VENDOR';
                }
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
     * Format business_email to be all lowercase
     */
    protected function businessEmail(): Attribute
    {
        return Attribute::make(
            get: function ($value) {
                return $value;
            },
            set: function ($value) {
                if (!$value) {
                    return null;
                }
                
                // Convert to all lowercase
                return strtolower($value);
            }
        );
    }

    /**
     * Accessor for the vendor's registration date as a Carbon instance.
     * Falls back to null if unavailable or unparsable.
     */
    protected function registrationDate(): Attribute
    {
        return Attribute::make(
            get: function ($value, array $attributes) {
                // When cast as array, $this->registration may be array or null
                if (!isset($this->registration) || !isset($this->registration['registration_date'])) {
                    return null;
                }
                $raw = $this->registration['registration_date'];
                if (empty($raw)) {
                    return null;
                }
                try {
                    return Carbon::parse($raw);
                } catch (\Throwable $e) {
                    return null;
                }
            }
        );
    }
}
