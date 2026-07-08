<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Scout\Searchable;

class SmsMessage extends Model
{
    use Searchable;

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

    protected static function booted(): void
    {
        // Keep the SmsGroupThread Meili index in sync as conversations evolve.
        $touch = function (self $message): void {
            if ($message->thread_id) {
                $thread = SmsGroupThread::find($message->thread_id);
                if ($thread) {
                    $thread->searchable();
                }
            }
        };

        static::saved($touch);
        static::deleted($touch);
    }

    /**
     * Dedicated Meilisearch index for full message-body search (covers the
     * entire thread history, not just the recent messages embedded in the
     * SmsGroupThread index).
     */
    public function searchableAs(): string
    {
        return app()->environment('local') ? 'sms_messages_index_dev' : 'sms_messages_index';
    }

    /**
     * Only index messages that carry searchable body text.
     */
    public function shouldBeSearchable(): bool
    {
        return filled(trim((string) $this->text));
    }

    /**
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => (int) $this->id,
            'thread_id' => $this->thread_id ? (int) $this->thread_id : null,
            'text' => mb_substr((string) ($this->display_text ?? $this->text ?? ''), 0, 2000),
            'from_number' => (string) ($this->from_number ?? ''),
            'created_at_unix' => optional($this->created_at)->timestamp ?? 0,
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

        $text = self::repairMojibakeText((string) $this->text);

        // Strip trailing signature (e.g. "\n-PS") or standalone signature (e.g. "-PS")
        $cleaned = preg_replace('/(?:^|\n)-(?:PS|GS|GSC)$/s', '', $text);

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
        // Newer iOS / RCS reactions (any emoji) — prefix style
        'Reacted' => null,        // "Reacted 🔥 to ..." — emoji parsed via parseReactedTo()
        'Emphasised' => '‼️', // British English variant
        // Emoji-prefix variants (e.g. "👍 to" from some carriers/RCS)
        "\u{1F44D} to" => '👍',
        "\u{2764}\u{FE0F} to" => '❤️',
        "\u{2764} to" => '❤️',
        "\u{1F44E} to" => '👎',
        "\u{1F602} to" => '😂',
        "\u{203C}\u{FE0F} to" => '‼️',
        "\u{2753} to" => '❓',
        "\u{1F525} to" => '🔥',     // 🔥 fire
        "\u{1F389} to" => '🎉',     // 🎉 party
        "\u{1F44F} to" => '👏',     // 👏 clap
        "\u{1F4AF} to" => '💯',     // 💯 hundred
        "\u{1F64F} to" => '🙏',     // 🙏 pray
        "\u{1F92F} to" => '🤯',     // 🤯 mind blown
        "\u{1F62D} to" => '😭',     // 😭 crying
        "\u{1F62E} to" => '😮',     // 😮 wow
        // "Removed" variants (un-react)
        'Removed a like from' => null,
        'Removed a heart from' => null,
        'Removed a dislike from' => null,
        'Removed a laugh from' => null,
        'Removed an emphasis from' => null,
        'Removed a question from' => null,
        'Removed a reaction from' => null,
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
            'pouce en haut', 'pouce vers le haut',    // French
            'gustó', 'gusta', 'pulgar arriba',        // Spanish
            'gefällt', 'daumen hoch',                 // German
            'mi piace', 'piace',                      // Italian
            'curtiu', 'gostou', 'polegar',            // Portuguese
            'leuk', 'duim omhoog',                    // Dutch
            'понравил', 'нравит', 'палец вверх',      // Russian
            'いいね',                                  // Japanese
            '赞', '点赞',                              // Chinese
            '좋아', '엄지···',                         // Korean
            'polubi', 'lubię', 'kciuk w gór',         // Polish
            'أعجب', 'إبهام لأعلى',                    // Arabic
            'אהבתי', 'אגודל למעלה',                   // Hebrew
            'pasand', 'पसंद',                         // Hindi
            'suka', 'jempol',                         // Indonesian/Malay
            'thích',                                  // Vietnamese
            'gillade',                                // Swedish
            'liker',                                  // Norwegian
            'tykkä',                                  // Finnish
            'beğen',                                  // Turkish
            'kedvel', 'tetszik',                      // Hungarian
            'líbil', 'palec nahoru',                  // Czech
            'páčilo',                                 // Slovak
            'apreciat',                               // Romanian
            'μου αρέσει',                             // Greek
            'gostei',                                 // Portuguese (alt)
            'ถูกใจ',                                 // Thai
        ],
        '❤️' => [
            'loved', 'love', 'cœur', 'coeur', 'adoré',
            'encantó', 'me encant', 'corazón',        // Spanish
            'herz', 'liebe',                          // German
            'amou', 'adorou', 'coração',              // Portuguese
            'adorat', 'cuore', 'amato',               // Italian
            'сердц', 'полюбил',                       // Russian
            'hart', 'gehouden',                       // Dutch
            'serce', 'pokocha',                       // Polish
            '爱', '心',                                // Chinese
            '사랑', '하트',                            // Korean
            'ハート', '愛',                            // Japanese
            'أحب', 'قلب',                             // Arabic
            'אהב', 'לב',                              // Hebrew
            'प्यार', 'dil',                            // Hindi
            'älskat', 'hjärta',                       // Swedish
            'elsket', 'hjerte',                       // Norwegian
            'rakast', 'sydän',                        // Finnish
            'sevdi', 'kalp',                          // Turkish
            'szeret', 'szív',                         // Hungarian
            'miloval', 'srdce',                       // Czech
            'αγάπησ', 'καρδιά',                       // Greek
            'iubit', 'inimă',                         // Romanian
            'หัวใจ', 'รัก',                          // Thai
            'menyukai', 'mencintai',                  // Indonesian
            'yêu thích',                              // Vietnamese
        ],
        '👎' => [
            'disliked', 'dislike',
            'pouce vers le bas', 'pouce en bas',      // French
            'no le gustó', 'no me gust', 'pulgar abajo', // Spanish
            'daumen runter',                          // German
            'não gost', 'polegar para baixo',         // Portuguese
            'не понравил', 'палец вниз',              // Russian
            '싫어',                                    // Korean
            'nie lubi', 'kciuk w dół',                // Polish
            'ogillade', 'tummen ner',                 // Swedish
            '不喜欢', '差评',                          // Chinese
            'nesnáš', 'palec dolů',                   // Czech
            'nevolil',                                // Slovak
            'δεν μου άρεσε',                          // Greek
            'tidak suka',                             // Indonesian
        ],
        '😂' => [
            'laughed', 'laugh', 'ha ha', 'lol',
            'rire', 'mort de rire', 'mdr',            // French
            'reí', 'rió', 'reir',                     // Spanish
            'gelacht', 'lustig',                      // German
            'risata', 'rise',                         // Italian
            'riu', 'engraçado',                       // Portuguese
            'смеял', 'смешно',                        // Russian
            '笑', '呵呵',                              // Chinese/Japanese
            '웃',                                      // Korean
            'skrattat',                               // Swedish
            'śmiech', 'śmieje', 'ha ha ha',           // Polish
            'gül', 'kahkaha',                         // Turkish
            'nevetett',                               // Hungarian
            'smál',                                   // Czech
            'γέλασ',                                  // Greek
            'ضحك',                                    // Arabic
            'צחק',                                    // Hebrew
            'tertawa',                                // Indonesian
            'cười',                                   // Vietnamese
            'หัวเราะ',                               // Thai
        ],
        '‼️' => [
            'emphasized', 'emphasised', 'emphasis',
            'exclamation', 'souligné',                // French
            'enfatiz', 'énfasis',                     // Spanish/Italian
            'betont', 'hervorgehoben',                // German
            'выделил', 'акцент',                      // Russian
            'wykrzyknik', 'podkreśli',                // Polish
            '感叹', '强调',                            // Chinese
            '강조',                                    // Korean
            'vurgu',                                  // Turkish
            'הדגיש',                                  // Hebrew
            'أكد',                                    // Arabic
            'เน้น',                                  // Thai
        ],
        '❓' => [
            'questioned', 'question',
            'pregunt',                                // Spanish
            'gefragt',                                // German
            'domanda', 'chiest',                      // Italian
            'perguntou',                              // Portuguese
            'demandé',                                // French
            'вопрос', 'спросил',                      // Russian
            'znak zapytania', 'zapytał',              // Polish
            '疑问', '问号',                            // Chinese
            '질문',                                    // Korean
            '質問',                                    // Japanese
            'soru', 'sord',                           // Turkish
            'שאל',                                    // Hebrew
            'سؤال',                                   // Arabic
            'ถาม',                                   // Thai
        ],
        '🔥' => [   // 🔥 fire / lit
            'fire', 'lit', 'feu', 'fuego', 'fogo',
            'feuer', 'fuoco', '火', 'файер', 'огонь',
            'ogień', 'płomień',                       // Polish
        ],
        '🎉' => [   // 🎉 party / celebrate
            'celebrated', 'celebrate', 'congrats', 'party',
            'fête', 'fiesta', 'festa', 'feier',
            'gratulacje', 'гратулем', 'ฉลอง',
        ],
        '👏' => [   // 👏 clap / applaud
            'clap', 'applauded', 'applause', 'bravo',
            'aplaud', 'klatsch', 'aplauso', '鼓掌',
            'oklaski', 'מחיאת כפיים',
        ],
        '💯' => [   // 💯 hundred
            '100', 'hundred', 'cent pourcent', 'cien por cien',
            'sto procent',
        ],
        '🙏' => [   // 🙏 pray / thanks
            'pray', 'prayed', 'thanks', 'thank you',
            'merci', 'gracias', 'obrigad', 'danke',
            'dzięki', 'spasibo', 'спасибо', '谢谢', '感谢',
            'شكرا', 'todah', 'תודה',
        ],
        '🤯' => [   // 🤯 mind blown / wow
            'mind blown', 'mindblown',
            'increíble', 'incredibile', 'unglaublich',
            'niesamowite',
        ],
        '😭' => [   // 😭 sad / crying
            'sad', 'crying', 'cried',
            'triste', 'traurig', 'piango', 'chor',
            'smutne', 'płacz', 'grăć', '哭', '울',
        ],
        '😮' => [   // 😮 wow / surprised
            'wow', 'surprised', 'étonn',
            'sorprend', 'überrasch',
            'zaskoczon',
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

        $text = self::repairMojibakeText($text);

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

        // Reacted <emoji> to "..." — newer RCS/iOS arbitrary-emoji format.
        // The emoji sits between the prefix and the "to ..." segment.
        if (preg_match('/^Reacted\s+(\X)\s+to\s+[\x{201c}"](.*?)[\x{201d}"]\s*$/su', $text, $matches)) {
            return [
                'reaction' => 'Reacted '.$matches[1],
                'emoji' => $matches[1],
                'quoted' => $matches[2],
            ];
        }

        // Polish carrier/iOS variant seen in the wild:
        // Dodano „kciuk w górę” do „original message”
        // Also handles common mojibake wrappers like â€ž ... â€.
        if (preg_match('/^Dodano\s+(.+?)\s+do\s+(.+)$/isu', $text, $matches)) {
            $reactionRaw = trim($matches[1]);
            $quotedRaw = trim($matches[2]);

            $reactionRaw = preg_replace('/^(?:â€ž|â€œ|[„“"«])+/u', '', $reactionRaw) ?? $reactionRaw;
            $reactionRaw = preg_replace('/(?:â€|â€|[”"»])+$/u', '', $reactionRaw) ?? $reactionRaw;

            $quotedRaw = preg_replace('/^(?:â€ž|â€œ|[„“"«])+/u', '', $quotedRaw) ?? $quotedRaw;
            $quotedRaw = preg_replace('/(?:â€|â€|[”"»])+$/u', '', $quotedRaw) ?? $quotedRaw;

            $reaction = self::repairMojibakeText(trim($reactionRaw));
            $quoted = self::repairMojibakeText(trim($quotedRaw));
            $emoji = self::detectReactionEmoji($reaction);

            if ($emoji && mb_strlen($quoted) >= 2) {
                return [
                    'reaction' => $reaction,
                    'emoji' => $emoji,
                    'quoted' => $quoted,
                    'strict' => true,
                ];
            }
        }

        // Generic multi-language fallback: extract quoted text from Unicode quotation
        // marks and match reaction keywords across many languages.
        return self::parseGenericTapback($text);
    }

    /**
     * Prefixes Apple uses for the SMS fallback sent when an iMessage user
     * edits a message delivered to a non-iMessage recipient, per sender locale.
     * Arrives as: Edited to "new text" / Editado como: "nuevo texto"
     */
    public const REMOTE_EDIT_PREFIXES = [
        'Edited to',       // English
        'Editado como',    // Spanish
        'Editado para',    // Portuguese
        'Modifié en',      // French
        'Modifiée en',     // French (alt)
        'Bearbeitet in',   // German
        'Bearbeitet zu',   // German (alt)
        'Modificato in',   // Italian
        'Zmieniono na',    // Polish
        'Изменено на',     // Russian
    ];

