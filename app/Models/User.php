<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'first_name',
        'last_name',
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

    //Vednors USER belongs to
    public function vendors(): BelongsToMany
    {
        return $this->belongsToMany(Vendor::class)->using(UserVendor::class)->withoutGlobalScopes()->withTimestamps()->with('vendor')->withPivot(['is_employed', 'role_id', 'via_vendor_id', 'start_date', 'end_date', 'hourly_rate']);
    }

    //User's default/logged in vendor
    public function vendor(): BelongsTo
    {
        // dd($this->vendors()->find($this->primary_vendor_id));
        // return $this->vendors()->find($this->primary_vendor_id);
        return $this->belongsTo(Vendor::class, 'primary_vendor_id')->withoutGlobalScopes();
    }

    // public function primary_vendor()
    // {
    //     // return $this->belongsTo(Vendor::class, 'primary_vendor_id');
    //     return $this->vendor->users()->find($this->primary_vendor);
    // }

    public function via_vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'primary_vendor_id')->withoutGlobalScopes();
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Leads::class);
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


    
    /**
     * Get the user's vendor relationship for the primary vendor
     */
    protected function primaryVendor(): Attribute
    {
        return Attribute::make(
            get: function () {
                // Add null check to prevent the error
                if (!$this->vendor) {
                    return null;
                }
                
                return $this->vendor->users()->find($this->id);
            }
        );
    }

    /**
     * Check if user is registered
     */
    protected function isRegistered(): Attribute
    {
        return Attribute::make(
            get: fn () => !!($this->registration['registered'] ?? false)
        );
    }

    /**
     * Get the vendor for the currently authenticated user
     */
    protected function thisVendor(): Attribute
    {
        return Attribute::make(
            get: function () {
                $authVendorId = auth()->user()?->vendor?->id;
                return $this->vendors->where('id', $authVendorId)->first();
            }
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
        return Attribute::make(
            get: function () {
                // Check if vendor exists first to prevent errors
                if (!$this->vendor) {
                    return 'No Role';
                }
                
                // Check if primary_vendor relationship exists
                if (!$this->primary_vendor) {
                    return 'No Role';
                }
                
                $roleId = $this->primary_vendor->pivot->role_id ?? null;
                return $this->roleNames()[$roleId] ?? 'No Role';
            }
        );
    }
    
    /**
     * Get user's role for any vendor
     * 
     * @param int $vendorId
     * @return string
     */
    public function getRoleForVendor($vendorId): string
    {
        // Check if vendors are already loaded to prevent extra query
        if ($this->relationLoaded('vendors')) {
            $vendor = $this->vendors->firstWhere('id', $vendorId);
        } else {
            $vendor = $this->vendors()->where('vendors.id', $vendorId)->first();
        }
        
        if (!$vendor) {
            return 'No Role';
        }
        
        $roleId = $vendor->pivot->role_id ?? null;
        return $this->roleNames()[$roleId] ?? 'No Role';
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

    public function routeNotificationForTwilio()
    {
        if (!$this->cell_phone) {
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
}
