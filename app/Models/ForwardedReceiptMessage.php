<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A receipt email the forwarder copied from a company mailbox into Hive
 * Receipts. The original stays in that mailbox; this row keeps it from being
 * forwarded again on the next run.
 */
class ForwardedReceiptMessage extends Model
{
    /** @use HasFactory<\Database\Factories\ForwardedReceiptMessageFactory> */
    use HasFactory;

    protected $fillable = ['company_email_id', 'message_id', 'forwarded_message_id'];

    public function companyEmail(): BelongsTo
    {
        return $this->belongsTo(CompanyEmail::class);
    }
}
