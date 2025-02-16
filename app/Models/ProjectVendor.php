<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class ProjectVendor extends Pivot
{
    // protected $table = 'project_vendor';

    // public function project()
    // {
    //     return $this->belongsTo(Project::class);
    // }

    // public function client()
    // {
    //     return $this->belongsTo(Client::class, 'client_id');
    // }

    // public function vendor()
    // {
    //     return $this->belongsTo(Vendor::class, 'vendor_id');
    // }
}
