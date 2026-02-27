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

    /**
     * Normalise media URLs so legacy absolute URLs become relative paths.
     * This ensures images display correctly regardless of the current domain.
     *
     * @return array<int, string>|null
     */
    public function getMediaUrlsAttribute(mixed $value): ?array
    {
        $urls = is_string($value) ? json_decode($value, true) : $value;

        if (empty($urls)) {
            return $urls;
        }

        return array_map(function (string $url): string {
            // Convert absolute app URLs to relative paths (e.g. https://hive.contractors/storage/... → /storage/...)
            if (str_starts_with($url, 'http')) {
                $parsed = parse_url($url, PHP_URL_PATH);

                if ($parsed && str_starts_with($parsed, '/storage/')) {
                    return $parsed;
                }
            }

            return $url;
        }, $urls);
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
