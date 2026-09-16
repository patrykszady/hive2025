<?php

namespace App\Services;

use Anthropic\Beta\Messages\BetaRawContentBlockDeltaEvent;
use Anthropic\Beta\Messages\BetaRawMessageDeltaEvent;
use Anthropic\Beta\Messages\BetaRawMessageStartEvent;
use Anthropic\Beta\Messages\BetaTextDelta;
use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\RateLimitException;
use App\Models\Estimate;
use App\Models\EstimateAiDraft;
use App\Models\EstimateAiRule;
use App\Models\EstimateLineItem;
use App\Models\EstimateSection;
use App\Models\LineItem;
use App\Models\Vendor;
use App\Services\EstimateAI\DraftCorrections;
use App\Services\EstimateAI\LineItemStreamParser;
use App\Services\EstimateAI\SectionRetriever;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Drafts estimate line items with Claude.
 *
 * Replaces the GPT-4 generator. Three things are different by construction:
 *
 *  - The output is structured JSON whose `line_item_id` is an enum of the
 *    catalog ids we hand the model, so an off-catalog item cannot come back.
 *    Prices never come from the model either — every cost is re-read from
 *    the catalog before anything is shown (see normalizeLineItems).
 *  - The examples are the company's own most recent estimate sections for
 *    the rooms the enquiry mentions, not two hard-coded bathrooms.
 *  - Client details (emails, phone numbers, street addresses) are stripped
 *    from the enquiry before it is sent, and only the redacted text is
 *    logged.
 */
class EstimateAIService
{
    /** Catalog lines offered to the model at most; each is one short line. */
    public const CATALOG_CAP = 500;

    public const FALLBACK_BETA = 'server-side-fallback-2026-07-01';

    protected ?Client $client;

    protected string $model;

    protected ?SectionRetriever $retriever;

    public function __construct(?Client $client = null, ?SectionRetriever $retriever = null)
    {
        $this->client = $client;
        $this->retriever = $retriever;
        $this->model = (string) config('services.anthropic.estimate_model', 'claude-opus-5');
    }

