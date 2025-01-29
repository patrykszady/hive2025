<?php

namespace App\Models;

use App\Scopes\ProjectScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

class Project extends Model
{
    use HasFactory;

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

    public function last_status(): HasOne
    {
        return $this->hasOne(ProjectStatus::class)->orderBy('start_date', 'DESC')->latest();
    }

    public function scopeStatus($query, $status)
    {
        // dd($status);
        return $query->with('last_status')->get()->whereIn('last_status.title', $status);
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

    // public function getStatusAttribute()
    // {
    //     return $this->statuses()->orderBy('created_at', 'DESC')->first();
    // }

    // public function scopeActive($query)
    // {
    //     // dd($query->with('statuses')->get());
    //     // dd($query->whereHas('statuses')->get());
    //     // $posts = Post::whereHas('comments', function (Builder $query) {
    //     //     $query->where('content', 'like', 'code%');
    //     // })->get();

    //     $query->whereHas('statuses', function($q){
    //         dd($q->get()->groupBy('project_id'));
    //         $q->where('start_date', '>=', '2015-01-01');
    //     })->get();
    // }

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

    public function getFinancesAttribute()
    {
        $expenses_sum = $this->expenses()->where('reimbursment', 'Client')->sum('amount');
        $splits_sum = $this->expenseSplits()->where('reimbursment', 'Client')->sum('amount');

        $finances['estimate'] = (float) $this->bids()->where('type', 1)->sum('amount');
        $finances['change_orders'] = $this->bids()->where('type', '!=', 1)->sum('amount');
        $finances['total_bid'] = $finances['estimate'] + $finances['change_orders'];
        $finances['reimbursments'] = $splits_sum + $expenses_sum;
        $finances['total_project'] = round($finances['reimbursments'] + $finances['estimate'] + $finances['change_orders'], 2);
        $finances['expenses'] = $this->expenses->sum('amount') + $this->expenseSplits->sum('amount');
        $finances['timesheets'] = $this->timesheets->sum('amount');
        $finances['total_cost'] = $finances['timesheets'] + $finances['expenses'];
        $finances['payments'] = round($this->payments->sum('amount'), 2);
        //amount_format(..., 2)
        $finances['profit'] = $finances['payments'] - $finances['total_cost'];
        $finances['balance'] = $finances['total_project'] - $finances['payments'];

        return $finances;
    }

    public function getAddressMapURI()
    {
        $url = 'https://maps.apple.com/?q='.$this->address.', '.$this->city.', '.$this->state.', '.$this->zip_code;

        return $url;
    }

    public function getNameAttribute()
    {
        if ($this->project_name == 'EXPENSE SPLIT' || $this->project_name == 'NO PROJECT') {
            $name = $this->project_name;
        } elseif ($this->distribution == true) {
            $name = $this->project_name;
        } else {
            $name = $this->address.' | '.$this->project_name;
        }

        return $name;
    }
}
