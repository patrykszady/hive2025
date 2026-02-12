<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmsThreadParticipant extends Model
{
    protected $fillable = [
        'thread_id',
        'phone_number',
        'opted_in_at',
    ];

    protected function casts(): array
    {
        return [
            'opted_in_at' => 'datetime',
        ];
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(SmsGroupThread::class, 'thread_id');
    }
}
