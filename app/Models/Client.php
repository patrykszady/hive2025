<?php

namespace App\Models;

use App\Models\Scopes\ClientScope;
use App\Models\Scopes\VendorScope;
use App\Traits\HasAddress;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Client extends Model
{
    use HasFactory, HasAddress;

    protected $with = ['users'];

    protected $fillable = ['business_name', 'address', 'address_2', 'city', 'state', 'zip_code', 'home_phone', 'source', 'created_at', 'updated_at'];

    protected $appends = ['name'];

    protected static function booted()
    {
        static::addGlobalScope(new ClientScope);
    }

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_vendor', 'client_id', 'project_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class)->withoutGlobalScope(VendorScope::class);
    }

    public function vendors(): BelongsToMany
    {
        return $this->belongsToMany(Vendor::class)->withPivot('source', 'vendor_id')->withTimestamps();
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

                    if ($users->count() == 1) {
                        return $users->first()->first_name.' '.$users->first()->last_name;
                    } else {
                        $users_last_names = $users->groupBy('last_name');

                        if ($users_last_names->count() == 1) {
                            $users_implode = [];
                            foreach ($users as $user) {
                                $users_implode[] = $user->first_name;
                            }

                            $users_implode = implode(' & ', $users_implode);
                            $users_last_name = array_keys($users_last_names->toArray())[0];

                            return $users_implode.' '.$users_last_name;
                        } else {
                            $users_implode = [];
                            foreach ($users as $user) {
                                $users_implode[] = $user->first_name.' '.$user->last_name;
                            }

                            return implode(' & ', $users_implode);
                        }
                    }
                } else {
                    return $attributes['business_name'];
                }
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
