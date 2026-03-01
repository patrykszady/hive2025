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
     * Known iMessage tapback reaction prefixes and their emoji equivalents.
     */
    public const TAPBACK_PATTERNS = [
        'Liked' => '👍',
        'Loved' => '❤️',
        'Disliked' => '👎',
        'Laughed at' => '😂',
        'Emphasized' => '‼️',
        'Questioned' => '❓',
        // "Removed" variants (un-react)
        'Removed a like from' => null,
        'Removed a heart from' => null,
        'Removed a dislike from' => null,
        'Removed a laugh from' => null,
        'Removed an emphasis from' => null,
        'Removed a question from' => null,
    ];

    /**
     * Determine if this message is an iMessage tapback reaction.
     *
     * Tapbacks arrive as: Liked "original message text"
     * The quotes may be standard " or Unicode \u{201c} \u{201d}.
     */
    public function isTapback(): bool
    {
        return $this->parseTapback() !== null;
    }

    /**
     * Parse a tapback reaction message.
     *
     * @return array{reaction: string, emoji: string|null, quoted: string}|null
     */
    public function parseTapback(): ?array
    {
        if (! $this->text) {
            return null;
        }

        $text = trim($this->text);

        // Quote patterns: standard ASCII ", Unicode curly "" (U+201C/U+201D),
        // and their common UTF-8 double-encoding mojibake variants (â€œ / â€ + U+009D).
        $openQuotes = '[\x{201c}"\x{00e2}]';
        $closeQuotes = '[\x{201d}"\x{009d}]';

        foreach (self::TAPBACK_PATTERNS as $prefix => $emoji) {
            $escapedPrefix = preg_quote($prefix, '/');

            // Try matching with flexible quote detection:
            // 1) Standard/Unicode quotes
            // 2) Mojibake: â€œ...â€ + optional trailing char
            if (preg_match('/^' . $escapedPrefix . '\s+[\x{201c}"](.*?)[\x{201d}"]\s*$/su', $text, $matches)) {
                return [
                    'reaction' => $prefix,
                    'emoji' => $emoji,
                    'quoted' => $matches[1],
                ];
            }

            // Mojibake variant: â€œ as open, â€ followed by U+009D (or end of string) as close
            if (preg_match('/^' . $escapedPrefix . '\s+\x{00e2}\x{20ac}\x{0153}(.*?)(?:\x{00e2}\x{20ac}\x{009d}|\x{00e2}\x{20ac}$)\s*$/su', $text, $matches)) {
                return [
                    'reaction' => $prefix,
                    'emoji' => $emoji,
                    'quoted' => $matches[1],
                ];
            }
        }

        return null;
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
