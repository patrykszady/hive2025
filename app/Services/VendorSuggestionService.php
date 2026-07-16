<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * AI merchant identification for the Match Vendor page.
 *
 * Takes an unmatched bank descriptor ("ROCKET 6546") plus its transactions'
 * Plaid context and asks a web-search-grounded model who the merchant is —
 * either one of our existing vendors or a new one (with name, website,
 * city/state). The human always confirms before anything is written.
 */
class VendorSuggestionService
{
    protected const CACHE_TTL = 60 * 60 * 24 * 7; // a week — descriptors are stable

    /**
     * @param  Collection  $transactions  Transaction models sharing the descriptor
     * @param  Collection  $vendors  all vendors (id, business_name, city, state)
     * @return array{vendor_name: string, existing_vendor_id: ?int, website: ?string, city: ?string, state: ?string, match_desc: string, confidence: string, reasoning: string}|null
     */
    public function suggest(string $descriptor, Collection $transactions, Collection $vendors): ?array
    {
        $cacheKey = 'vendor-suggest:'.md5($descriptor);

        $suggestion = Cache::get($cacheKey);

        if (! is_array($suggestion)) {
            $suggestion = $this->querySuggestion($descriptor, $transactions, $vendors);

            if ($suggestion === null) {
                // Never cache failures — a timeout or bad key must stay retryable.
                return null;
            }

            Cache::put($cacheKey, $suggestion, self::CACHE_TTL);
        }

        return $this->revalidateAgainstVendors($suggestion, $vendors);
    }

    /**
     * The cache can outlive vendor-list changes — re-check the mapping on
     * every read so a stale suggestion can neither point at a deleted vendor
     * nor propose creating a vendor that now exists (duplicate guard).
     */
    protected function revalidateAgainstVendors(array $suggestion, Collection $vendors): array
    {
        if (! empty($suggestion['existing_vendor_id'])
            && ! $vendors->contains('id', (int) $suggestion['existing_vendor_id'])) {
            $suggestion['existing_vendor_id'] = null;
        }

        if (empty($suggestion['existing_vendor_id'])) {
            $name = mb_strtolower(trim($suggestion['vendor_name']));
            $match = $vendors->first(fn ($vendor) => mb_strtolower(trim((string) $vendor->business_name)) === $name);

            if ($match) {
                $suggestion['existing_vendor_id'] = (int) $match->id;
            }
        }

        return $suggestion;
    }

    protected function querySuggestion(string $descriptor, Collection $transactions, Collection $vendors): ?array
    {
        $prompt = $this->buildPrompt($descriptor, $transactions, $vendors);

        // Fall back at the PARSE level too: a 200 web-search response with
        // malformed JSON should still try the json_object-enforced chat call.
        $raw = $this->callWithWebSearch($prompt);
        $parsed = $raw !== null ? $this->parseSuggestion($raw) : null;

        if ($parsed === null) {
            $raw = $this->callChatFallback($prompt);
            $parsed = $raw !== null ? $this->parseSuggestion($raw) : null;
        }

        if ($parsed === null) {
            Log::warning('Vendor suggestion failed on both calls.', [
                'descriptor' => $descriptor,
                'raw' => mb_substr((string) $raw, 0, 2000),
            ]);
        }

        return $parsed;
    }

    protected function buildPrompt(string $descriptor, Collection $transactions, Collection $vendors): string
    {
        $transactionLines = $transactions->take(10)->map(function ($t) {
            $details = is_array($t->details) ? $t->details : [];
            $location = collect(data_get($details, 'location', []))
                ->only(['address', 'city', 'region', 'postal_code'])->filter()->implode(', ');
            $category = implode(' > ', (array) data_get($details, 'category', []));
            $pfc = data_get($details, 'personal_finance_category.detailed');
            $channel = data_get($details, 'payment_channel');
            $bank = $t->bank_account?->bank?->name;

            return sprintf(
                '- %s | $%s | %s%s%s%s%s',
                $t->transaction_date?->format('Y-m-d'),
                number_format((float) $t->amount, 2),
                $bank ? $bank.' '.$t->bank_account?->type : 'unknown bank',
                $category ? ' | category: '.$category : '',
                $pfc ? ' | plaid_pfc: '.$pfc.' (may be wrong)' : '',
                $channel ? ' | channel: '.$channel : '',
                $location ? ' | location: '.$location : ' | location: none (bank strips it)',
            );
        })->implode("\n");

        $merchantName = $transactions->first()?->plaid_merchant_name;

        // Prescreen candidates: vendors sharing a distinctive token with the
        // descriptor — the full list is too long for a prompt.
        $descTokens = collect(preg_split('/[^A-Za-z0-9]+/', mb_strtolower($descriptor)))
            ->filter(fn ($token) => mb_strlen($token) >= 4);
        $candidates = $vendors->filter(function ($vendor) use ($descTokens) {
            $name = mb_strtolower($vendor->business_name);

            return $descTokens->contains(fn ($token) => str_contains($name, $token));
        })->take(30);

        $candidateLines = $candidates->map(fn ($v) => sprintf(
            '- id %d: %s%s',
            $v->id,
            $v->business_name,
            $v->city ? ' ('.$v->city.($v->state ? ', '.$v->state : '').')' : '',
        ))->implode("\n");

        return <<<PROMPT
You identify merchants from bank/credit-card statement descriptors for a construction company based in Mount Prospect, IL (Chicago northwest suburbs). Most in-store charges happen in the Chicagoland area. Some banks (Capital One) truncate descriptors and strip location data, and Plaid's category guesses are frequently wrong for small local businesses.

Descriptor: "{$descriptor}"
Plaid merchant name guess: "{$merchantName}"

Transactions with this descriptor:
{$transactionLines}

Existing vendors that might match (id: name):
{$candidateLines}

Search the web for this descriptor (e.g. "{$descriptor} charge", the merchant name plus Chicagoland) to identify the actual business. Trailing numbers/codes in descriptors are usually store or terminal numbers — focus on the name part.

Respond with ONLY a JSON object, no markdown fences:
{
  "vendor_name": "the real business name",
  "existing_vendor_id": <id from the list above if this is one of them, else null>,
  "website": "https://... or null",
  "city": "city if you are confident about the specific location, else null",
  "state": "2-letter state or null",
  "match_desc": "the stable text part of the descriptor to auto-match future charges (e.g. 'ROCKET ' for 'ROCKET 6546')",
  "confidence": "high|medium|low",
  "reasoning": "1-3 sentences: what this most likely is and why; mention other plausible candidates if unsure"
}
PROMPT;
    }

