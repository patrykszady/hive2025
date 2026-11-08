<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of the iPhone app's on-device calling log, uploaded once the app
 * can next reach the server — calls placed while suspended, backgrounded, or
 * driven from Recents/Siri/the locked screen run where the Metro log channel
 * is dead, so this is the only record of what happened until then. See
 * App\Services\Calls\AppCallingDiagnostics, which writes both this and the
 * 'app-calling' log channel.
 */
class AppCallingEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'call_log_id',
        'call_uuid',
        'level',
        'message',
        'context',
        'occurred_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function callLog(): BelongsTo
    {
        return $this->belongsTo(CallLog::class);
    }
}
