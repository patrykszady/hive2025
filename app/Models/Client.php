<?php

namespace App\Models;

use App\Scopes\ClientScope;
use App\Scopes\VendorScope;

use App\Traits\HasAddress;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Client extends Model
{
    use HasFactory, HasAddress;

    protected $fillable = ['business_name', 'address', 'address_2', 'city', 'state', 'zip_code', 'home_phone', 'source', 'created_at', 'updated_at'];

    protected static function booted()
    {
        static::addGlobalScope(new ClientScope);
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
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
    protected function name(): Attribute
    {
        return Attribute::make(
            get: function ($value, array $attributes) {
                if (empty($attributes['business_name'])) {
                    $users = $this->users;

                    if ($users->count() == 0) {
                        return 'No Name';
                    }
                    
                    if ($users->count() == 1) {
                        return $users->first()->first_name.' '.$users->first()->last_name;
                    } else {
                        // Group users by last name
                        $usersByLastName = $users->groupBy('last_name');
                        
                        $nameGroups = [];
                        
                        // Process each last name group
                        foreach ($usersByLastName as $lastName => $lastNameGroup) {
                            if ($lastNameGroup->count() == 1) {
                                // Single person with this last name - use full name
                                $nameGroups[] = $lastNameGroup->first()->first_name . ' ' . $lastName;
                            } else {
                                // Multiple people with same last name - combine first names
                                $firstNames = $lastNameGroup->pluck('first_name')->toArray();
                                $nameGroups[] = implode(' & ', $firstNames) . ' ' . $lastName;
                            }
                        }
                        
                        // Join all name groups with " & "
                        return implode(' & ', $nameGroups);
                    }
                } else {
                    // Extract first part before ',' if available
                    $nameParts = explode(',', $attributes['business_name']);
                    return trim($nameParts[0]);
                }
            }
        );
    }

    /**
     * Get just the first names for greeting purposes
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

                if ($users->count() == 1) {
                    return $users->first()->first_name;
                }

                // Multiple users - combine first names with &
                $firstNames = $users->pluck('first_name')->toArray();
                return implode(' & ', $firstNames);
            }
        );
    }

    /**
     * Get the source attribute from the pivot table
     */
    protected function source(): Attribute
    {
        return Attribute::make(
            get: function ($value, array $attributes) {
                return $this->vendors()->wherePivot('vendor_id', auth()->user()->vendor->id)->first()?->pivot->source;
            }
        );
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
