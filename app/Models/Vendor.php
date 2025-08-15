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

class Vendor extends Model
{
    use HasFactory, Searchable, HasAddress;

    protected $fillable = ['business_name', 'business_type', 'sheets_type', 'category_id', 'address', 'address_2', 'city', 'state', 'zip_code', 'business_phone', 'business_email', 'created_at', 'updated_at'];

    // protected $appends = ['name'];

    protected function casts(): array
    {
        return [
            'registration' => 'object', //array
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
                
                // // Fallback calculation for non-search queries
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
}
