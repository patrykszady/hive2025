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
        // v4 (2026-10-02): the prompt carries location evidence and places
        // the card member's trip; answers given without them are not reused.
        $cacheKey = 'vendor-suggest:v4:'.md5($descriptor);

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
            $location = collect(['address', 'city', 'region', 'postal_code', 'store_number'])
                ->map(fn (string $field) => data_get($details, 'location.'.$field))->filter()->implode(', ');
            $category = implode(' > ', (array) data_get($details, 'category', []));
            $pfc = data_get($details, 'personal_finance_category.detailed');
            $channel = data_get($details, 'payment_channel');
            $mcc = data_get($details, 'merchant_category_code');
            $owner = data_get($details, 'account_owner');
            $bank = $t->bank_account?->bank?->name;

            return sprintf(
                '- %s | $%s | %s%s%s%s%s%s%s',
                $t->transaction_date?->format('Y-m-d'),
                number_format((float) $t->amount, 2),
                $bank ? $bank.' '.$t->bank_account?->type : 'unknown bank',
                $owner ? ' | card member ending '.$owner : '',
                $mcc ? ' | MCC '.$mcc : '',
                $category ? ' | category: '.$category : '',
                $pfc ? ' | plaid_pfc: '.$pfc.' (may be wrong)' : '',
                $channel ? ' | channel: '.$channel : '',
                $location ? ' | LOCATION: '.$location : ' | location: none on this charge',
            );
        })->implode("\n");

        $merchantName = $transactions->first()?->plaid_merchant_name;
        $counterparty = collect((array) data_get($transactions->first()?->details, 'counterparties', []))->firstWhere('type', 'merchant');
        $plaidMatch = $counterparty
            ? sprintf('"%s" (Plaid confidence: %s%s)', $counterparty['name'] ?? $merchantName, $counterparty['confidence_level'] ?? 'unknown', ! empty($counterparty['website']) ? ', website '.$counterparty['website'] : '')
            : '"'.$merchantName.'"';
        $sameCard = $this->sameCardContext($transactions);

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
You identify merchants from bank/credit-card statement descriptors for a construction company based in Mount Prospect, IL (Chicago northwest suburbs). Its people also travel. Decide WHERE the merchant is from the evidence, in this order:
1. A LOCATION on one of the transactions below: the merchant is there. Search there.
2. Where the card was around that date (below). Look up the merchants listed without a place: a restaurant or shop on the same card is where the card member was. Out-of-state places, airlines, shuttles or lodging mean the card was travelling, and the merchant is likely at that destination. Work out the destination first (search which airport and towns a shuttle runs between, where a hotel or a named restaurant is) before you identify the merchant. Employees share the account, so Chicagoland charges on other cards say nothing about this card; their travel can show where a shared trip went. Dates are the bank's posting dates, often a day or two after the purchase.
3. Only when neither points elsewhere, assume the Chicagoland area.
An airport restaurant or shop charged on a travel day is at an airport of that trip: the destination's airport, or O'Hare (ORD) / Midway (MDW) at home; check which of them the business operates in. Short letter codes after a dash or store number in a descriptor (e.g. "-ME") are usually the outlet's code, not a state. A match outside Illinois is not doubtful in itself when the evidence puts the card there. Some banks (Capital One) truncate descriptors and drop the location, and Plaid's category guesses are often wrong for small local businesses.

Descriptor: "{$descriptor}"
Plaid's merchant match: {$plaidMatch}

Transactions with this descriptor:
{$transactionLines}

{$sameCard}

Existing vendors that might match (id: name):
{$candidateLines}

