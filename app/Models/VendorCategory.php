<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class VendorCategory extends Model
{
    use HasFactory;

    public function vendors(): BelongsToMany
    {
        return $this->belongsToMany(Vendor::class, 'category_vendor', 'vendor_category_id', 'vendor_id')->withTimestamps();
    }
}
