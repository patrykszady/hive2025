<?php

namespace App\Models;

use App\Models\Scopes\EstimateScope;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Estimate extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['project_id', 'options', 'belongs_to_vendor_id', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return [
            'options' => 'array',
        ];
    }

    protected static function booted()
    {
        static::addGlobalScope(new EstimateScope);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function line_items(): BelongsToMany
    {
        return $this->belongsToMany(LineItem::class)->withPivot('id', 'name', 'category', 'sub_category', 'unit_type', 'cost', 'desc', 'notes', 'quantity', 'total', 'section_id')->withTimestamps();
    }

    public function estimate_line_items(): HasMany
    {
        return $this->hasMany(EstimateLineItem::class);
    }

    public function estimate_sections(): HasMany
    {
        return $this->hasMany(EstimateSection::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'belongs_to_vendor_id');
    }

    // public function getSectionsAttribute($value)
    // {
    //     // dd($value);
    //     //where not removed
    //     $sections = collect(json_decode($value, true));
    //     return $sections->where('deleted', '!=', true);
    //     // dd($sections->where('deleted', '!=', true));
    //     // foreach($sections as $section){
    //     //     if(isset($section['deleted'])){
    //     //         continue;
    //     //     }else{
    //     //         // $sections
    //     //     }
    //     // }
    //     // return json_decode($value, true);
    // }

    public function getClientAttribute()
    {
        return $this->project->clients()->wherePivot('vendor_id', $this->belongs_to_vendor_id)->first();
    }
    // public function getClient($vendor)
    // {
    //     dd($vendor);
    // }

    public function getStartDateAttribute()
    {
        if (isset($this->options['start_date'])) {
            return Carbon::parse($this->options['start_date']);
        } else {
            return null;
        }
    }

    public function getEndDateAttribute()
    {
        if (isset($this->options['end_date'])) {
            return Carbon::parse($this->options['end_date']);
        } else {
            return null;
        }
    }

    public function getReimbursmentsAttribute()
    {
        if (isset($this->options['include_reimbursement']) && $this->options['include_reimbursement'] == true) {
            return $this->project->finances['reimbursments'];
        } else {
            return null;
        }
    }

    public function getPaymentsAttribute()
    {
        if (isset($this->options['payments'])) {
            return $this->options['payments'];
        } else {
            return null;
        }
    }

    public function getNumberAttribute()
    {
        $number =
            $this->belongs_to_vendor_id.'-'.
            $this->client->id.'-'.
            $this->project->id.'-'.
            $this->id;

        return $number;
    }
}