    /**
     * Generate estimate line items based on inquiry and optional floorplan data.
     *
     * @param  string  $inquiry  The customer inquiry describing the work needed
     * @param  array|null  $floorplanData  Optional parsed floorplan data (dimensions, room info)
     * @param  int  $vendorId  The vendor ID to filter line items
     * @return array{success: bool, line_items: array, reasoning: string, error?: string}
     */
    /**
     * @param  callable(array<string, mixed> $lineItem, int $index): void|null  $onLineItem  called with each
     *                                                                                   catalog-resolved line item as the model finishes it
     * @param  list<int>  $excludeSectionIds  past sections never shown as examples (the evaluation's own answer key)
     * @return array{success: bool, line_items: array<int, array<string, mixed>>, reasoning?: string, error?: string, meta: array<string, mixed>}
     */
    public function generateEstimate(string $inquiry, ?array $floorplanData = null, int $vendorId = 1, ?callable $onLineItem = null, array $excludeSectionIds = []): array
    {
        $requestId = uniqid('est_ai_');
        $meta = ['request_id' => $requestId, 'model' => $this->model, 'usage' => null, 'stop_reason' => null, 'example_section_ids' => [], 'catalog_count' => 0, 'floorplan' => null];
        $redactedInquiry = static::redact($inquiry);
        // Only the numbers. The parser also returns the uploaded file's name
        // (client-chosen: "Debby_Hill_1463_Winnetka.pdf") and free text — none
        // of which the model or the log has any use for.
        $floorplanData = static::floorplanMetrics($floorplanData);
        $meta['floorplan'] = $floorplanData;

        Log::channel('estimate_ai')->info('Estimate generation started', [
            'request_id' => $requestId,
            'vendor_id' => $vendorId,
            'model' => $this->model,
            'inquiry' => $redactedInquiry,
            'redacted' => $redactedInquiry !== $inquiry,
            'has_floorplan' => ! empty($floorplanData),
            'floorplan_data' => $floorplanData,
        ]);

        try {
            $availableLineItems = $this->filterRelevantLineItems($this->getAvailableLineItems($vendorId), $redactedInquiry);
            $examples = $this->getExampleSections($vendorId, $redactedInquiry, 4, $excludeSectionIds);
            $rules = $this->activeRules($vendorId);
            $corrections = $this->pastCorrections(array_column($examples, 'section_id'));
            $meta['example_section_ids'] = array_column($examples, 'section_id');
            $meta['catalog_count'] = $availableLineItems->count();

            $request = $this->buildRequest($redactedInquiry, $floorplanData, $availableLineItems, $examples, $rules, $corrections, $this->companyLine($vendorId));

            Log::channel('estimate_ai')->debug('Request built', [
                'request_id' => $requestId,
                'catalog_count' => $availableLineItems->count(),
                'categories' => $availableLineItems->pluck('category')->unique()->values()->all(),
                'example_sections' => collect($examples)->map(fn ($e) => $e['name'].' ('.$e['score'].')')->all(),
                'rules' => count($rules),
                'corrections' => count($corrections),
            ]);

            $onText = $onLineItem === null
                ? null
                : $this->streamLineItems($onLineItem, $availableLineItems, $floorplanData);

            $completion = $this->complete($request, $onText);
            $meta['model'] = $completion['model'];
            $meta['usage'] = $completion['usage'];
            $meta['stop_reason'] = $completion['stop_reason'];

            Log::channel('estimate_ai')->debug('Claude response received', [
                'request_id' => $requestId,
                'model' => $completion['model'],
                'stop_reason' => $completion['stop_reason'],
                'usage' => $completion['usage'],
                'raw_response' => $completion['text'],
            ]);

            if ($completion['stop_reason'] === 'refusal') {
                return $this->failure($requestId, 'Claude declined to draft this estimate. Reword the description and try again.', $meta);
            }

            if ($completion['stop_reason'] === 'max_tokens') {
                return $this->failure($requestId, 'The draft was cut off before it finished. Try a shorter description or fewer rooms at once.', $meta);
            }

            $result = $this->parseResponse($completion['text']);

            if ($result['success']) {
                $allowed = $availableLineItems->pluck('id')->map(fn ($id) => (int) $id)->all();
                $result['line_items'] = $this->normalizeLineItems($result['line_items'], $allowed);
            }

            if ($result['success'] && ! empty($floorplanData)) {
                $result['line_items'] = $this->applyFloorplanQuantities($result['line_items'], $floorplanData);
            }

            Log::channel('estimate_ai')->info('Estimate generation completed', [
                'request_id' => $requestId,
                'success' => $result['success'],
                'line_items_count' => count($result['line_items']),
                'reasoning' => $result['reasoning'] ?? null,
                'line_items' => $result['line_items'],
            ]);

            $result['meta'] = $meta;

            return $result;
        } catch (\Throwable $e) {
            Log::channel('estimate_ai')->error('Estimate generation failed', [
                'request_id' => $requestId,
                'error' => $e->getMessage(),
                'exception_class' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);
            Log::error('EstimateAIService error: '.$e->getMessage());

            return $this->failure($requestId, $this->friendlyError($e), $meta);
        }
    }

    /**
     * Apply AI-generated line items to an estimate section.
     *
     * @param  array<int, array<string, mixed>>  $generatedItems
     * @return list<EstimateLineItem>
     */
    public function applyToEstimate(Estimate $estimate, EstimateSection $section, array $generatedItems): array
    {
        Log::channel('estimate_ai')->info('Applying AI line items to estimate', [
            'estimate_id' => $estimate->id,
            'section_id' => $section->id,
            'generated_items_count' => count($generatedItems),
        ]);

        $createdItems = [];
        $skippedItems = [];

        foreach ($generatedItems as $item) {
            $created = $this->applyLineItem($estimate, $section, $item);

            if ($created === null) {
                $skippedItems[] = ['line_item_id' => $item['line_item_id'] ?? null, 'reason' => 'Line item not found'];

                continue;
            }

            $createdItems[] = $created;
        }

        $section->total = $section->estimate_line_items()->sum('total');
        $section->save();

        Log::channel('estimate_ai')->info('Applied AI line items to estimate', [
            'estimate_id' => $estimate->id,
            'section_id' => $section->id,
            'created_count' => count($createdItems),
            'skipped_count' => count($skippedItems),
            'skipped_items' => $skippedItems,
        ]);

        return $createdItems;
    }

    /**
     * One drafted item onto the estimate, at the catalog's price and with the
     * catalog's own text. Null when the catalog item no longer exists.
     *
     * @param  array<string, mixed>  $item
     */
    public function applyLineItem(Estimate $estimate, EstimateSection $section, array $item): ?EstimateLineItem
    {
        $lineItem = LineItem::find($item['line_item_id'] ?? null);

        if (! $lineItem) {
            return null;
        }

        $quantity = is_numeric($item['quantity'] ?? null) && (float) $item['quantity'] > 0 ? (float) $item['quantity'] : 1.0;
        // The catalog price, always — never a figure the model wrote.
        $cost = (float) $lineItem->cost;

        return EstimateLineItem::create([
            'estimate_id' => $estimate->id,
            'line_item_id' => $lineItem->id,
            'section_id' => $section->id,
            'order' => ($section->estimate_line_items()->max('order') ?? -1) + 1,
            'name' => $lineItem->name,
            'category' => $lineItem->category,
            'sub_category' => $lineItem->sub_category,
            'unit_type' => $lineItem->unit_type,
            'quantity' => $quantity,
            'cost' => $cost,
            'total' => $quantity * $cost,
            // The catalog's own text, verbatim — the description and
            // notes already on file for this item are what the estimate
            // says, whether a person or the model picked it (2026-09-16:
            // a drafted kitchen carried the model's rewordings instead).
            'desc' => $lineItem->desc,
            'notes' => $lineItem->notes,
        ]);
    }

    /**
     * The floorplan fields anything downstream reads — and therefore the only
     * ones sent or logged.
     */
    public static function floorplanMetrics(?array $floorplanData): ?array
    {
        if (! $floorplanData) {
            return null;
        }

        $numbers = [
            'floor_sqft', 'wall_sqft', 'cement_board_sqft', 'ceiling_height_ft', 'perimeter_ft',
            'window_area_sqft', 'window_casing_lf', 'door_casing_lf',
            'cabinet_count', 'base_cabinet_lf', 'upper_cabinet_lf', 'tall_cabinet_lf', 'countertop_lf',
        ];

        $metrics = array_map(
            fn ($v) => (float) $v,
            array_filter(array_intersect_key($floorplanData, array_flip($numbers)), fn ($v) => is_numeric($v)),
        );

        // A room scan also says which room each number belongs to, and what
        // appliances stand there — nothing in either identifies the client.
        $rooms = collect((array) ($floorplanData['rooms'] ?? []))
            ->filter(fn ($r) => is_array($r) && filled($r['name'] ?? null))
            ->map(fn (array $r) => array_filter([
                'name' => Str::limit((string) $r['name'], 40, ''),
                'dimensions' => isset($r['dimensions']) ? Str::limit((string) $r['dimensions'], 40, '') : null,
            ] + array_map(fn ($v) => (float) $v, array_filter(
                array_intersect_key($r, array_flip(['floor_sqft', 'wall_sqft', 'ceiling_height_ft', 'perimeter_ft', 'window_casing_lf', 'door_casing_lf'])),
                fn ($v) => is_numeric($v),
            ))))
            ->values()
            ->all();
        if ($rooms !== []) {
            $metrics['rooms'] = $rooms;
        }

        $appliances = array_filter(array_map('intval', array_filter((array) ($floorplanData['appliances'] ?? []), 'is_numeric')));
        if ($appliances !== []) {
            $metrics['appliances'] = $appliances;
        }

        return $metrics === [] ? null : $metrics;
    }

    /**
     * Strip the details that identify a client before text leaves the
     * building: email addresses, phone numbers, street addresses and ZIP
     * codes. Names are left alone — a first name is not enough to identify
     * anyone, and stripping every capitalised word would eat "Kohler" and
     * "Hall Bath" too.
     */
    public static function redact(string $text): string
    {
        $patterns = [
            // emails
            '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/u' => '[email]',
            // US phone numbers: (224) 555-1234, 224-555-1234, 224.555.1234, +1 224 555 1234
            '/(?:\+?1[\s.-]?)?\(?\b\d{3}\)?[\s.-]?\d{3}[\s.-]?\d{4}\b/' => '[phone]',
            // street addresses: 3299 Middlesax Drive, 1463 W Winnetka St, 12 N. Main Ave.
            '/\b\d{1,6}(?:-\d{1,4})?\s+(?:[NSEW]\.?\s+)?(?:[A-Z][A-Za-z\'\-]+\s+){1,4}(?:Street|St|Avenue|Ave|Road|Rd|Drive|Dr|Lane|Ln|Court|Ct|Boulevard|Blvd|Place|Pl|Way|Circle|Cir|Trail|Trl|Terrace|Ter|Parkway|Pkwy|Highway|Hwy)\.?(?:\s*(?:#|Apt\.?|Suite|Ste\.?|Unit)\s*[\w-]+)?\b/i' => '[address]',
            // ZIP and ZIP+4
            '/\b\d{5}(?:-\d{4})?\b/' => '[zip]',
        ];

        return (string) preg_replace(array_keys($patterns), array_values($patterns), $text);
    }

    /**
     * The request as the SDK receives it. Kept as one array so a test can
     * assert on exactly what would be sent.
     *
     * @return array<string, mixed>
     */
    protected function buildRequest(string $inquiry, ?array $floorplanData, Collection $lineItems, array $examples, array $rules = [], array $corrections = [], string $companyLine = ''): array
    {
        return [
            'model' => $this->model,
            'maxTokens' => 16000,
            // No cache marker: the system prompt is ~350 tokens, under Opus 5's
            // 512-token minimum cacheable prefix, so a marker would never cache.
            'system' => [
                ['type' => 'text', 'text' => $this->systemPrompt($companyLine)],
            ],
            'messages' => [
                ['role' => 'user', 'content' => $this->userPrompt($inquiry, $floorplanData, $lineItems, $examples, $rules, $corrections)],
            ],
            'thinking' => ['type' => 'adaptive'],
            'outputConfig' => [
                'effort' => 'high',
                'format' => [
                    'type' => 'json_schema',
                    'schema' => $this->outputSchema($lineItems),
                ],
            ],
            // A policy decline is re-run on Anthropic's recommended substitute
            // server-side instead of coming back as an empty answer.
            'fallbacks' => 'default',
            'betas' => [self::FALLBACK_BETA],
        ];
    }

    /**
     * The one place the SDK is called. Tests replace this with a fake.
     *
     * With $onText the reply is streamed and every text delta is handed to it
     * as it arrives; the returned text is the same either way.
     *
     * @param  array<string, mixed>  $request
     * @param  callable(string): void|null  $onText
     * @return array{text: string, stop_reason: ?string, model: string, usage: array<string, mixed>}
     */
    protected function complete(array $request, ?callable $onText = null): array
    {
        $arguments = [
            'maxTokens' => $request['maxTokens'],
            'messages' => $request['messages'],
            'model' => $request['model'],
            'fallbacks' => $request['fallbacks'],
            'outputConfig' => $request['outputConfig'],
            'system' => $request['system'],
            'thinking' => $request['thinking'],
            'betas' => $request['betas'],
        ];

        if ($onText === null) {
            $message = $this->client()->beta->messages->create(...$arguments);

            $text = '';
            foreach ($message->content as $block) {
                if ($block->type === 'text') {
                    $text .= $block->text;
                }
            }

            return [
                'text' => $text,
                'stop_reason' => $message->stopReason,
                'model' => $message->model,
                'usage' => [
                    'input_tokens' => $message->usage->inputTokens,
                    'output_tokens' => $message->usage->outputTokens,
                    'cache_read_input_tokens' => $message->usage->cacheReadInputTokens,
                    'cache_creation_input_tokens' => $message->usage->cacheCreationInputTokens,
                ],
            ];
        }

        $stream = $this->client()->beta->messages->createStream(...$arguments);

        $text = '';
        $stopReason = null;
        $model = (string) $request['model'];
        $usage = [
            'input_tokens' => null,
            'output_tokens' => null,
            'cache_read_input_tokens' => null,
            'cache_creation_input_tokens' => null,
        ];

        try {
            foreach ($stream as $event) {
                if ($event instanceof BetaRawMessageStartEvent) {
                    $model = $event->message->model ?: $model;
                    $usage['input_tokens'] = $event->message->usage->inputTokens;
                    $usage['cache_read_input_tokens'] = $event->message->usage->cacheReadInputTokens;
                    $usage['cache_creation_input_tokens'] = $event->message->usage->cacheCreationInputTokens;
                } elseif ($event instanceof BetaRawContentBlockDeltaEvent) {
                    if ($event->delta instanceof BetaTextDelta && $event->delta->text !== '') {
                        $text .= $event->delta->text;
                        $onText($event->delta->text);
                    }
                } elseif ($event instanceof BetaRawMessageDeltaEvent) {
                    $stopReason = $event->delta->stopReason ?? $stopReason;
                    $usage['output_tokens'] = $event->usage->outputTokens;
                }
            }
        } finally {
            $stream->close();
        }

        return [
            'text' => $text,
            'stop_reason' => $stopReason,
            'model' => $model,
            'usage' => $usage,
        ];
    }

    /**
     * Text deltas in, catalog-resolved line items out: one $onLineItem call per
     * item the moment the model closes it, with the same normalisation and
     * floorplan quantities the finished draft gets.
     *
     * @param  callable(array<string, mixed>, int): void  $onLineItem
     * @return \Closure(string): void
     */
    protected function streamLineItems(callable $onLineItem, Collection $lineItems, ?array $floorplanData): \Closure
    {
        $parser = new LineItemStreamParser;
        $allowed = $lineItems->pluck('id')->map(fn ($id) => (int) $id)->all();
        $streamed = 0;

        return function (string $chunk) use ($parser, $onLineItem, $allowed, $floorplanData, &$streamed): void {
            foreach ($parser->push($chunk) as $raw) {
                $items = $this->normalizeLineItems([$raw], $allowed);

                if ($items !== [] && ! empty($floorplanData)) {
                    $items = $this->applyFloorplanQuantities($items, $floorplanData);
                }

                foreach ($items as $item) {
                    $onLineItem($item, $streamed++);
                }
            }
        };
    }

    protected function client(): Client
    {
        if ($this->client) {
            return $this->client;
        }

        $apiKey = (string) config('services.anthropic.api_key');
        if ($apiKey === '') {
            throw new \RuntimeException('Anthropic API key is not configured. Set ANTHROPIC_API_KEY in your .env file.');
        }

        return $this->client = new Client(apiKey: $apiKey);
    }

    /**
     * JSON schema for the draft. `line_item_id` is an enum of the catalog ids
     * offered in this request — the model cannot answer with any other id.
     */
    protected function outputSchema(Collection $lineItems): array
    {
        $ids = $lineItems->pluck('id')->map(fn ($id) => (int) $id)->values()->all();

        return [
            'type' => 'object',
            'properties' => [
                'reasoning' => [
                    'type' => 'string',
                    'description' => 'Two to four sentences: what the job is, which rooms, and the main assumptions behind the quantities.',
                ],
                'line_items' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'line_item_id' => ['type' => 'integer', 'enum' => $ids ?: [0]],
                            'quantity' => ['type' => 'number', 'description' => 'Quantity in the catalog unit for this item. 1 for lump-sum (no_unit) items.'],
                        ],
                        // No desc/notes: each line carries the catalog's own
                        // description and notes, never a rewording.
                        'required' => ['line_item_id', 'quantity'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['reasoning', 'line_items'],
            'additionalProperties' => false,
        ];
    }

    protected function systemPrompt(string $companyLine = ''): string
    {
        $companyLine = $companyLine !== '' ? $companyLine : 'a residential remodeling contractor';

        return "You are the estimator at {$companyLine}. You draft the line items for a new estimate from the company's own price catalog, for a human estimator to review and edit before anything is sent to a client.\n".<<<'PROMPT'

How estimates are built here: an estimate is split into sections, one per room or scope ("Kitchen", "Hall Bath", "Basement"). Each section lists catalog items with a quantity in the item's unit — pieces, sq.ft., li.ft., or no_unit for lump sums, which always have quantity 1. Follow the order of construction within a section: demolition, framing, plumbing rough, electrical rough, HVAC, insulation, drywall, tile prep, tile, finish carpentry, fixtures and trim, painting.

Rules:
- Use only catalog items from the list in the request, by id. If the job needs something the catalog does not have, say so in the reasoning and leave it out.
- Choose quantities from the dimensions given; if none are given, use the quantities the example sections used for a similar room and say in the reasoning that they are typical, not measured.
- Include the supporting work a real job needs: switches for new lights, patching after electrical, cement board under tile, GFCI protection and an exhaust fan in bathrooms, code-compliance items where the examples include them.
- Do not price anything. Costs come from the catalog after you answer.
- Follow the company's own estimating rules when the request lists them, and learn from the corrections its estimators made to earlier drafts of similar work: what they removed was not wanted, what they added was missing.
- Keep the reasoning short and concrete. Do not repeat the item list in it.
PROMPT;
    }

    /** "GS Construction, a residential remodeling contractor in Palatine, IL" — the company whose estimator the model plays. */
    protected function companyLine(int $vendorId): string
    {
        $vendor = Vendor::withoutGlobalScopes()->find($vendorId);
        if ($vendor === null) {
            return '';
        }

        $place = collect([$vendor->city, $vendor->state])->filter()->implode(', ');

        // The short name where there is one ("GS Construction"); the legal name is title-cased on save.
        $name = trim((string) ($vendor->short_name ?: $vendor->business_name));

        return $name.', a residential remodeling contractor'.($place !== '' ? " in {$place}" : '');
    }

    protected function userPrompt(string $inquiry, ?array $floorplanData, Collection $lineItems, array $examples, array $rules = [], array $corrections = []): string
    {
        $prompt = "CUSTOMER INQUIRY (contact details already removed):\n{$inquiry}\n\n";

        if ($rules !== []) {
            $prompt .= "COMPANY ESTIMATING RULES (written by this company's estimators; follow them):\n";
            foreach ($rules as $rule) {
                $prompt .= "- {$rule}\n";
            }
            $prompt .= "\n";
        }

        if ($floorplanData) {
            $prompt .= "FLOORPLAN DATA (measured from a room scan; size quantities from these numbers, per room where rooms are listed):\n"
                ."- floor_sqft sizes flooring and floor tile; wall_sqft sizes paint, drywall and wall tile; perimeter_ft sizes baseboard.\n"
                ."- window_casing_lf and door_casing_lf size casings; base_cabinet_lf, upper_cabinet_lf and tall_cabinet_lf are the cabinet runs in linear feet; countertop_lf is the counter run.\n"
                ."- appliances are what is there now: one hookup or install per unit that is replaced or moved.\n"
                .json_encode($floorplanData, JSON_PRETTY_PRINT)."\n\n";
        }

        $prompt .= "CATALOG ITEMS AVAILABLE FOR THIS DRAFT (id | name | category / sub-category | unit):\n";
        foreach ($lineItems as $item) {
            $prompt .= sprintf(
                "%d | %s | %s / %s | %s\n",
                $item->id,
                $item->name,
                $item->category,
                $item->sub_category ?? '-',
                $item->unit_type,
            );
        }

        if ($examples !== []) {
            $prompt .= "\nRECENT SECTIONS THE COMPANY ESTIMATED FOR SIMILAR ROOMS (name, then item × quantity unit):\n";
            foreach ($examples as $example) {
                $prompt .= "\n{$example['name']} — {$example['project']}, {$example['date']}:\n";
                foreach ($example['items'] as $item) {
                    $prompt .= sprintf("  - %s × %s %s\n", $item['name'], rtrim(rtrim(number_format((float) $item['quantity'], 2, '.', ''), '0'), '.'), $item['unit_type']);
                }
            }
        }

        if ($corrections !== []) {
            $prompt .= "\nWHAT THE ESTIMATORS CHANGED AFTER EARLIER AI DRAFTS OF SIMILAR WORK (do not repeat these mistakes):\n";
            foreach ($corrections as $correction) {
                $prompt .= "- {$correction['section']}: asked for \"{$correction['inquiry']}\" — {$correction['summary']}\n";
            }
        }

        return $prompt;
    }

    /**
     * @return list<string>
     */
    protected function activeRules(int $vendorId): array
    {
        return EstimateAiRule::query()->forVendor($vendorId)->active()->orderBy('id')->pluck('text')->map(fn ($t) => trim((string) $t))->filter()->values()->all();
    }

    /**
     * The corrections estimators made to kept AI drafts of the example
     * sections: the nearest past work, so the nearest past mistakes.
     *
     * @param  list<int>  $sectionIds
     * @return list<array{section: string, inquiry: string, summary: string}>
     */
    protected function pastCorrections(array $sectionIds, int $limit = 6): array
    {
        if ($sectionIds === []) {
            return [];
        }

        return EstimateAiDraft::query()
            ->whereIn('section_id', $sectionIds)
            ->where('status', '!=', EstimateAiDraft::DISCARDED)
            ->with('section')
            ->latest('id')
            ->get()
            ->map(function (EstimateAiDraft $draft) {
                $corrections = DraftCorrections::forDraft($draft);

                return DraftCorrections::isEmpty($corrections) ? null : [
                    'section' => $draft->section?->name ?: 'Section',
                    'inquiry' => Str::limit(static::redact(trim((string) $draft->inquiry)), 140),
                    'summary' => DraftCorrections::summarize($corrections),
                ];
            })
            ->filter()
            ->take($limit)
            ->values()
            ->all();
    }

    protected function getAvailableLineItems(int $vendorId): Collection
    {
        return LineItem::query()
            ->where(function ($query) use ($vendorId) {
                $query->where('belongs_to_vendor_id', $vendorId)
                    ->orWhereNull('belongs_to_vendor_id');
            })
            ->whereNull('deleted_at')
            ->orderBy('category')
            ->orderBy('name')
            ->get(['id', 'name', 'category', 'sub_category', 'unit_type', 'cost', 'desc']);
    }

    /**
     * The company's own past sections closest to the enquiry — by meaning
     * when embeddings are available, by word overlap otherwise — as the
     * examples the model drafts from.
     *
     * @param  list<int>  $excludeSectionIds
     * @return list<array{section_id: int, score: float, name: string, project: string, date: string, items: list<array{name: string, quantity: float, unit_type: string}>}>
     */
    protected function getExampleSections(int $vendorId, string $inquiry, int $limit = 4, array $excludeSectionIds = []): array
    {
        $retriever = $this->retriever ?? app(SectionRetriever::class);

        return collect($retriever->similar($vendorId, $inquiry, $limit, $excludeSectionIds))
            ->map(fn (array $row) => [
                'section_id' => (int) $row['section']->id,
                'score' => (float) $row['score'],
                'name' => $row['section']->name,
                'project' => $row['section']->estimate?->project?->project_name ?? 'past project',
                'date' => optional($row['section']->created_at)->format('M Y') ?? '',
                'items' => $row['section']->estimate_line_items
                    ->map(fn ($item) => ['name' => $item->name, 'quantity' => (float) $item->quantity, 'unit_type' => $item->unit_type])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }

    protected function applyFloorplanQuantities(array $items, array $floorplanData): array
    {
        $floorSqft = $floorplanData['floor_sqft'] ?? null;
        $wallSqft = $floorplanData['wall_sqft'] ?? null;
        $cementBoardSqft = $floorplanData['cement_board_sqft'] ?? null;
        $perimeter = $floorplanData['perimeter_ft'] ?? null;
        $windowCasing = $floorplanData['window_casing_lf'] ?? null;
        $doorCasing = $floorplanData['door_casing_lf'] ?? null;

        if (! $floorSqft && ! $wallSqft && ! $cementBoardSqft && ! $perimeter && ! $windowCasing && ! $doorCasing) {
            return $items;
        }

        foreach ($items as &$item) {
            $name = Str::lower((string) ($item['name'] ?? ''));
            $linear = ($item['unit_type'] ?? null) === 'li.ft.';
            if ($floorSqft && str_contains($name, 'floor tile')) {
                $item['quantity'] = (float) $floorSqft;
            } elseif ($wallSqft && str_contains($name, 'wall tile')) {
                $item['quantity'] = (float) $wallSqft;
            } elseif ($perimeter && $linear && str_contains($name, 'baseboard')) {
                // The scan measured the room's perimeter: that is the baseboard run.
                $item['quantity'] = (float) $perimeter;
            } elseif ($windowCasing && $linear && str_contains($name, 'window casing')) {
                $item['quantity'] = (float) $windowCasing;
            } elseif ($doorCasing && $linear && str_contains($name, 'door casing')) {
                $item['quantity'] = (float) $doorCasing;
            } elseif ($cementBoardSqft && str_contains($name, 'cement board')) {
                // Sheets cover ~32 sq.ft.; the catalog item is priced per piece.
                $item['quantity'] = ($item['unit_type'] ?? null) === 'pieces'
                    ? (float) ceil($cementBoardSqft / 32)
                    : round((float) $cementBoardSqft, 2);
            }
        }

        return $items;
    }

    /**
     * The catalog offered to the model. A company's whole catalog fits (a
     * few hundred short lines), and the model is the one who knows that a
     * wine cellar needs a header, glass and drywall as well as the HVAC the
     * enquiry happened to mention — so it is all offered. Only a catalog
     * larger than the cap is trimmed, and then the trades the enquiry names
     * come first.
     */
    protected function filterRelevantLineItems(Collection $lineItems, string $inquiry): Collection
    {
        if ($lineItems->count() <= self::CATALOG_CAP) {
            return $lineItems->values();
        }

        $categories = $this->categoriesNamedIn($inquiry);
        [$named, $rest] = $lineItems->partition(fn ($item) => in_array($item->category, $categories, true));

        return $named->concat($rest)->take(self::CATALOG_CAP)->values();
    }

    /**
     * Trade categories the words of an enquiry point at, for ordering a
     * catalog too large to offer whole.
     *
     * @return list<string>
     */
    protected function categoriesNamedIn(string $inquiry): array
    {
        $keywords = Str::lower($inquiry);

        $categoryMap = [
            'kitchen' => ['Demolition', 'Plumbing', 'Electrical', 'Carpentry', 'Drywall', 'Tiles', 'Flooring', 'Painting', 'Services', 'HVAC'],
            'basement' => ['Demolition', 'Framing', 'Insulation', 'Drywall', 'Electrical', 'Plumbing', 'HVAC', 'Flooring', 'Painting', 'Carpentry', 'Services'],
            'shower' => ['Plumbing', 'Tiles', 'Glass', 'Demolition'],
            'tile' => ['Tiles', 'Drywall'],
            'tub' => ['Plumbing', 'Tiles'],
            'bath' => ['Demolition', 'Plumbing', 'Electrical', 'Tiles', 'Drywall', 'Services', 'Painting', 'Glass', 'Carpentry'],
            'vanity' => ['Plumbing', 'Carpentry', 'Services'],
            'electric' => ['Electrical'],
            'light' => ['Electrical'],
            'exhaust' => ['Electrical', 'HVAC'],
            'fan' => ['Electrical', 'HVAC'],
            'paint' => ['Painting'],
            'drywall' => ['Drywall'],
            'insulation' => ['Insulation'],
            'floor' => ['Tiles', 'Flooring', 'Hardwood', 'Carpet'],
            'fram' => ['Framing', 'Carpentry'],
            'header' => ['Framing', 'Carpentry'],
            'window' => ['Carpentry', 'Framing', 'Siding', 'Windows'],
            'door' => ['Carpentry'],
            'cabinet' => ['Carpentry', 'Services'],
            'counter' => ['Carpentry', 'Services', 'Stone Fabricator'],
            'glass' => ['Glass'],
            'glaz' => ['Glass'],
            'hvac' => ['HVAC'],
            'plumb' => ['Plumbing'],
            'demo' => ['Demolition', 'Demo'],
            'roof' => ['Roofing'],
            'siding' => ['Siding'],
        ];

        $categories = [];
        foreach ($categoryMap as $keyword => $mapped) {
            if (str_contains($keywords, $keyword)) {
                $categories = array_merge($categories, $mapped);
            }
        }

        return array_values(array_unique($categories));
    }

    protected function parseResponse(string $content): array
    {
        $json = preg_match('/\{[\s\S]*\}/', $content, $matches) ? $matches[0] : $content;
        $data = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($data)) {
            Log::channel('estimate_ai')->warning('Failed to parse AI response', [
                'error' => json_last_error_msg(),
                'raw_content' => $content,
            ]);

            return [
                'success' => false,
                'line_items' => [],
                'reasoning' => '',
                'error' => 'Failed to parse the draft: '.json_last_error_msg(),
            ];
        }

        return [
            'success' => true,
            'line_items' => is_array($data['line_items'] ?? null) ? $data['line_items'] : [],
            'reasoning' => (string) ($data['reasoning'] ?? ''),
        ];
    }

    /**
     * Re-read every item from the catalog: the name and price shown are the
     * catalog's, quantities are numeric, and an id outside the offered set —
     * impossible under the schema, but checked anyway — is dropped.
     *
     * @param  array<int, int>|null  $allowedIds
     */
    protected function normalizeLineItems(array $items, ?array $allowedIds = null): array
    {
        $ids = collect($items)->pluck('line_item_id')->filter()->map(fn ($id) => (int) $id)->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }

        $lineItems = LineItem::query()->whereIn('id', $ids)->get(['id', 'name', 'category', 'sub_category', 'cost', 'unit_type', 'desc', 'notes'])->keyBy('id');

        $normalized = [];
        $dropped = [];
        foreach ($items as $item) {
            $lineItemId = (int) ($item['line_item_id'] ?? 0);
            $lineItem = $lineItems->get($lineItemId);

            if (! $lineItem || ($allowedIds !== null && ! in_array($lineItemId, $allowedIds, true))) {
                $dropped[] = $lineItemId;

                continue;
            }

            $quantity = is_numeric($item['quantity'] ?? null) ? (float) $item['quantity'] : 1.0;
            if ($lineItem->unit_type === 'no_unit' || $quantity <= 0) {
                $quantity = 1.0;
            }

            $normalized[] = [
                'line_item_id' => $lineItem->id,
                'name' => $lineItem->name,
                'category' => $lineItem->category,
                'sub_category' => $lineItem->sub_category,
                'quantity' => $quantity,
                'cost' => (float) $lineItem->cost,
                'unit_type' => $lineItem->unit_type,
                // Always the catalog's text: the model is not asked for any.
                'desc' => $lineItem->desc,
                'notes' => $lineItem->notes,
            ];
        }

        if ($dropped !== []) {
            Log::channel('estimate_ai')->warning('Dropped line items outside the offered catalog', ['line_item_ids' => $dropped]);
        }

        return $normalized;
    }

    protected function friendlyError(\Throwable $e): string
    {
        return match (true) {
            $e instanceof AuthenticationException => 'The Anthropic API key was rejected. Check ANTHROPIC_API_KEY.',
            $e instanceof RateLimitException => 'Claude is rate-limited right now. Wait a moment and try again.',
            $e instanceof APIConnectionException => 'Could not reach Claude. Check the connection and try again.',
            $e instanceof APIStatusException => 'Claude returned an error ('.($e->type?->value ?? 'unknown').'). Try again.',
            default => $e->getMessage(),
        };
    }

    /** @return array{success: false, line_items: array, reasoning: string, error: string} */
    protected function failure(string $requestId, string $message, array $meta = []): array
    {
        Log::channel('estimate_ai')->warning('Estimate generation returned no draft', ['request_id' => $requestId, 'error' => $message]);

        return ['success' => false, 'line_items' => [], 'reasoning' => '', 'error' => $message, 'meta' => $meta + ['request_id' => $requestId]];
    }
}
