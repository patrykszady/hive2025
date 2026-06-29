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
        $protectedLines = [];
        $textForTranslation = $this->protectTaskAndAddressLines($text, $protectedLines);

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

        $cacheKey = 'sms-translate:' . md5(implode('|', [$sourceLanguage ?? 'auto', $targetLanguage, $textForTranslation]));

        $translated = Cache::remember($cacheKey, now()->addDays(30), function () use ($apiKey, $sourceLanguage, $targetLanguage, $text, $textForTranslation, $protectedLines): string {
            $model = config('services.openai.sms_translation_model', 'gpt-4o-mini');
            $source = $sourceLanguage ? "from {$sourceLanguage} " : '';

            $system = <<<PROMPT
Translate SMS text {$source}to {$targetLanguage}.
Rules:
- Preserve intent, formatting, newlines, bullets, dates, times, URLs, and addresses.
- Keep names exactly as written.
    - Keep any token in the format [[[KEEP_LINE_X]]] unchanged.
    - Do not translate task bullet lines or US postal address lines.
- Return only the translated text with no commentary.
PROMPT;

            $response = Http::withToken($apiKey)
                ->timeout(30)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => $model,
                    'messages' => [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user', 'content' => $textForTranslation],
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

            $translated = $this->restoreProtectedLines($translated, $protectedLines);

            return $translated !== '' ? $translated : $text;
        });

        if ($this->isInvalidTranslationResult($translated)) {
            Cache::forget($cacheKey);

            return $text;
        }

        return $translated;
    }

    /**
     * @param  array<string, string>  $protectedLines
     */
    private function protectTaskAndAddressLines(string $text, array &$protectedLines): string
    {
        $lines = preg_split('/\R/u', $text) ?: [];

        foreach ($lines as $index => $line) {
            if (! $this->shouldPreserveLine($line)) {
                continue;
            }

            $token = "[[[KEEP_LINE_{$index}]]]";
            $protectedLines[$token] = $line;
            $lines[$index] = $token;
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, string>  $protectedLines
     */
    private function restoreProtectedLines(string $translated, array $protectedLines): string
    {
        if ($protectedLines === []) {
            return $translated;
        }

        return str_replace(array_keys($protectedLines), array_values($protectedLines), $translated);
    }

    private function shouldPreserveLine(string $line): bool
    {
        $trimmed = trim($line);

        if ($trimmed === '') {
            return false;
        }

        if (str_starts_with($trimmed, '- ')) {
            return true;
        }

        if (preg_match('/^\d{1,6}\s+.+\b(?:St|Street|Ave|Avenue|Rd|Road|Dr|Drive|Ln|Lane|Ct|Court|Blvd|Boulevard|Way|Pl|Place|Pkwy|Parkway|Cir|Circle|Ter|Terrace|Hwy|Highway)\b\.?$/i', $trimmed) === 1) {
            return true;
        }

        if (preg_match('/^[A-Za-z][A-Za-z .\'-]*,\s*[A-Z]{2}\s+\d{5}(?:-\d{4})?$/', $trimmed) === 1) {
            return true;
        }

        if ($this->isScheduleDayHeadingLine($trimmed)) {
            return true;
        }

        return false;
    }

    private function isScheduleDayHeadingLine(string $line): bool
    {
        return preg_match(
            '/^(?:Today|Tomorrow|Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday)(?:\s+(?:Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday))?\s+\d{1,2}\/\d{1,2}:$/i',
            $line
        ) === 1;
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
