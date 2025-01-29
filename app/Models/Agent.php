<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Agent extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'business_name', 'address', 'phone', 'email'];

    public function vendor_docs(): HasMany
    {
        return $this->hasMany(VendorDoc::class);
    }
}
