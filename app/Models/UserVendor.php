<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class UserVendor extends Pivot
{
    protected $table = 'user_vendor';

    protected $guarded = [];
    
    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'hourly_rate' => 'decimal:2',
        'options' => 'array',
    ];
}