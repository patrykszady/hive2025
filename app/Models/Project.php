<?php

namespace App\Models;

use App\Scopes\ProjectScope;
use App\Traits\HasAddress;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\Casts\Attribute;

class Project extends Model
{
    use HasFactory, HasAddress;

    protected $fillable = ['project_name', 'client_id', 'belongs_to_vendor_id', 'created_by_user_id', 'note', 'timesheet_id', 'created_by_user_id', 'note', 'do_not_include', 'address', 'address_2', 'city', 'state', 'zip_code', 'created_at', 'updated_at'];

    protected $appends = ['name'];

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

    public function bids(): HasMany
    {
        return $this->hasMany(Bid::class);
    }

    //projects many to many vendors
    public function vendors(): BelongsToMany
    {
        return $this->belongsToMany(Vendor::class)->withPivot('client_id')->withTimestamps();
    }

    public function vendor(): belongsToMany
    {
        //project has one vendor via the project_vendor pivot table
        // return $this->belongsTo(Vendor::class);
        return $this->belongsToMany(Vendor::class)->withPivot('client_id')->withTimestamps();
    }

    public function getVendorAttribute()
    {
        return $this->vendor()->first();
    }

    public function expenseSplits(): HasMany
    {
        return $this->hasMany(ExpenseSplits::class);
    }

    public function clients(): BelongsToMany
    {
        //through project_vendor->client_id
        return $this->belongsToMany(Client::class, 'project_vendor')->withPivot('vendor_id')->withTimestamps();
    }

    public function client(): belongsToMany
    {
        //project has one client via the project_vendor pivot table client_id
        // return $this->hasOneThrough(Client::class, 'project_vendor_pivot', 'project_id', 'client_id');
        //->using(ProjectVendor::class)
        return $this->belongsToMany(Client::class, 'project_vendor')->withPivot('vendor_id')->withTimestamps();
    }

    public function getClientAttribute()
    {
        return $this->client()->wherePivot('vendor_id', $this->vendor->id)->first();
    }

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
            $q->whereIn('title', $status); // Check if the latest status title is in the given array
        });
    }

    // public function getClientAttribute()
    // {
    //     // dd(Client::findOrFail($this->vendors()->first()->pivot->client_id));
    //     // dd($this->clients);
    //     dd($this->vendors);
    //     // return Client::withoutGlobalScopes()->findOrFail($this->clients()->first()->id);
    //     $vendor = $this->vendors()->first();
    //     // dd($vendor);
    //     if($this->belongs_to_vendor_id == $vendor->id){
    //         return Client::findOrFail($vendor->pivot->client_id);
    //     }else{
    //         return Client::findOrFail($vendor->pivot->client_id);
    //     }
    // }

    /**
     * Format project_name with title case (First Letter Of Each Word)
     */
    protected function projectName(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value,
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
     * Get the name attribute for the project
     */
    protected function name(): Attribute
    {
        return Attribute::make(
            get: function ($value, array $attributes) {
                // Check if project_name exists, provide a default if not
                if (!isset($attributes['project_name'])) {
                    return 'NO PROJECT';
                }
                
                // Special project names that don't need address
                if ($attributes['project_name'] == 'EXPENSE SPLIT' || $attributes['project_name'] == 'NO PROJECT') {
                    return $attributes['project_name'];
                }
                
                // Distribution projects just use project name
                if (isset($attributes['distribution']) && $attributes['distribution'] == true) {
                    return $attributes['project_name'];
                }
                
                // Standard projects combine address and name, but check if address exists
                if (isset($attributes['address']) && !empty($attributes['address'])) {
                    return $attributes['address'].' | '.$attributes['project_name'];
                }
                
                // Fallback to just project name if no address
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
                $finances['estimate'] = (float) $this->bids()->where('type', 1)->sum('amount');
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