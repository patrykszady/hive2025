<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AutoReceiptEmailBatch extends Model
{
    use HasFactory;

    protected $fillable = [
        'message_id',
        'company_email_id',
        'belongs_to_vendor_id',
        'from_email',
        'subject',
        'email_received_at',
        'attachment_count',
        'processed_receipt_count',
    ];

    protected $casts = [
        'email_received_at' => 'datetime',
    ];
}
