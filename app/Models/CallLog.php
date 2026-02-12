<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CallLog extends Model
{
    // Status constants
    public const STATUS_INITIATED = 'initiated';
    public const STATUS_ANSWERED = 'answered';
    public const STATUS_TRANSFERRED = 'transferred';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_MISSED = 'missed';
    public const STATUS_VOICEMAIL = 'voicemail';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'call_id',
        'call_control_id',
        'call_session_id',
        'call_leg_id',
        'connection_id',
        'direction',
        'from_number',
        'to_number',
        'caller_name',
        'status',
        'forwarded_to',
        'duration_seconds',
        'disconnect_cause',
        'hangup_cause',
        'notes',
        'recording_url',
        'has_voicemail',
        'project_id',
        'user_id',
        'client_id',
        'contact_user_id',
        'metadata',
        'answered_at',
        'ended_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'has_voicemail' => 'boolean',
            'answered_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Find a call log by its Telnyx call_control_id.
     */
    public static function findByCallControlId(string $callControlId): ?self
    {
        return static::where('call_control_id', $callControlId)->first();
    }

    /**
     * Find a call log by its session ID (groups both legs of a transfer).
     */
    public static function findBySessionId(string $sessionId): ?self
    {
        return static::where('call_session_id', $sessionId)
            ->where('direction', 'incoming')
            ->first();
    }

    /**
     * Look up calling user by phone number.
     */
    public function lookUpCaller(): ?User
    {
        $digits = preg_replace('/\D/', '', $this->from_number);

        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            $digits = substr($digits, 1);
        }

        return User::where('cell_phone', 'LIKE', "%{$digits}%")->first();
    }
}