Search the web for this descriptor and Plaid's merchant name in the place the evidence points to (Chicagoland only if nothing points elsewhere) to identify the actual business. Trailing numbers/codes in descriptors are usually store or terminal numbers — focus on the name part.

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
     * Where the card was around these charges. Capital One names the card
     * member (account_owner, the employee card's last four digits) on every
     * charge, so that card's in-person charges three days either side are
     * all listed by name — a restaurant with no location is still evidence
     * once looked up — while other employees' cards on the account add only
     * travel and places outside Illinois. Accounts without card members get
     * the day before to the day after, places and travel only. Online
     * charges are left out: their "location" is the seller's HQ. Without a
     * Plaid location the matched vendor's city on file stands in, so one
     * identified merchant places the rest of the trip.
     * 2026-10-02: "LA NUEVA VIZCAINA" and "LA VENDIMIA DE JOSE" on card 0616
     * were placed in Chicago and San Jose from other cards' Home Depot runs;
     * 0616's own charges (Doña Fela two days later, Frontier) were in San
     * Juan, PR.
     */
    protected function sameCardContext(Collection $transactions): string
    {
        $ids = $transactions->pluck('id')->filter()->all();
        $owners = $transactions->take(5)->map(fn ($t) => (string) data_get($t->details, 'account_owner'))->filter()->unique()->values();
        $days = $owners->isNotEmpty() ? 3 : 1;
        $thisCard = collect();
        $otherCards = collect();

        foreach ($transactions->take(5) as $transaction) {
            if (! $transaction->bank_account_id || ! $transaction->transaction_date) {
                continue;
            }

            \App\Models\Transaction::withoutGlobalScopes()
                ->whereNull('deleted_at')
                ->where('bank_account_id', $transaction->bank_account_id)
                ->whereNotIn('id', $ids)
                ->whereBetween('transaction_date', [$transaction->transaction_date->copy()->subDays($days)->toDateString(), $transaction->transaction_date->copy()->addDays($days)->toDateString()])
                ->with(['vendor' => fn ($query) => $query->withoutGlobalScopes()->select(['id', 'business_name', 'city', 'state'])])
                ->orderBy('transaction_date')
                ->limit(150)
                ->get()
                ->each(function ($nearby) use ($owners, $thisCard, $otherCards) {
                    $details = is_array($nearby->details) ? $nearby->details : [];
                    if (data_get($details, 'payment_channel') === 'online') {
                        return;
                    }

                    $place = collect([data_get($details, 'location.city'), data_get($details, 'location.region')])->filter()->implode(', ');
                    $state = (string) data_get($details, 'location.region');
                    if ($place === '' && filled($nearby->vendor?->city)) {
                        $place = collect([$nearby->vendor->city, $nearby->vendor->state])->filter()->implode(', ').' (on file)';
                        $state = (string) $nearby->vendor->state;
                    }
                    $name = $nearby->plaid_merchant_name ?: $nearby->plaid_merchant_description;
                    $kind = $this->travelKind($nearby, $details);
                    $owner = (string) data_get($details, 'account_owner');
                    $line = sprintf('- %s | %s%s%s', $nearby->transaction_date?->format('Y-m-d'), $name, $place !== '' ? ' | '.$place : '', $kind ? ' | '.$kind : '');

                    if ($owners->contains($owner)) {
                        $thisCard->put($nearby->id, $line);

                        return;
                    }

                    $away = $kind !== null || ($state !== '' && strtoupper($state) !== 'IL');
                    if ($away || ($owners->isEmpty() && $place !== '')) {
                        $otherCards->put($nearby->id, $line.($owner !== '' ? ' | card ending '.$owner : ''));
                    }
                });
        }

        if ($owners->isEmpty()) {
            return "Same card, the day before to the day after (in-person charges with a place, and travel; \"on file\" is the address saved on our vendor record):\n"
                .($otherCards->isEmpty() ? 'none with a place or travel' : $otherCards->take(15)->implode("\n"));
        }

        return sprintf("This card (card member ending %s), %d days either side, every in-person charge (\"on file\" is the address saved on our vendor record):\n%s\n\nOther employees' cards on the account, same days, only travel and places outside Illinois:\n%s",
            $owners->implode(', '),
            $days,
            $thisCard->isEmpty() ? 'none' : $thisCard->take(25)->implode("\n"),
            $otherCards->isEmpty() ? 'none' : $otherCards->take(15)->implode("\n"),
        );
    }

    /**
     * Airline, lodging, car rental or ground transport, by the card network's
     * merchant category code when Plaid sends one, else by name. The code
     * wins: "LAMPLIGHTER INN TAVE" is a bar (5813), not lodging.
     */
    protected function travelKind(\App\Models\Transaction $transaction, array $details): ?string
    {
        $mcc = (int) data_get($details, 'merchant_category_code');

        if ($mcc > 0) {
            return match (true) {
                ($mcc >= 3000 && $mcc <= 3299) || in_array($mcc, [4511, 4582], true) => 'airline',
                ($mcc >= 3351 && $mcc <= 3500) || in_array($mcc, [7512, 7519], true) => 'car rental',
                ($mcc >= 3501 && $mcc <= 3999) || in_array($mcc, [4722, 7011, 7012], true) => 'lodging',
                in_array($mcc, [4111, 4112, 4131, 4411, 4789], true) => 'ground transport',
                default => null,
            };
        }

        $text = implode(' ', [$transaction->plaid_merchant_name, $transaction->plaid_merchant_description, $transaction->vendor?->business_name]);

        return match (true) {
            (bool) preg_match('/airline|airways|air lines|airport|frontier ai|spirit air|delta air|united air|southwest air|american air|jetblue|alaska air/i', $text) => 'airline',
            (bool) preg_match('/hertz|avis\b|enterprise rent|national car|budget car|alamo|sixt|car rental/i', $text) => 'car rental',
            preg_match('/hotel|motel|resort|lodge|\binn\b|airbnb|vrbo|marriott|hilton|hyatt/i', $text) && ! preg_match('/tavern|\btave|\bpub\b|\bbar\b|grill|restaurant|pizz/i', $text) => 'lodging',
            (bool) preg_match('/shuttle|amtrak|greyhound|ferry|cruise/i', $text) => 'ground transport',
            default => null,
        };
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