    /**
     * Parse a remote-edit notification message and return the replacement text.
     *
     * When an iPhone user edits a message that fell back to SMS, the carrier
     * delivers a follow-up like: Editado como: "new text". Returns the new
     * text so callers can apply it to the original message, or null when this
     * message is not an edit notification.
     */
    public function parseRemoteEdit(): ?string
    {
        if (! $this->text) {
            return null;
        }

        $text = self::repairMojibakeText(trim($this->text));

        // Repair double-encoded UTF-8 mojibake (same paths as parseTapback).
        if (preg_match('/\xC3[\x80-\xBF][\xC2-\xC5\xE2][\x80-\xBF]/', $text)
            || preg_match('/\xC3[\x80-\xBF]\xC2[\x80-\xBF]/', $text)) {
            $decoded = @mb_convert_encoding($text, 'Windows-1252', 'UTF-8');
            if ($decoded !== false && $decoded !== '' && mb_check_encoding($decoded, 'UTF-8')) {
                $text = $decoded;
            }
        }

        foreach (self::REMOTE_EDIT_PREFIXES as $prefix) {
            $escapedPrefix = preg_quote($prefix, '/');

            // Standard/Unicode quotes, optional colon after the prefix.
            if (preg_match('/^' . $escapedPrefix . '\s*:?\s*[\x{201c}"](.*?)[\x{201d}"]\s*$/su', $text, $matches)) {
                $edited = trim($matches[1]);

                return $edited !== '' ? $edited : null;
            }

            // Mojibake variant: â€œ as open, â€ (+U+009D) or end of string as close.
            if (preg_match('/^' . $escapedPrefix . '\s*:?\s*\x{00e2}\x{20ac}\x{0153}(.*?)(?:\x{00e2}\x{20ac}\x{009d}|\x{00e2}\x{20ac}$)\s*$/su', $text, $matches)) {
                $edited = trim(self::repairMojibakeText($matches[1]));

                return $edited !== '' ? $edited : null;
            }
        }

        return null;
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
            ["\u{201e}", "\u{201c}"],  // \u{201e}\u{201c} German low-high
            ["\u{201e}", "\u{201d}"],  // \u{201e}\u{201d} Polish low-right
            ["\u{00e2}\u{20ac}\u{017e}", "\u{00e2}\u{20ac}\u{009d}"], // mojibake Polish low-right
            ["\u{00e2}\u{20ac}\u{0153}", "\u{00e2}\u{20ac}\u{009d}"], // mojibake left-right
            ["\u{300c}", "\u{300d}"],  // \u{300c}\u{300d} CJK corner brackets
            ["\u{05f4}", "\u{05f4}"],  // ״ Hebrew Punctuation Gershayim (U+05F4)
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
        $text = self::repairMojibakeText($text);

        // Normalise smart quotes/apostrophes to ASCII for keyword matching
        $normalized = str_replace(
            ["\u{2018}", "\u{2019}", "\u{201a}", "\u{2032}"],
            "'",
            trim($text)
        );
        // Collapse all Unicode whitespace (NBSP, narrow NBSP, etc.) to a regular space
        // so keywords like "kciuk w gór" match when the source uses U+00A0.
        $normalized = preg_replace('/\s+/u', ' ', $normalized);
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
            "\u{1F525}" => '🔥',
            "\u{1F389}" => '🎉',
            "\u{1F44F}" => '👏',
            "\u{1F4AF}" => '💯',
            "\u{1F64F}" => '🙏',
            "\u{1F92F}" => '🤯',
            "\u{1F62D}" => '😭',
            "\u{1F62E}" => '😮',
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
     * Best-effort repair for UTF-8 mojibake text produced by double encoding.
     */
    private static function repairMojibakeText(string $text): string
    {
        $utf8 = @iconv('UTF-8', 'UTF-8//IGNORE', $text);
        if (is_string($utf8) && $utf8 !== '') {
            $text = $utf8;
        }

        $text = str_replace(
            [
                "\u{00e2}\u{20ac}\u{0153}",
                "\u{00e2}\u{20ac}\u{009d}",
                "\u{00e2}\u{20ac}\u{017e}",
                'â€',
                'â€',
                "\u{009d}",
                "\u{00c2}\u{00a0}",
            ],
            [
                "\u{201c}",
                "\u{201d}",
                "\u{201e}",
                "\u{201d}",
                "\u{201d}",
                "\u{201d}",
                ' ',
            ],
            $text,
        );

        if ($text === '' || ! preg_match('/[ÃÂâðÄÅ]/u', $text)) {
            return $text;
        }

        $candidates = [
            @mb_convert_encoding($text, 'Windows-1252', 'UTF-8'),
            @mb_convert_encoding($text, 'ISO-8859-1', 'UTF-8'),
        ];

        $score = static function (string $value): int {
            $penalty = preg_match_all('/[ÃÂâðÄÅ]/u', $value, $m);
            $signal = preg_match_all('/["“”„óęąśłżźćń]/u', $value, $m2);

            return (int) $signal - ((int) $penalty * 2);
        };

        $best = $text;
        $bestScore = $score($text);

        foreach ($candidates as $candidate) {
            if (! is_string($candidate) || $candidate === '') {
                continue;
            }

            $candidateUtf8 = @iconv('UTF-8', 'UTF-8//IGNORE', $candidate);
            if (! is_string($candidateUtf8) || $candidateUtf8 === '' || ! mb_check_encoding($candidateUtf8, 'UTF-8')) {
                continue;
            }

            $candidateScore = $score($candidateUtf8);
            if ($candidateScore > $bestScore) {
                $best = $candidateUtf8;
                $bestScore = $candidateScore;
            }
        }

        return $best;
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
