<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VendorTransaction extends Model
{
    use HasFactory;

    protected $fillable = ['vendor_id', 'deposit_check', 'amount_sign', 'plaid_inst_id', 'desc', 'options'];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class)->withoutGlobalScopes();
    }

    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class, 'plaid_inst_id', 'plaid_ins_id')->withoutGlobalScopes();
    }

    public function setDescAttribute($value)
    {
        $this->attributes['desc'] = trim(addcslashes($value, '/'));
    }
}
