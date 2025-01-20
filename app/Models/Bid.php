<?php

namespace App\Models;

use App\Scopes\BidScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Bid extends Model
{
    use HasFactory;

    protected $fillable = ['project_id', 'vendor_id', 'amount', 'type', 'created_at', 'updated_at'];

    protected static function booted()
    {
        static::addGlobalScope(new BidScope);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function estimate_sections(): HasMany
    {
        return $this->hasMany(EstimateSection::class);
    }

    public function getNameAttribute()
    {
        if ($this->type == 1) {
            $name = 'Original Bid';
        } else {
            $name = 'Change Order '.$this->type - 1;
        }

        return $name;
    }

    public function scopeVendorBids($query, $vendor_id)
    {
        return $query->withoutGlobalScopes()->where('vendor_id', $vendor_id);
    }
}
