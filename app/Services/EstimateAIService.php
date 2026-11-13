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
use App\Exceptions\Estimates\DraftStopped;
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
use App\Services\EstimateAI\SectionStreamParser;
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

    /** Past sections of the same name shown with each section of a whole-estimate draft. */
    public const NAMESAKES_PER_SECTION = 3;

    /** Past sections shown for the job as a whole, besides each section's namesakes. */
    public const JOB_EXAMPLES = 4;

    /** The key the model answers with for a section that is not one of the listed ones. */
    public const NEW_SECTION = 'NEW';

    /** What a room scan measures for one room — and so all that is kept of it. */
    protected const ROOM_NUMBERS = [
        'floor_sqft', 'wall_sqft', 'ceiling_height_ft', 'ceiling_height_max_ft', 'perimeter_ft', 'window_casing_lf', 'door_casing_lf',
        'window_count', 'window_area_sqft', 'door_count',
        'cabinet_count', 'base_cabinet_lf', 'upper_cabinet_lf', 'tall_cabinet_lf', 'countertop_lf',
    ];

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
     * Draft a whole estimate in one call, section by section.
     *
     * $sections are the sections the draft may fill, each with a short key
     * and, when it came from a room scan, that room's own measurements. With
     * $listed they are the estimator's own list: the model fills those and
     * starts another only for work that belongs to none of them. Without it
     * they are merely what is on the estimate already, and the model splits
     * the work into sections itself.
     *
     * The reply is read as it streams: $on['section'] when a section opens,
     * $on['line_item'] for each catalog-resolved item (sized from its
     * section's own measurements), $on['section_end'] when a section closes
     * with its final scope, and $on['event'] on every stream event, thinking
     * included — the caller's heartbeat. Each is called with the section's
     * header (key, name, scope, on_estimate, measurements) and its position.
     *
     * @param  list<array{key: string, name: string, on_estimate?: bool, rooms?: list<array<string, mixed>>}>  $sections
     * @param  array{section?: callable(array<string, mixed>, int): void, line_item?: callable(array<string, mixed>, int): void, section_end?: callable(array<string, mixed>, int): void, event?: callable(): void}  $on
     * @param  list<int>  $excludeSectionIds  past sections never shown as examples
     * @param  bool  $onlyListed  draft the listed sections and no other (a single section drafted again)
     * @return array{success: bool, reasoning: string, sections: list<array<string, mixed>>, error?: string, meta: array<string, mixed>}
     */
    public function generateSections(string $inquiry, array $sections = [], bool $listed = true, ?array $floorplanData = null, int $vendorId = 1, array $on = [], array $excludeSectionIds = [], bool $onlyListed = false): array
    {
        $requestId = uniqid('est_ai_');
        $redactedInquiry = static::redact($inquiry);
        $floorplanData = static::floorplanMetrics($floorplanData);
        $plan = static::sectionPlan($sections);
        $listed = $listed && $plan !== [];
        // Only the listed sections, nothing new beside them: one section of an
        // estimate drafted again while the rest stands.
        $onlyListed = $onlyListed && $listed;
        $meta = ['request_id' => $requestId, 'model' => $this->model, 'usage' => null, 'stop_reason' => null, 'example_section_ids' => [], 'catalog_count' => 0, 'floorplan' => $floorplanData];
        $drafted = [];

        Log::channel('estimate_ai')->info('Estimate sections generation started', [
            'request_id' => $requestId,
            'vendor_id' => $vendorId,
            'model' => $this->model,
            'inquiry' => $redactedInquiry,
            'redacted' => $redactedInquiry !== $inquiry,
            'listed' => $listed,
            'sections' => $plan,
            'floorplan_data' => $floorplanData,
        ]);

        try {
            $availableLineItems = $this->filterRelevantLineItems($this->getAvailableLineItems($vendorId), $redactedInquiry);
            $examples = $this->getExampleSectionsForPlan($vendorId, $redactedInquiry, $plan, $excludeSectionIds);
            // A past section can stand under two sections (a sitting room and a living room both find the family rooms).
            $exampleIds = array_values(array_unique([...array_column(array_merge(...array_values($examples['by_section'])), 'section_id'), ...array_column($examples['job'], 'section_id')]));
            $rules = $this->activeRules($vendorId);
            $corrections = $this->pastCorrections($exampleIds, 8);
            $meta['example_section_ids'] = $exampleIds;
            $meta['catalog_count'] = $availableLineItems->count();

            $request = $this->buildSectionsRequest($redactedInquiry, $plan, $listed, $floorplanData, $availableLineItems, $examples, $rules, $corrections, $this->companyLine($vendorId), $onlyListed);

            Log::channel('estimate_ai')->debug('Sections request built', [
                'request_id' => $requestId,
                'catalog_count' => $availableLineItems->count(),
                'section_examples' => collect($examples['by_section'])->map(fn (array $rows) => collect($rows)->map(fn ($e) => $e['name'].' #'.$e['section_id'])->all())->all(),
                'job_examples' => collect($examples['job'])->map(fn ($e) => $e['name'].' ('.$e['score'].')')->all(),
                'rules' => count($rules),
                'corrections' => count($corrections),
            ]);

            // Always streamed: a whole estimate is a long reply, and the rows
            // are handed over as they close.
            $completion = $this->complete($request, $this->streamSections($on, $availableLineItems, $plan, $drafted), $on['event'] ?? null);
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

            $data = json_decode($completion['text'], true);
            $reasoning = is_array($data) ? trim((string) ($data['reasoning'] ?? '')) : '';
            $error = match (true) {
                $completion['stop_reason'] === 'refusal' => 'Claude declined to draft this estimate. Reword the description and try again.',
                $completion['stop_reason'] === 'max_tokens' => 'The draft was cut off before it finished. Draft fewer sections at once, or describe the work more briefly.',
                ! is_array($data) => 'The draft came back incomplete. Try again.',
                default => null,
            };

            Log::channel('estimate_ai')->info('Estimate sections generation completed', [
                'request_id' => $requestId,
                'success' => $error === null,
                'error' => $error,
                'reasoning' => $reasoning,
                'sections' => collect($drafted)->map(fn (array $section) => ['key' => $section['key'], 'name' => $section['name'], 'line_items' => count($section['line_items'])])->values()->all(),
            ]);

            return array_filter([
                'success' => $error === null,
                'reasoning' => $reasoning,
                'sections' => array_values($drafted),
                'error' => $error,
                'meta' => $meta,
            ], fn ($value) => $value !== null);
        } catch (\Throwable $e) {
            // The caller stopped the draft from inside a callback: that is its business, not a failure here.
            if ($e instanceof DraftStopped) {
                throw $e;
            }

            Log::channel('estimate_ai')->error('Estimate sections generation failed', [
                'request_id' => $requestId,
                'error' => $e->getMessage(),
                'exception_class' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);
            Log::error('EstimateAIService error: '.$e->getMessage());

            return ['success' => false, 'reasoning' => '', 'sections' => array_values($drafted), 'error' => $this->friendlyError($e), 'meta' => $meta];
        }
    }

    /**
     * The sections a whole-estimate draft may fill, as the model is told
     * about them: the caller's key, the name (contact details stripped like
     * everything else) and the room measurements that are theirs.
     *
     * @param  list<array<string, mixed>>  $sections
     * @return list<array{key: string, name: string, on_estimate: bool, measurements: ?array<string, mixed>, note: ?string}>
     */
    public static function sectionPlan(array $sections): array
    {
        $plan = [];

        foreach ($sections as $section) {
            $key = (string) ($section['key'] ?? '');
            $name = Str::limit(trim(static::redact((string) ($section['name'] ?? ''))), 60, '');

            if ($name === '' || ! preg_match('/^[A-Za-z0-9_-]{1,16}$/', $key) || $key === self::NEW_SECTION || isset($plan[$key])) {
                continue;
            }

            $note = Str::limit(trim(static::redact((string) ($section['note'] ?? ''))), 300, '');

            $plan[$key] = [
                'key' => $key,
                'name' => $name,
                'on_estimate' => (bool) ($section['on_estimate'] ?? false),
                'measurements' => static::sectionMeasurements((array) ($section['rooms'] ?? [])),
                // What the estimator typed for this section alone ("tile only, keep the cabinets").
                'note' => $note !== '' ? $note : null,
            ];
        }

        return array_values($plan);
    }

    /**
     * One scanned room, reduced to what sizes an estimate: its name as
     * scanned, its dimensions, its numbers and what stands in it.
     *
     * @param  array<string, mixed>  $room
     * @return array<string, mixed>
     */
    public static function roomMetrics(array $room): array
    {
        $appliances = array_filter(array_map('intval', array_filter((array) ($room['appliances'] ?? []), 'is_numeric')));

        return array_filter([
            'name' => filled($room['name'] ?? null) ? Str::limit((string) $room['name'], 40, '') : null,
            'dimensions' => isset($room['dimensions']) ? Str::limit((string) $room['dimensions'], 40, '') : null,
        ] + array_map(fn ($v) => round((float) $v, 2), array_filter(
            array_intersect_key($room, array_flip(self::ROOM_NUMBERS)),
            fn ($v) => is_numeric($v),
        )) + ['appliances' => $appliances], fn ($v) => $v !== null && $v !== []);
    }

    /**
     * The measurements of one estimate section: its room's own, or several
     * rooms added up when the estimator put them in one section (a living
     * room scanned in two parts).
     *
     * @param  list<array<string, mixed>>  $rooms
     * @return array<string, mixed>|null
     */
    public static function sectionMeasurements(array $rooms): ?array
    {
        $rooms = array_values(array_filter(array_map(fn ($room) => is_array($room) ? static::roomMetrics($room) : [], $rooms)));

        if ($rooms === []) {
            return null;
        }

        $scannedAs = implode(' + ', array_filter(array_column($rooms, 'name')));

        if (count($rooms) === 1) {
            return array_filter(['scanned_as' => $scannedAs] + array_diff_key($rooms[0], ['name' => true]), fn ($v) => $v !== '' && $v !== []);
        }

        $measurements = ['scanned_as' => $scannedAs];
        foreach (self::ROOM_NUMBERS as $key) {
            $values = array_filter(array_column($rooms, $key), 'is_numeric');
            if ($values !== []) {
                $measurements[$key] = str_starts_with($key, 'ceiling_height') ? max($values) : round(array_sum($values), 2);
            }
        }

        $appliances = [];
        foreach ($rooms as $room) {
            foreach ($room['appliances'] ?? [] as $kind => $count) {
                $appliances[$kind] = ($appliances[$kind] ?? 0) + (int) $count;
            }
        }

        return array_filter($measurements + ['appliances' => $appliances], fn ($v) => $v !== '' && $v !== []);
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
            ->map(fn (array $r) => static::roomMetrics($r))
            ->values()
            ->all();
        if ($rooms !== []) {
            $metrics['rooms'] = $rooms;
        }

        $appliances = array_filter(array_map('intval', array_filter((array) ($floorplanData['appliances'] ?? []), 'is_numeric')));
        if ($appliances !== []) {
            $metrics['appliances'] = $appliances;
        }

        // Several layouts (two floors, a basement, 2026-09-30): the totals
        // above are summed across them; this keeps each one's own numbers.
        $floors = collect((array) ($floorplanData['floors'] ?? []))
            ->filter(fn ($f) => is_array($f) && filled($f['label'] ?? null))
            ->map(fn (array $f) => ['label' => Str::limit((string) $f['label'], 40, '')] + array_map(
                fn ($v) => (float) $v,
                array_filter(array_intersect_key($f, array_flip($numbers)), fn ($v) => is_numeric($v)),
            ))
            ->values()
            ->all();
        if (count($floors) > 1) {
            $metrics['floors'] = $floors;
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
     * The request for a whole estimate drafted section by section. More room
     * to answer than a single section gets, and the thinking is summarised
     * rather than withheld so the stream is never silent for long — the
     * caller's heartbeat rides on its events.
     *
     * @param  list<array<string, mixed>>  $plan
     * @return array<string, mixed>
     */
    protected function buildSectionsRequest(string $inquiry, array $plan, bool $listed, ?array $floorplanData, Collection $lineItems, array $examples, array $rules = [], array $corrections = [], string $companyLine = '', bool $onlyListed = false): array
    {
        return [
            'model' => $this->model,
            'maxTokens' => 32000,
            'system' => [
                ['type' => 'text', 'text' => $this->systemPrompt($companyLine, wholeEstimate: true)],
            ],
            'messages' => [
                ['role' => 'user', 'content' => $this->sectionsPrompt($inquiry, $plan, $listed, $floorplanData, $lineItems, $examples, $rules, $corrections, $onlyListed)],
            ],
            'thinking' => ['type' => 'adaptive', 'display' => 'summarized'],
            'outputConfig' => [
                'effort' => 'high',
                'format' => [
                    'type' => 'json_schema',
                    'schema' => $this->sectionsOutputSchema($lineItems, $plan, $onlyListed),
                ],
            ],
            'fallbacks' => 'default',
            'betas' => [self::FALLBACK_BETA],
        ];
    }

    /**
     * The one place the SDK is called. Tests replace this with a fake.
     *
     * With $onText the reply is streamed and every text delta is handed to it
     * as it arrives; the returned text is the same either way. $onEvent is
     * called on every stream event, text or not.
     *
     * @param  array<string, mixed>  $request
     * @param  callable(string): void|null  $onText
     * @param  callable(): void|null  $onEvent
     * @return array{text: string, stop_reason: ?string, model: string, usage: array<string, mixed>}
     */
    protected function complete(array $request, ?callable $onText = null, ?callable $onEvent = null): array
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
                if ($onEvent !== null) {
                    $onEvent();
                }

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

    /**
     * streamLineItems() for a whole estimate: text deltas in; section
     * openings, catalog-resolved line items and section closings out, each
     * handed to the caller's callbacks and gathered into $drafted.
     *
     * @param  array<string, callable>  $on
     * @param  list<array<string, mixed>>  $plan
     * @param  array<int, array<string, mixed>>  $drafted  filled as the reply arrives, keyed by the section's position
     * @return \Closure(string): void
     */
    protected function streamSections(array $on, Collection $lineItems, array $plan, array &$drafted): \Closure
    {
        $parser = new SectionStreamParser;
        $allowed = $lineItems->pluck('id')->map(fn ($id) => (int) $id)->all();
        $notify = fn (string $kind, array $payload, int $index) => isset($on[$kind]) ? $on[$kind]($payload, $index) : null;

        return function (string $chunk) use ($parser, $allowed, $plan, $notify, &$drafted): void {
            foreach ($parser->push($chunk) as $event) {
                $index = (int) $event['index'];

                if ($event['event'] === 'item') {
                    if (! isset($drafted[$index])) {
                        continue;
                    }

                    $header = array_diff_key($drafted[$index], ['line_items' => true]);

                    // The quantities stay as drafted: Claude sizes each section
                    // from that section's own measurements, and knows when work
                    // covers only part of a room. (2026-11-13: forcing floor
                    // tile to the room's floor turned a ~40 sq.ft. pantry
                    // allowance into the whole 286 sq.ft. kitchen.)
                    foreach ($this->normalizeLineItems([$event['item']], $allowed) as $item) {
                        $drafted[$index]['line_items'][] = $item;
                        $notify('line_item', $item + ['section' => $header], $index);
                    }

                    continue;
                }

                $header = $this->sectionHeader($event, $plan, $index);
                $opening = ! isset($drafted[$index]);
                $drafted[$index] = $header + ['line_items' => $drafted[$index]['line_items'] ?? []];

                if ($opening) {
                    $notify('section', $header, $index);
                }

                if ($event['event'] === 'section_end') {
                    $notify('section_end', $header, $index);
                }
            }
        };
    }

    /**
     * Which section the model means: a listed one by its key (or, failing
     * that, by its name), keeping the listed name and measurements; anything
     * else is a section of the model's own.
     *
     * @param  array<string, mixed>  $event
     * @param  list<array<string, mixed>>  $plan
     * @return array{key: string, name: string, scope: string, on_estimate: bool, measurements: ?array<string, mixed>}
     */
    protected function sectionHeader(array $event, array $plan, int $index): array
    {
        $name = Str::limit(trim((string) ($event['name'] ?? '')), 60, '');
        $scope = Str::limit(trim((string) ($event['scope'] ?? '')), 600);

        $listed = collect($plan)->firstWhere('key', (string) ($event['key'] ?? ''))
            ?? ($name !== '' ? collect($plan)->first(fn (array $section) => Str::lower($section['name']) === Str::lower($name)) : null);

        if ($listed !== null) {
            return ['key' => $listed['key'], 'name' => $listed['name'], 'scope' => $scope, 'on_estimate' => (bool) $listed['on_estimate'], 'measurements' => $listed['measurements'] ?? null];
        }

        return ['key' => self::NEW_SECTION, 'name' => $name !== '' ? $name : 'Section '.($index + 1), 'scope' => $scope, 'on_estimate' => false, 'measurements' => null];
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

    /**
     * JSON schema for a whole-estimate draft: sections, each with its own
     * line items. A section's key is an enum of the listed sections' keys
     * plus NEW, so the model cannot point at a section that is not there;
     * the header comes before the items so each row can be placed as it
     * streams in.
     *
     * @param  list<array<string, mixed>>  $plan
     */
    protected function sectionsOutputSchema(Collection $lineItems, array $plan, bool $onlyListed = false): array
    {
        $lineItemsSchema = $this->outputSchema($lineItems)['properties']['line_items'];

        return [
            'type' => 'object',
            'properties' => [
                'reasoning' => [
                    'type' => 'string',
                    'description' => 'Two to four sentences on the job as a whole: what it is and the main assumptions. The sections say the rest.',
                ],
                'sections' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            // No NEW when only the listed sections are being drafted: there is no key to answer with for another.
                            'key' => ['type' => 'string', 'enum' => $onlyListed ? array_column($plan, 'key') : [...array_column($plan, 'key'), self::NEW_SECTION]],
                            'name' => ['type' => 'string', 'description' => 'The section name: the listed name for a listed key; for NEW, a short name the way this company names its sections.'],
                            'scope' => ['type' => 'string', 'description' => 'One or two sentences: what this section covers and what its quantities assume.'],
                            'line_items' => $lineItemsSchema,
                        ],
                        'required' => ['key', 'name', 'scope', 'line_items'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['reasoning', 'sections'],
            'additionalProperties' => false,
        ];
    }

    protected function systemPrompt(string $companyLine = '', bool $wholeEstimate = false): string
    {
        $companyLine = $companyLine !== '' ? $companyLine : 'a residential remodeling contractor';

        return "You are the estimator at {$companyLine}. You draft the line items for a new estimate from the company's own price catalog, for a human estimator to review and edit before anything is sent to a client.\n".($wholeEstimate ? <<<'PROMPT'

This draft covers the whole estimate, not one section. Answer section by section. A section holds the work that happens in that room or scope, sized from that section's own measurements — never from the whole plan's totals — so the client can read what each room costs. Work asked for across the house ("new flooring throughout", "paint everything") goes into each room's section at that room's size, unless this company's past estimates show it estimating that trade as a section of its own. Something the whole job needs once (a dumpster, a permit, floor protection) appears once, in the section where the past estimates put it.

Each section comes with this company's own past sections of the same or a similar name. They are its checklist for that room: when the inquiry asks for new work there ("new kitchen", "remodel the bath", "new wet bar"), carry the items those sections carry for that kind of work — demolition, framing, plumbing and electrical rough-ins, drywall, installation, finishes and the supporting items — sized to this room. An enquiry is usually a few words; "new kitchen" means the kitchen this company normally builds, not one line. Leave out only what the inquiry rules out or what the work in that room clearly does not need (a floor refinish needs no cabinets).

A section may also carry the estimator's own note. It is their word on what that section is to include, written knowing the inquiry: where the two read differently, the note decides for that section.

PROMPT : '').<<<'PROMPT'

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
        $prompt = "CUSTOMER INQUIRY (contact details already removed):\n{$inquiry}\n\n".$this->rulesBlock($rules);

        if ($floorplanData) {
            $prompt .= $this->floorplanBlock($floorplanData);
        }

        return $prompt.$this->catalogBlock($lineItems).$this->examplesBlock($examples).$this->correctionsBlock($corrections);
    }

    /**
     * userPrompt() for a whole estimate: the same enquiry, rules, catalog,
     * examples and corrections, with the sections the draft is to fill —
     * each with its own room's measurements — in place of one flat scan.
     *
     * @param  list<array<string, mixed>>  $plan
     */
    /**
     * @param  list<array<string, mixed>>  $plan
     * @param  array{by_section: array<string, list<array<string, mixed>>>, job: list<array<string, mixed>>}  $examples
     */
    protected function sectionsPrompt(string $inquiry, array $plan, bool $listed, ?array $floorplanData, Collection $lineItems, array $examples, array $rules = [], array $corrections = [], bool $onlyListed = false): string
    {
        $measured = collect($plan)->contains(fn (array $section) => ! empty($section['measurements']));
        $prompt = "CUSTOMER INQUIRY (contact details already removed):\n{$inquiry}\n\n".$this->rulesBlock($rules);

        if ($listed) {
            $prompt .= "SECTIONS OF THIS ESTIMATE (the estimator's own list; answer with each section's key):\n"
                ."- Draft every section below that the enquiry gives work for. Leave out a section it gives no work for rather than padding it.\n"
                .($measured
                    ? "- A section's measurements come from a scan of that room; size its items from them: floor_sqft sizes flooring and floor tile; wall_sqft sizes paint, drywall and wall tile; perimeter_ft sizes baseboard; window_casing_lf and door_casing_lf size casings; base_cabinet_lf, upper_cabinet_lf and tall_cabinet_lf are the cabinet runs and countertop_lf the counter run, in linear feet; appliances are what stands there now — one hookup or install per unit that is replaced or moved.\n"
                    : '')
                ."- Under each section are this company's own past sections of the same or a similar name: that is how it estimates that room. Draft the section the way they were drafted for the work asked for there — see the system prompt.\n"
                .($onlyListed
                    ? "- Only what is listed below is being drafted now: the rest of this estimate is drafted already. Draft the work of the inquiry that belongs here, and leave everything that belongs to another room or section out.\n\n"
                    : '- Use key '.self::NEW_SECTION." with a name of your own only for work that belongs to none of the sections below.\n\n");
        } else {
            $prompt .= "SECTIONS OF THIS ESTIMATE:\n"
                ."- Split the work into sections the way this company's past estimates do — one per room or scope (\"Kitchen\", \"Hall Bath\", \"Painting\"). Give each one key ".self::NEW_SECTION." and a short name.\n"
                .($plan !== [] ? "- These sections are on the estimate already. Use a section's key to add to it rather than starting a second one for the same room:\n\n" : "\n");
        }

        foreach ($plan as $section) {
            $prompt .= "{$section['key']} — {$section['name']}".($section['on_estimate'] ? ' (already on the estimate: add to it)' : '')."\n";
            if (! empty($section['measurements'])) {
                $prompt .= json_encode($section['measurements'])."\n";
            }

            if (! empty($section['note'])) {
                $prompt .= "  The estimator's note for this section: {$section['note']}\n";
            }

            $namesakes = $examples['by_section'][$section['key']] ?? [];
            $prompt .= $namesakes === []
                ? "  (no past section of this name at this company)\n"
                : "  This company's past \"{$section['name']}\" sections:\n".implode('', array_map(fn (array $example) => '  - '.$this->exampleLine($example)."\n", $namesakes));
            $prompt .= "\n";
        }

        if ($floorplanData && $measured) {
            // The rooms are with their sections above; here only what they add up to.
            $prompt .= "WHOLE-PLAN MEASUREMENTS (every scanned room together — only for work that is not tied to one section):\n"
                .json_encode(array_diff_key($floorplanData, ['rooms' => true]), JSON_PRETTY_PRINT)."\n\n";
        } elseif ($floorplanData) {
            $prompt .= $this->floorplanBlock($floorplanData);
        }

        $job = $examples['job'] === [] ? '' : "\nOTHER PAST SECTIONS CLOSE TO THIS JOB (for work that is in none of the sections above):\n"
            .implode('', array_map(fn (array $example) => '- '.$this->exampleLine($example)."\n", $examples['job']));

        return $prompt.$this->catalogBlock($lineItems).$job.$this->correctionsBlock($corrections);
    }

    /**
     * One past section on one line: "Kitchen — Smith remodel, Oct 2026: Demo × 1; Floor Tile × 45 sq.ft.; …".
     *
     * @param  array<string, mixed>  $example
     */
    protected function exampleLine(array $example): string
    {
        $items = array_map(function (array $item) {
            $quantity = rtrim(rtrim(number_format((float) $item['quantity'], 2, '.', ''), '0'), '.');

            return $item['unit_type'] === 'no_unit' ? "{$item['name']} × 1" : "{$item['name']} × {$quantity} {$item['unit_type']}";
        }, $example['items']);

        return "{$example['name']} — {$example['project']}, {$example['date']}: ".implode('; ', $items);
    }

    /** @param  list<string>  $rules */
    protected function rulesBlock(array $rules): string
    {
        if ($rules === []) {
            return '';
        }

        $block = "COMPANY ESTIMATING RULES (written by this company's estimators; follow them):\n";
        foreach ($rules as $rule) {
            $block .= "- {$rule}\n";
        }

        return $block."\n";
    }

    protected function floorplanBlock(array $floorplanData): string
    {
        return "FLOORPLAN DATA (measured from a room scan; size quantities from these numbers, per room where rooms are listed):\n"
            ."- floor_sqft sizes flooring and floor tile; wall_sqft sizes paint, drywall and wall tile; perimeter_ft sizes baseboard.\n"
            ."- window_casing_lf and door_casing_lf size casings; base_cabinet_lf, upper_cabinet_lf and tall_cabinet_lf are the cabinet runs in linear feet; countertop_lf is the counter run.\n"
            ."- appliances are what is there now: one hookup or install per unit that is replaced or moved.\n"
            .(isset($floorplanData['floors'])
                ? "- This job has several layouts (floors): the totals are summed across all of them, room names start with their floor, and floors gives each one's own numbers. Size work on one floor from that floor's numbers.\n"
                : '')
            .json_encode($floorplanData, JSON_PRETTY_PRINT)."\n\n";
    }

    protected function catalogBlock(Collection $lineItems): string
    {
        $block = "CATALOG ITEMS AVAILABLE FOR THIS DRAFT (id | name | category / sub-category | unit):\n";
        foreach ($lineItems as $item) {
            $block .= sprintf(
                "%d | %s | %s / %s | %s\n",
                $item->id,
                $item->name,
                $item->category,
                $item->sub_category ?? '-',
                $item->unit_type,
            );
        }

        return $block;
    }

    protected function examplesBlock(array $examples): string
    {
        if ($examples === []) {
            return '';
        }

        $block = "\nRECENT SECTIONS THE COMPANY ESTIMATED FOR SIMILAR ROOMS (name, then item × quantity unit):\n";
        foreach ($examples as $example) {
            $block .= "\n{$example['name']} — {$example['project']}, {$example['date']}:\n";
            foreach ($example['items'] as $item) {
                $block .= sprintf("  - %s × %s %s\n", $item['name'], rtrim(rtrim(number_format((float) $item['quantity'], 2, '.', ''), '0'), '.'), $item['unit_type']);
            }
        }

        return $block;
    }

    protected function correctionsBlock(array $corrections): string
    {
        if ($corrections === []) {
            return '';
        }

        $block = "\nWHAT THE ESTIMATORS CHANGED AFTER EARLIER AI DRAFTS OF SIMILAR WORK (do not repeat these mistakes):\n";
        foreach ($corrections as $correction) {
            $block .= "- {$correction['section']}: asked for \"{$correction['inquiry']}\" — {$correction['summary']}\n";
        }

        return $block;
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
            ->map(fn (array $row) => $this->exampleFrom($row))
            ->values()
            ->all();
    }

    /**
     * The examples for a whole-estimate draft: for each section, this
     * company's own past sections of the same or a similar name — how it
     * estimates that room — and, for the job as a whole (work the model puts
     * in a section of its own), the past sections closest to the enquiry.
     * Each past section is shown once.
     *
     * @param  list<array<string, mixed>>  $plan
     * @param  list<int>  $excludeSectionIds
     * @return array{by_section: array<string, list<array<string, mixed>>>, job: list<array<string, mixed>>}
     */
    protected function getExampleSectionsForPlan(int $vendorId, string $inquiry, array $plan, array $excludeSectionIds = []): array
    {
        $retriever = $this->retriever ?? app(SectionRetriever::class);
        $names = collect($plan)->mapWithKeys(fn (array $section) => [$section['key'] => $section['name']])->all();

        $bySection = collect($names === [] ? [] : $retriever->namesakes($vendorId, $names, $inquiry, self::NAMESAKES_PER_SECTION, $excludeSectionIds))
            ->map(fn (array $rows) => array_map(fn (array $row) => $this->exampleFrom($row), $rows))
            ->filter()
            ->all();

        $shown = collect($bySection)->flatten(1)->pluck('section_id')->all();
        $job = collect($this->getExampleSections($vendorId, $inquiry, self::JOB_EXAMPLES + count($shown), $excludeSectionIds))
            ->reject(fn (array $example) => in_array($example['section_id'], $shown, true))
            ->take(self::JOB_EXAMPLES)
            ->values()
            ->all();

        return ['by_section' => $bySection, 'job' => $job];
    }

    /**
     * @param  array{section: EstimateSection, score: float}  $row
     * @return array{section_id: int, score: float, name: string, project: string, date: string, items: list<array{name: string, quantity: float, unit_type: string}>}
     */
    protected function exampleFrom(array $row): array
    {
        return [
            'section_id' => (int) $row['section']->id,
            'score' => (float) $row['score'],
            'name' => $row['section']->name,
            // A project is often named for its street ("1463 W Winnetka kitchen"): stripped like the enquiry is.
            'project' => static::redact((string) ($row['section']->estimate?->project?->project_name ?? 'past project')),
            'date' => optional($row['section']->created_at)->format('M Y') ?? '',
            'items' => $row['section']->estimate_line_items
                ->map(fn ($item) => ['name' => $item->name, 'quantity' => (float) $item->quantity, 'unit_type' => $item->unit_type])
                ->values()
                ->all(),
        ];
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
