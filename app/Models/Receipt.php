<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Receipt extends Model
{
    use HasFactory;

    protected $guarded = [];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function getOptionsAttribute($value)
    {
        return json_decode($value, true);
    }

    // protected function purchaseOrder(): Attribute
    // {
    //     return Attribute::make(
    //         // get: fn (string $value) => ucfirst($value),
    //         set: fn (string $value) => strtolower($value),
    //     );
    // }
}
