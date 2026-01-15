<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CallLog extends Model
{
    protected $fillable = [
        'call_id',
        'direction',
        'status',
        'from_number',
        'to_number',
        'caller_name',
        'user_id',
        'project_id',
        'client_id',
        'contact_user_id',
        'duration_seconds',
        'disconnect_cause',
        'notes',
        'recording_url',
        'has_voicemail',
        'metadata',
        'answered_at',
        'ended_at',
    ];

    protected $casts = [
        'has_voicemail' => 'boolean',
        'metadata' => 'array',
        'answered_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    // Direction constants
    public const DIRECTION_INBOUND = 'inbound';
    public const DIRECTION_OUTBOUND = 'outbound';
    public const DIRECTION_CLICK_TO_CALL = 'click_to_call';

    // Status constants
    public const STATUS_INITIATED = 'initiated';
    public const STATUS_RINGING = 'ringing';
    public const STATUS_ANSWERED = 'answered';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_NO_ANSWER = 'no_answer';

    /**
     * The user (staff) who made or received this call.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The project associated with this call.
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * The client associated with this call.
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * The contact user associated with this call.
     */
    public function contactUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'contact_user_id');
    }

    /**
     * Scope for inbound calls.
     */
    public function scopeInbound($query)
    {
        return $query->where('direction', self::DIRECTION_INBOUND);
    }

    /**
     * Scope for outbound calls.
     */
    public function scopeOutbound($query)
    {
        return $query->whereIn('direction', [self::DIRECTION_OUTBOUND, self::DIRECTION_CLICK_TO_CALL]);
    }

    /**
     * Scope for completed calls.
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    /**
     * Scope for calls with voicemails.
     */
    public function scopeWithVoicemail($query)
    {
        return $query->where('has_voicemail', true);
    }

    /**
     * Get formatted duration.
     */
    public function getFormattedDurationAttribute(): string
    {
        if (! $this->duration_seconds) {
            return '-';
        }

        $minutes = floor($this->duration_seconds / 60);
        $seconds = $this->duration_seconds % 60;

        return sprintf('%d:%02d', $minutes, $seconds);
    }

    /**
     * Check if this is an inbound call.
     */
    public function isInbound(): bool
    {
        return $this->direction === self::DIRECTION_INBOUND;
    }

    /**
     * Check if this is an outbound/click-to-call.
     */
    public function isOutbound(): bool
    {
        return in_array($this->direction, [self::DIRECTION_OUTBOUND, self::DIRECTION_CLICK_TO_CALL]);
    }
}
