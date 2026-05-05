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
        'scheduled_at',
    ];

    protected function casts(): array
    {
        return [
            'to_numbers' => 'array',
            'media_urls' => 'array',
            'raw_payload' => 'array',
            'scheduled_at' => 'datetime',
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
     * Detect if a media URL is a video by file extension.
     */
    public static function isVideoUrl(string $url): bool
    {
        $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?: $url, PATHINFO_EXTENSION));

        return in_array($ext, ['mp4', 'mov', 'm4v', 'webm', 'ogv', '3gp', '3gpp', 'mkv', 'avi', 'qt'], true);
    }

    /**
     * Detect if a media URL is an audio file by extension.
     */
    public static function isAudioUrl(string $url): bool
    {
        $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?: $url, PATHINFO_EXTENSION));

        return in_array($ext, ['mp3', 'm4a', 'aac', 'wav', 'ogg', 'oga', 'amr', 'opus'], true);
    }

    /**
     * Detect if a media URL is an image by extension (defaults to true for unknown).
     */
    public static function isImageUrl(string $url): bool
    {
        $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?: $url, PATHINFO_EXTENSION));

        if ($ext === '') {
            return true;
        }

        return in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'heic', 'heif', 'svg'], true);
    }

    /**
     * MIME type guess from a URL extension. Returns null if unknown.
     */
    public static function mimeForUrl(string $url): ?string
    {
        $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?: $url, PATHINFO_EXTENSION));

        return match ($ext) {
            'mp4', 'm4v' => 'video/mp4',
            'mov', 'qt' => 'video/quicktime',
            'webm' => 'video/webm',
            'ogv' => 'video/ogg',
            '3gp', '3gpp' => 'video/3gpp',
            'mkv' => 'video/x-matroska',
            'mp3' => 'audio/mpeg',
            'm4a' => 'audio/mp4',
            'aac' => 'audio/aac',
            'wav' => 'audio/wav',
            'ogg', 'oga' => 'audio/ogg',
            'amr' => 'audio/amr',
            default => null,
        };
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
        // Emoji-prefix variants (e.g. "👍 to" from some carriers/RCS)
        "\u{1F44D} to" => '👍',
        "\u{2764}\u{FE0F} to" => '❤️',
        "\u{2764} to" => '❤️',
        "\u{1F44E} to" => '👎',
        "\u{1F602} to" => '😂',
        "\u{203C}\u{FE0F} to" => '‼️',
        "\u{2753} to" => '❓',
        // "Removed" variants (un-react)
        'Removed a like from' => null,
        'Removed a heart from' => null,
        'Removed a dislike from' => null,
        'Removed a laugh from' => null,
        'Removed an emphasis from' => null,
        'Removed a question from' => null,
    ];

    /**
     * Multi-language reaction keywords → emoji.
     * Used by the generic tapback detector to identify the reaction type
     * regardless of language.
     *
     * @var array<string, list<string>>
     */
    public const REACTION_KEYWORDS = [
        '👍' => [
            'liked', 'like', "j'aime", 'aime', 'aimé',
            'gustó', 'gusta',                        // Spanish
            'gefällt', 'daumen hoch',                 // German
            'curtiu', 'gostou',                       // Portuguese
            'piace',                                  // Italian
            'leuk',                                   // Dutch
            'понравил', 'нравит',                     // Russian
            'いいね',                                  // Japanese
            '赞',                                      // Chinese
            '좋아',                                    // Korean
            'polubi', 'lubię',                        // Polish
            'أعجب',                                    // Arabic
            'suka',                                   // Indonesian/Malay
            'thích',                                  // Vietnamese
            'gillade',                                // Swedish
            'liker',                                  // Norwegian
            'tykkä',                                  // Finnish
            'beğen',                                  // Turkish
        ],
        '❤️' => [
            'loved', 'love', 'cœur', 'coeur', 'adoré',
            'encantó',                                // Spanish
            'herz',                                   // German
            'amou', 'adorou',                         // Portuguese
            'adorat', 'cuore',                        // Italian
            'сердц',                                  // Russian
            'hart',                                   // Dutch
            '爱',                                      // Chinese
            '사랑',                                    // Korean
            'älskat',                                 // Swedish
            'elsket',                                 // Norwegian
        ],
        '👎' => [
            'disliked', 'dislike',
            'pouce vers le bas',                      // French
            'no le gustó', 'no me gust',              // Spanish
            'daumen runter',                          // German
            'não gost',                               // Portuguese
            'не понравил',                            // Russian
            '싫어',                                    // Korean
            'nie lubi',                               // Polish
            'ogillade',                               // Swedish
            '不喜欢',                                  // Chinese
        ],
        '😂' => [
            'laughed', 'laugh', 'ha ha',
            'rire',                                   // French
            'reí', 'rió',                             // Spanish
            'gelacht',                                // German
            'risata',                                 // Italian
            'смеял',                                  // Russian
            '笑',                                      // Chinese/Japanese
            '웃',                                      // Korean
            'skrattat',                               // Swedish
            'śmiech',                                 // Polish
        ],
        '‼️' => [
            'emphasized', 'emphasis',
            'exclamation',                            // French
            'enfatiz',                                // Spanish/Italian
            'betont',                                 // German
            'выделил',                                // Russian
            '感叹',                                    // Chinese
            '강조',                                    // Korean
        ],
        '❓' => [
            'questioned', 'question',
            'pregunt',                                // Spanish
            'gefragt',                                // German
            'domanda',                                // Italian
            'вопрос',                                 // Russian
            '疑问',                                    // Chinese
            '질문',                                    // Korean
        ],
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
     * @return array{reaction: string, emoji: string|null, quoted: string, strict?: bool}|null
     */
    public function parseTapback(): ?array
    {
        if (! $this->text) {
            return null;
        }

        $text = trim($this->text);

        // Repair double-encoded UTF-8 mojibake.
        // Two common variants:
        // 1) Latin-1 path: high bytes (C0-FF) become C3 [80-BF] followed by C2 continuation
        // 2) CP1252 path: â€ sequence (e.g. â€œ for ", ðŸ for emoji start)
        if (preg_match('/\xC3[\x80-\xBF][\xC2-\xC5\xE2][\x80-\xBF]/', $text)
            || preg_match('/\xC3[\x80-\xBF]\xC2[\x80-\xBF]/', $text)) {
            $decoded = @mb_convert_encoding($text, 'Windows-1252', 'UTF-8');
            if ($decoded !== false && $decoded !== '' && mb_check_encoding($decoded, 'UTF-8')) {
                $text = $decoded;
            }
        }

        // Quote patterns: standard ASCII ", Unicode curly \u{201c}\u{201d} (U+201C/U+201D),
        // and their common UTF-8 double-encoding mojibake variants.
        foreach (self::TAPBACK_PATTERNS as $prefix => $emoji) {
            $escapedPrefix = preg_quote($prefix, '/');

            // Try matching with flexible quote detection:
            // 1) Standard/Unicode quotes
            if (preg_match('/^' . $escapedPrefix . '\s+[\x{201c}"](.*?)[\x{201d}"]\s*$/su', $text, $matches)) {
                return [
                    'reaction' => $prefix,
                    'emoji' => $emoji,
                    'quoted' => $matches[1],
                ];
            }

            // 2) Mojibake variant: â€œ as open, â€ followed by U+009D (or end of string) as close
            if (preg_match('/^' . $escapedPrefix . '\s+\x{00e2}\x{20ac}\x{0153}(.*?)(?:\x{00e2}\x{20ac}\x{009d}|\x{00e2}\x{20ac}$)\s*$/su', $text, $matches)) {
                return [
                    'reaction' => $prefix,
                    'emoji' => $emoji,
                    'quoted' => $matches[1],
                ];
            }
        }

        // Generic multi-language fallback: extract quoted text from Unicode quotation
        // marks and match reaction keywords across many languages.
        return self::parseGenericTapback($text);
    }

    /**
     * Generic multi-language tapback detection.
     *
     * Extracts quoted text from various Unicode quotation mark styles (guillemets,
     * curly quotes, CJK brackets, etc.) and identifies the reaction emoji from
     * multi-language keywords. Returns 'strict' => true so that callers can choose
     * to only hide these messages when the quoted text matches a real thread message.
     */
    private static function parseGenericTapback(string $text): ?array
    {
        // Unicode quotation mark styles (ASCII " handled by English patterns above)
        $quoteStyles = [
            ["\u{201c}", "\u{201d}"],  // \u{201c}\u{201d} English/general curly
            ["\u{00ab}", "\u{00bb}"],  // \u{00ab}\u{00bb} French/Russian guillemets
            ["\u{201e}", "\u{201c}"],  // \u{201e}\u{201c} German/Polish low-high
            ["\u{300c}", "\u{300d}"],  // \u{300c}\u{300d} CJK corner brackets
        ];

        // Extract all quoted segments
        $allQuoted = [];
        foreach ($quoteStyles as [$open, $close]) {
            $pattern = '/' . preg_quote($open, '/') . '[\s\x{a0}]?(.+?)[\s\x{a0}]?' . preg_quote($close, '/') . '/su';
            if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $m) {
                    $trimmed = trim($m[1]);
                    if (mb_strlen($trimmed) >= 2) {
                        $allQuoted[] = ['text' => $trimmed, 'full' => $m[0]];
                    }
                }
            }
        }

        if (empty($allQuoted)) {
            return null;
        }

        // Sort longest first — the longest quote is most likely the original message text
        usort($allQuoted, fn ($a, $b) => mb_strlen($b['text']) - mb_strlen($a['text']));

        // Identify which quoted segment is the reaction name vs. the original message.
        // Languages like French/German/Italian put the reaction name in its own quoted segment.
        // Reaction names are always short (< 25 chars), so skip long segments.
        $reactionSegment = null;
        $messageSegment = null;

        foreach ($allQuoted as $q) {
            if (mb_strlen($q['text']) <= 25 && ! $reactionSegment) {
                $emoji = self::detectReactionEmoji($q['text']);
                if ($emoji) {
                    $reactionSegment = ['text' => $q['text'], 'emoji' => $emoji, 'full' => $q['full']];
                    continue;
                }
            }
            if (! $messageSegment) {
                $messageSegment = $q;
            }
        }

        // Case 1: Reaction name is in a quoted segment (French/German/Italian style)
        // e.g. A ajouté un « J'aime » à « original text ».
        if ($reactionSegment && $messageSegment) {
            $label = $text;
            foreach ($allQuoted as $q) {
                $label = str_replace($q['full'], '', $label);
            }
            if (mb_strlen(trim($label)) > 60) {
                return null;
            }

            return [
                'reaction' => $reactionSegment['text'],
                'emoji' => $reactionSegment['emoji'],
                'quoted' => $messageSegment['text'],
                'strict' => true,
            ];
        }

        // Case 2: Reaction keyword is in the non-quoted text (Spanish, Russian, etc.)
        // e.g. Le gustó "original text"
        if (count($allQuoted) >= 1) {
            $quoted = $messageSegment['text'] ?? $allQuoted[0]['text'];
            $label = $text;
            foreach ($allQuoted as $q) {
                $label = str_replace($q['full'], '', $label);
            }
            $label = trim(preg_replace('/[\s\x{a0}]+/u', ' ', $label));
            $label = trim($label, " \t\n\r\0\x0B.");

            if (mb_strlen($label) < 2 || mb_strlen($label) > 40) {
                return null;
            }

            $emoji = self::detectReactionEmoji($label);
            if ($emoji) {
                return [
                    'reaction' => $label,
                    'emoji' => $emoji,
                    'quoted' => $quoted,
                    'strict' => true,
                ];
            }
        }

        return null;
    }

    /**
     * Match a text fragment against known multi-language reaction keywords.
     */
    private static function detectReactionEmoji(string $text): ?string
    {
        // Normalise smart quotes/apostrophes to ASCII for keyword matching
        $normalized = str_replace(
            ["\u{2018}", "\u{2019}", "\u{201a}", "\u{2032}"],
            "'",
            trim($text)
        );
        $lower = mb_strtolower($normalized);

        // Bare punctuation marks used across many languages for emphasis/question
        $stripped = preg_replace('/[\s\x{a0}]+/u', '', $lower);
        if ($stripped === '!!' || $stripped === '‼️' || $stripped === '‼') {
            return '‼️';
        }
        if ($stripped === '?' || $stripped === '❓') {
            return '❓';
        }

        // Direct emoji match (e.g. "👍 to" from some carriers)
        $emojiMap = [
            "\u{1F44D}" => '👍',
            "\u{2764}\u{FE0F}" => '❤️',
            "\u{2764}" => '❤️',
            "\u{1F44E}" => '👎',
            "\u{1F602}" => '😂',
            "\u{203C}\u{FE0F}" => '‼️',
            "\u{203C}" => '‼️',
            "\u{2753}" => '❓',
        ];
        foreach ($emojiMap as $char => $mappedEmoji) {
            if (str_contains($text, $char)) {
                return $mappedEmoji;
            }
        }

        foreach (self::REACTION_KEYWORDS as $emoji => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($lower, $keyword)) {
                    return $emoji;
                }
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
