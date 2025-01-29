<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
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

    public function getPrimaryVendorAttribute()
    {
        return $this->vendor->users()->find($this->id);
    }

    //via_vendor
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

    public function getVendorRoleAttribute()
    {
        // $vendor_id = $this->pivot->vendor_id;
        // $role_id = $this->vendors()->where('vendors.id', $vendor_id)->first()->pivot->role_id;
        $role_id = $this->primary_vendor->pivot->role_id;

        if ($role_id == 1) {
            $role = 'Admin';
        } elseif ($role_id == 2) {
            $role = 'Member';
        } else {
            $role = 'No Role';
        }

        return $role;
    }

    public function getVendorRole($vendor_id)
    {
        $role_id = $this->vendors()->where('vendors.id', $vendor_id)->first()->pivot->role_id;

        if ($role_id == 1) {
            $role = 'Admin';
        } elseif ($role_id == 2) {
            $role = 'Member';
        } else {
            $role = 'No Role';
        }

        return $role;
    }

    public function getRegistrationAttribute($value)
    {
        return json_decode($value, true) ?? '';
    }

    public function getFullNameAttribute()
    {
        return $this->first_name.' '.$this->last_name;
    }

    //on vendor->user queries
    public function scopeEmployed($query)
    {
        return $query->where('is_employed', 1);
    }
}
