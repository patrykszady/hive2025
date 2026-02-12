<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmsMessage extends Model
{
    public const DIRECTION_INBOUND = 'inbound';

    public const DIRECTION_OUTBOUND = 'outbound';

    protected $fillable = [
        'thread_id',
        'provider',
        'provider_message_id',
        'direction',
        'from_number',
        'to_numbers',
        'text',
        'media_urls',
        'raw_payload',
        'status',
        'sent_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'to_numbers' => 'array',
            'media_urls' => 'array',
            'raw_payload' => 'array',
        ];
    }

    /**
     * The user who sent this outbound message.
     */
    public function sentByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by_user_id');
    }

    /**
     * Get display text with signature stripped.
     */
    public function getDisplayTextAttribute(): ?string
    {
        if (! $this->text) {
            return null;
        }

        // Strip trailing signature (e.g. "\n-PS") or standalone signature (e.g. "-PS")
        $cleaned = preg_replace('/(?:^|\n)-(?:PS|GS|GSC)$/s', '', $this->text);

        return $cleaned !== '' ? $cleaned : null;
    }

    /**
     * Determine if this message has media attachments.
     */
    public function hasMedia(): bool
    {
        return ! empty($this->media_urls);
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(SmsGroupThread::class, 'thread_id');
    }

    /**
     * Determine if this message was sent by us.
     */
    public function isOutbound(): bool
    {
        return $this->direction === self::DIRECTION_OUTBOUND;
    }

    /**
     * Determine if this message was received from an external number.
     */
    public function isInbound(): bool
    {
        return $this->direction === self::DIRECTION_INBOUND;
    }

    /**
     * Get a display label for the sender (last 4 digits of phone).
     */
    public function getSenderLabelAttribute(): string
    {
        if ($this->isOutbound()) {
            return 'You';
        }

        return substr($this->from_number, -4);
    }
}
