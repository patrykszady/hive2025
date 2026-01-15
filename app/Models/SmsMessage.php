<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SmsMessage extends Model
{
    protected $fillable = [
        'provider',
        'provider_message_id',
        'direction',
        'from_number',
        'to_numbers',
        'text',
        'raw_payload',
    ];

    protected function casts(): array
    {
        return [
            'to_numbers' => 'array',
            'raw_payload' => 'array',
        ];
    }
}
