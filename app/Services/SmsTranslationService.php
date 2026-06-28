<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SmsTranslationService
{
    /**
     * SMS control keywords that must not be translated.
     *
     * @var array<int, string>
     */
    private const CONTROL_KEYWORDS = [
        'START',
        'STOP',
        'HELP',
        'YES',
        'NO',
        'UNSTOP',
        'QUIT',
        'CANCEL',
        'SUBSCRIBE',
        'UNSUBSCRIBE',
    ];

    public function normalizeLanguage(string $language): string
    {
        $normalized = strtolower(trim($language));

        return match (true) {
            $normalized === '',
            str_contains($normalized, 'english'),
            $normalized === 'en' => 'English',
            str_contains($normalized, 'polish'),
            str_contains($normalized, 'polski'),
            $normalized === 'pl' => 'Polish',
            str_contains($normalized, 'spanish'),
            str_contains($normalized, 'espanol'),
            str_contains($normalized, 'español'),
            $normalized === 'es' => 'Spanish',
            default => ucfirst($language),
        };
    }

    public function translate(string $text, string $targetLanguage, ?string $sourceLanguage = null): string
    {
        $text = trim($text);
        $targetLanguage = $this->normalizeLanguage($targetLanguage);
        $sourceLanguage = $sourceLanguage ? $this->normalizeLanguage($sourceLanguage) : null;

        if ($text === '') {
            return '';
        }

        if ($this->isControlKeywordMessage($text)) {
            return $text;
        }

        if ($sourceLanguage && strcasecmp($sourceLanguage, $targetLanguage) === 0) {
            return $text;
        }

        $apiKey = config('services.openai.api_key');
        if (! $apiKey) {
            return $text;
        }

        $cacheKey = 'sms-translate:' . md5(implode('|', [$sourceLanguage ?? 'auto', $targetLanguage, $text]));

        $translated = Cache::remember($cacheKey, now()->addDays(30), function () use ($apiKey, $sourceLanguage, $targetLanguage, $text): string {
            $model = config('services.openai.sms_translation_model', 'gpt-4o-mini');
            $source = $sourceLanguage ? "from {$sourceLanguage} " : '';

            $system = <<<PROMPT
Translate SMS text {$source}to {$targetLanguage}.
Rules:
- Preserve intent, formatting, newlines, bullets, dates, times, URLs, and addresses.
- Keep names exactly as written.
- Return only the translated text with no commentary.
PROMPT;

            $response = Http::withToken($apiKey)
                ->timeout(30)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => $model,
                    'messages' => [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user', 'content' => $text],
                    ],
                    'temperature' => 0,
                ]);

            if (! $response->successful()) {
                Log::warning('SmsTranslationService: OpenAI translation failed', [
                    'status' => $response->status(),
                ]);

                return $text;
            }

            $translated = trim((string) data_get($response->json(), 'choices.0.message.content', ''));

            if ($this->isInvalidTranslationResult($translated)) {
                return $text;
            }

            return $translated !== '' ? $translated : $text;
        });

        if ($this->isInvalidTranslationResult($translated)) {
            Cache::forget($cacheKey);

            return $text;
        }

        return $translated;
    }

    private function isControlKeywordMessage(string $text): bool
    {
        $normalized = strtoupper(trim($text));

        return in_array($normalized, self::CONTROL_KEYWORDS, true);
    }

    private function isInvalidTranslationResult(string $translated): bool
    {
        $normalized = strtolower(trim($translated));

        if ($normalized === '') {
            return true;
        }

        if (
            str_contains($normalized, 'please provide')
            && str_contains($normalized, 'translated')
        ) {
            return true;
        }

        return false;
    }
}
