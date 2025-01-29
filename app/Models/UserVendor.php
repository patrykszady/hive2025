<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class UserVendor extends Pivot
{
    protected $casts = [
        'start_date' => 'date:Y-m-d',
        'end_date' => 'date:Y-m-d',
    ];

    //  public function via_vendor()
    // {
    //     return $this->belongsTo(Vendor::class, 'via_vendor_id')->withoutGlobalScopes();
    // }

    // public function via_vendor()
    // {
    //     return $this->belongsTo(Vendor::class, 'via_vendor_id')->withoutGlobalScopes();
    // }
}
