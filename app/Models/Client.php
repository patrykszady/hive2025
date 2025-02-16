<?php

namespace App\Models;

use App\Models\Scopes\ClientScope;
use App\Models\Scopes\VendorScope;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Client extends Model
{
    use HasFactory;

    protected $with = ['users'];

    protected $fillable = ['business_name', 'address', 'address_2', 'city', 'state', 'zip_code', 'home_phone', 'source', 'created_at', 'updated_at'];

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

    public function getNameAttribute()
    {
        if ($this->business_name == null) {
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
            return $this->business_name;
        }
    }

    public function getSourceAttribute()
    {
        return $this->vendors()->wherePivot('vendor_id', auth()->user()->vendor->id)->first()->pivot->source;
    }

    public function getFullAddressAttribute()
    {
        if ($this->address_2 == null) {
            $address1 = $this->address;
        } else {
            $address1 = $this->address.'<br>'.$this->address_2;
        }
        $address2 = $this->city.', '.$this->state.' '.$this->zip_code;

        return $address1.'<br>'.$address2;
    }

    public function getOneLineAddressAttribute()
    {
        if ($this->address_2) {
            $address = $this->address.' | '.$this->address_2.' | '.$this->city.', '.$this->state.' '.$this->zip_code;
        } elseif ($this->address) {
            $address = $this->address.' | '.$this->city.', '.$this->state.' '.$this->zip_code;
        } else {
            $address = null;
        }

        return $address;
    }

    public function setBusinessNameAttribute($value)
    {
        $this->attributes['business_name'] = ucwords(strtolower($value));
    }

    public function setAddressAttribute($value)
    {
        $this->attributes['address'] = ucwords(strtolower($value));
    }

    // public function Address2(): Attribute
    // {
    //     return Attribute::make(
    //         // get: fn (string $value) => strto
    //         // ucwords(strtoupper($value))upper($value),
    //         set: fn (string $value) => strtoupper($value),
    //     );
    // }

    public function setCityAttribute($value)
    {
        $this->attributes['city'] = ucwords(strtolower($value));
    }

    public function setStateAttribute($value)
    {
        $this->attributes['state'] = strtoupper($value);
    }

    public function setZipCodeAttribute($value)
    {
        $this->attributes['zip_code'] = strtolower($value);
    }
}
