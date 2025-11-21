<?php

namespace App\Models;

use App\Models\Client;
use App\Models\ProjectStatus;
use App\Models\ProjectVendor;
use App\Scopes\ProjectScope;
use App\Traits\HasAddress;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

class Project extends Model
{
    use HasFactory, HasAddress;

    protected $fillable = ['project_name', 'client_id', 'belongs_to_vendor_id', 'created_by_user_id', 'note', 'timesheet_id', 'created_by_user_id', 'note', 'do_not_include', 'address', 'address_2', 'city', 'state', 'zip_code', 'created_at', 'updated_at'];

    protected static function booted()
    {
        static::addGlobalScope(new ProjectScope);
    }

    public function distributions(): BelongsToMany
    {
        return $this->belongsToMany(Distribution::class)->withPivot('percent', 'amount', 'created_at')->withTimestamps();
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function expenseSplits(): HasMany
    {
        return $this->hasMany(ExpenseSplits::class);
    }

    public function bids(): HasMany
    {
        return $this->hasMany(Bid::class);
    }

    public function vendors(): BelongsToMany
    {
        return $this->belongsToMany(Vendor::class)->withTimestamps();
    }

    public function createdByVendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'belongs_to_vendor_id');
    }
    // //projects many to many vendors
    // public function vendors(): BelongsToMany
    // {
    //     return $this->belongsToMany(Vendor::class)->withPivot('client_id')->withTimestamps();
    // }

    // public function vendor(): belongsToMany
    // {
    //     //project has one vendor via the project_vendor pivot table
    //     // return $this->belongsTo(Vendor::class);
    //     return $this->belongsToMany(Vendor::class)->withPivot('client_id')->withTimestamps();
    // }

    // public function getVendorAttribute()
    // {
    //     return $this->vendor()->first();
    // }

    /**
     * Get the client for the project through the project_vendor pivot table
     */
    public function client(): HasOneThrough
    {
        return $this->hasOneThrough(
            Client::class,
            ProjectVendor::class,
            'project_id', // Foreign key on project_vendor table
            'id',         // Foreign key on clients table
            'id',         // Local key on projects table
            'client_id'   // Local key on project_vendor table
        );
    }

    // public function clients(): BelongsToMany
    // {
    //     //through project_vendor->client_id
    //     return $this->belongsToMany(Client::class, 'project_vendor')->withPivot('vendor_id')->withTimestamps();
    // }

    // public function client(): belongsToMany
    // {
    //     //project has one client via the project_vendor pivot table client_id
    //     // return $this->hasOneThrough(Client::class, 'project_vendor_pivot', 'project_id', 'client_id');
    //     //->using(ProjectVendor::class)
    //     return $this->belongsToMany(Client::class, 'project_vendor')->withPivot('vendor_id')->withTimestamps();
    // }

    // public function getClientAttribute()
    // {
    //     return $this->client()->wherePivot('vendor_id', $this->vendor->id)->first();
    // }

    public function estimates(): HasMany
    {
        return $this->hasMany(Estimate::class);
    }

    public function hours(): HasMany
    {
        return $this->hasMany(Hour::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function timesheets(): HasMany
    {
        return $this->hasMany(Timesheet::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function statuses(): HasMany
    {
        return $this->hasMany(ProjectStatus::class);
    }

    public function latestStatus(): HasOne
    {
        return $this->hasOne(ProjectStatus::class)->latestOfMany('start_date'); // Automatically picks the latest
    }

    public function scopeStatus($query, $status)
    {
        return $query->whereHas('latestStatus', function ($q) use ($status) {
            $q->whereIn('status_code', $status); // Check if the latest status code is in the given array
        });
    }

    public function scopeNotCancelled(Builder $query): Builder
    {
        return $query->whereHas('latestStatus', function ($q) {
            $q->where('status_code', '!=', 10);
        });
    }

    public function scopeOrderByLatestStatusDateDesc(Builder $query): Builder
    {
        return $query->orderBy(
            ProjectStatus::select('start_date')
                ->whereColumn('project_id', 'projects.id')
                ->latest('start_date')
                ->take(1),
            'desc'
        );
    }

    /**
     * Handle project_name formatting and access
     */
    protected function projectName(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value,
            set: fn ($value) => $value ? ucwords(strtolower($value)) : null
        );
    }

    /**
     * Get the display name for the project
     */
    protected function name(): Attribute
    {
        return Attribute::make(
            get: function ($value, array $attributes) {
                // Split placeholder
                if (isset($attributes['split']) && $attributes['split']) {
                    return 'SPLIT';
                }

                // Distribution synthetic naming
                if (isset($attributes['distribution']) && $attributes['distribution']) {
                    if (isset($attributes['distribution_name']) && ! empty($attributes['distribution_name'])) {
                        return $attributes['distribution_name'];
                    }
                    if (isset($attributes['project_name']) && ! empty($attributes['project_name'])) {
                        return $attributes['project_name'];
                    }
                }

                // If we lack a project_name entirely
                if (! isset($attributes['project_name']) || empty($attributes['project_name'])) {
                    return 'No Project';
                }

                // Normalize explicit NO PROJECT strings
                $specialNames = ['No Project', 'NO PROJECT'];
                if (in_array($attributes['project_name'], $specialNames, true)) {
                    return 'No Project';
                }

                // Standard project: include address prefix if present
                if (! empty($attributes['address'])) {
                    return $attributes['address'] . ' | ' . $attributes['project_name'];
                }

                return $attributes['project_name'];
            }
        );
    }

    /**
     * Get the calculated financial data for the project
     */
    protected function finances(): Attribute
    {
        return Attribute::make(
            get: function ($value, array $attributes) {
                $expenses_sum = $this->expenses()->where('reimbursment', 'Client')->sum('amount');
                $splits_sum = $this->expenseSplits()->where('reimbursment', 'Client')->sum('amount');

                $finances = [];
                $bid_estimate_total = (float) $this->bids()->where('type', 1)->sum('amount');
                
                // If no finalized bids exist, calculate from estimate sections
                if ($bid_estimate_total == 0) {
                    $unfinalized_estimate_total = $this->estimates()
                        ->with('estimate_sections')
                        ->get()
                        ->flatMap(function ($estimate) {
                            return $estimate->estimate_sections;
                        })
                        ->sum('total');
                    $finances['estimate'] = (float) $unfinalized_estimate_total;
                } else {
                    $finances['estimate'] = $bid_estimate_total;
                }
                
                $finances['change_orders'] = $this->bids()->where('type', '!=', 1)->sum('amount');
                $finances['total_bid'] = $finances['estimate'] + $finances['change_orders'];
                $finances['reimbursments'] = $splits_sum + $expenses_sum;
                $finances['total_project'] = round($finances['reimbursments'] + $finances['estimate'] + $finances['change_orders'], 2);
                $finances['expenses'] = $this->expenses->sum('amount') + $this->expenseSplits->sum('amount');
                $finances['timesheets'] = $this->timesheets->sum('amount');
                $finances['total_cost'] = $finances['timesheets'] + $finances['expenses'];
                $finances['payments'] = round($this->payments->sum('amount'), 2);
                $finances['profit'] = $finances['payments'] - $finances['total_cost'];
                $finances['balance'] = $finances['total_project'] - $finances['payments'];

                return $finances;
            }
        );
    }
}