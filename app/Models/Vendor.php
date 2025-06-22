<?php

namespace App\Models;

use App\Models\Scopes\ClientScope;
use App\Models\Scopes\VendorScope;

use App\Collections\SearchableCollection;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

use Laravel\Scout\Searchable;

class Vendor extends Model
{
    use HasFactory, Searchable;

    protected $fillable = ['business_name', 'business_type', 'sheets_type', 'category_id', 'address', 'address_2', 'city', 'state', 'zip_code', 'business_phone', 'business_email', 'created_at', 'updated_at'];

    protected $appends = ['name'];

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

    /**
     * Get the name of the index associated with the model.
     */
    public function searchableAs(): string
    {
        return env('APP_ENV') == 'local' ? 'vendors_index_dev' : 'vendors_index';
    }

    public function vendor_categories(): BelongsToMany
    {
        return $this->belongsToMany(VendorCategory::class, 'category_vendor', 'vendor_id', 'vendor_category_id')->withTimestamps();
    }

    //Vendors that belong to Logged in vendor / via $user->primary_vendor_id
    public function vendors(): BelongsToMany
    {
        return $this->belongsToMany(Vendor::class, 'vendors_vendor', 'belongs_to_vendor_id')->withoutGlobalScopes()->withTimestamps();
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

    public function vendor(): BelongsToMany
    {
        return $this->belongsToMany(Vendor::class, 'vendors_vendor', 'vendor_id')->withTimestamps();
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

    // public function project_status()
    // {
    //     return $this->hasMany(ProjectStatus::class, '');
    // }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function hours(): HasMany
    {
        return $this->hasMany(Hour::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->with('vendor')->withPivot(['is_employed', 'role_id', 'via_vendor_id', 'start_date', 'end_date', 'hourly_rate']);
    }

    public function clients(): BelongsToMany
    {
        return $this->belongsToMany(Client::class);
    }

    public function client(): HasOne
    {
        return $this->hasOne(Client::class)->withoutGlobalScope(ClientScope::class);
    }

    public function getRegistrationAttribute($value)
    {
        $value = json_decode($value, true);
        $status_array = ['registered', 'vendor_info', 'team_members', 'user_registered', 'banks_registered', 'emails_registered'];

        foreach ($status_array as $status) {
            if (! isset($value[$status])) {
                $value[$status] = false;
            }
        }

        return $value;
    }

    public function getFullAddressAttribute()
    {
        if ($this->address_2) {
            $address = $this->address.'<br>'.$this->address_2.'<br>'.$this->city.', '.$this->state.' '.$this->zip_code;
        } elseif ($this->address) {
            $address = $this->address.'<br>'.$this->city.', '.$this->state.' '.$this->zip_code;
        } else {
            $address = null;
        }

        return $address;
    }

    public function getBusienssNameAttribute()
    {
        if (is_null($this->business_name)) {
            return 'NO VENDOR';
        } else {
            return html_entity_decode($this->business_name);
        }
    }

    public function getNameAttribute()
    {
        if (is_null($this->business_name)) {
            return 'NO VENDOR';
        }

        if ($this->biz_type == 4 && $this->users()->exists()) {
            $user = $this->users()->first();
            return html_entity_decode($user->first_name . ' ' . $user->last_name);
        }

        // Extract first part before ',' if available
        $nameParts = explode(',', $this->business_name);
        return html_entity_decode(trim($nameParts[0]));
    }

    public function getAddressMapURI()
    {
        //2025-6-21 lets use google maps api for this
        $url = 'https://maps.apple.com/?q='.$this->address.', '.$this->city.', '.$this->state.', '.$this->zip_code;

        return $url;
    }

    public function getYtdExpenseSumAttribute()
    {
        // If it's already set (from search results), return it
        if (array_key_exists('ytd_expense_sum', $this->attributes)) {
            return $this->attributes['ytd_expense_sum'];
        }

        // Fallback calculation for non-search queries
        return $this->expenses()
            ->where('created_at', '>=', today()->subYear())
            ->sum('amount');
    }

    public function scopeHiveVendors($query)
    {
        return $query->withoutGlobalScopes()->where('business_type', 'Sub')->where('registration->registered', true);
    }
}