    /**
     * Responses API with the web_search tool. Returns raw output text or null.
     */
    protected function callWithWebSearch(string $prompt): ?string
    {
        try {
            $response = Http::withToken(config('services.openai.api_key'))
                ->timeout(25)
                ->post('https://api.openai.com/v1/responses', [
                    'model' => config('services.openai.vendor_suggestion_model', 'gpt-4o'),
                    'tools' => [['type' => 'web_search_preview']],
                    'input' => $prompt,
                ]);

            if ($response->failed()) {
                Log::info('Vendor suggestion web-search call failed, falling back to chat.', [
                    'status' => $response->status(),
                    'body' => mb_substr($response->body(), 0, 500),
                ]);

                return null;
            }

            // Output is a list of items; the assistant message holds the text.
            foreach (array_reverse($response->json('output', [])) as $item) {
                if (($item['type'] ?? null) === 'message') {
                    foreach ($item['content'] ?? [] as $content) {
                        if (($content['type'] ?? null) === 'output_text' && filled($content['text'] ?? null)) {
                            return $content['text'];
                        }
                    }
                }
            }

            return null;
        } catch (\Throwable $e) {
            Log::warning('Vendor suggestion web-search call threw.', ['error' => $e->getMessage()]);

            return null;
        }
    }

    protected function callChatFallback(string $prompt): ?string
    {
        try {
            $response = Http::withToken(config('services.openai.api_key'))
                ->timeout(25)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => config('services.openai.vendor_suggestion_fallback_model', 'gpt-4o'),
                    'messages' => [['role' => 'user', 'content' => $prompt]],
                    'response_format' => ['type' => 'json_object'],
                ]);

            if ($response->failed()) {
                Log::warning('Vendor suggestion chat fallback failed.', [
                    'status' => $response->status(),
                    'body' => mb_substr($response->body(), 0, 500),
                ]);

                return null;
            }

            return $response->json('choices.0.message.content');
        } catch (\Throwable $e) {
            Log::warning('Vendor suggestion chat fallback threw.', ['error' => $e->getMessage()]);

            return null;
        }
    }

    protected const US_STATES = [
        'AL', 'AK', 'AZ', 'AR', 'CA', 'CO', 'CT', 'DE', 'FL', 'GA', 'HI', 'ID',
        'IL', 'IN', 'IA', 'KS', 'KY', 'LA', 'ME', 'MD', 'MA', 'MI', 'MN', 'MS',
        'MO', 'MT', 'NE', 'NV', 'NH', 'NJ', 'NM', 'NY', 'NC', 'ND', 'OH', 'OK',
        'OR', 'PA', 'RI', 'SC', 'SD', 'TN', 'TX', 'UT', 'VT', 'VA', 'WA', 'WV',
        'WI', 'WY', 'DC', 'PR', 'VI', 'GU', 'AS', 'MP',
    ];

    /**
     * Accept only a real USPS code — a full state name must fail safe to
     * null, never truncate ("New Jersey" is not "NE"braska).
     */
    protected function normalizeState($state): ?string
    {
        if (blank($state)) {
            return null;
        }

        $state = strtoupper(trim((string) $state));

        return in_array($state, self::US_STATES, true) ? $state : null;
    }

    protected function parseSuggestion(string $raw): ?array
    {
        // Strip markdown fences if the model added them anyway.
        $json = trim(preg_replace('/^```(?:json)?|```$/m', '', trim($raw)));

        // Grab the outermost object if there is prose around it.
        if (! str_starts_with($json, '{')) {
            $start = strpos($json, '{');
            $end = strrpos($json, '}');
            if ($start === false || $end === false || $end <= $start) {
                return null;
            }
            $json = substr($json, $start, $end - $start + 1);
        }

        $data = json_decode($json, true);

        if (! is_array($data) || blank($data['vendor_name'] ?? null)) {
            return null;
        }

        // The website renders as a link in the UI — only allow http(s).
        $website = filled($data['website'] ?? null) ? (string) $data['website'] : null;
        if ($website !== null && ! preg_match('#^https?://#i', $website)) {
            $website = null;
        }

        return [
            'vendor_name' => (string) $data['vendor_name'],
            'existing_vendor_id' => isset($data['existing_vendor_id']) && $data['existing_vendor_id'] !== null
                ? (int) $data['existing_vendor_id'] : null,
            'website' => $website,
            'city' => filled($data['city'] ?? null) ? (string) $data['city'] : null,
            'state' => $this->normalizeState($data['state'] ?? null),
            'match_desc' => filled($data['match_desc'] ?? null) ? (string) $data['match_desc'] : '',
            'confidence' => in_array($data['confidence'] ?? null, ['high', 'medium', 'low'], true)
                ? $data['confidence'] : 'low',
            'reasoning' => (string) ($data['reasoning'] ?? ''),
        ];
    }
}
