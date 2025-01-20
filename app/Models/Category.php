<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    use HasFactory;

    protected $fillable = ['primary', 'friendly_primary', 'detailed', 'friendly_detailed', 'icon_url', 'created_at', 'updated_at'];

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function vendors(): HasMany
    {
        return $this->hasMany(Vendor::class);
    }
}
