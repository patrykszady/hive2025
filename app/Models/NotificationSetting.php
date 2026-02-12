<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationSetting extends Model
{
    protected $fillable = [
        'user_id',
        'realtime_email',
        'realtime_sms',
        'sms_inbound_browser',
        'realtime_start',
        'realtime_end',
        'morning_email',
        'morning_sms',
        'evening_email',
        'evening_sms',
    ];

    protected function casts(): array
    {
        return [
            'realtime_email' => 'boolean',
            'realtime_sms' => 'boolean',
            'sms_inbound_browser' => 'boolean',
            'morning_email' => 'boolean',
            'morning_sms' => 'boolean',
            'evening_email' => 'boolean',
            'evening_sms' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
