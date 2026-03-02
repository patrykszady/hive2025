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
        'manual_opt_in_reason',
        'manual_opt_in_by',
    ];

    protected function casts(): array
    {
        return [
            'opted_in_at' => 'datetime',
        ];
    }

    public function manualOptInUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manual_opt_in_by');
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(SmsGroupThread::class, 'thread_id');
    }
}
