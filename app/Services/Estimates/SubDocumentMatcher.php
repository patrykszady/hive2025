<?php

namespace App\Services\Estimates;

use App\Models\Estimate;
use App\Models\EstimateLineItem;
use App\Models\LineItem;
use App\Models\SubItemAlias;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Lines up a sub's document (SubDocumentReader's output) against our
 * estimate and proposes what a change order needs. The model does the one
 * thing that takes judgement — which of OUR catalog items each of the sub's
 * lines is ("Recessed LED square light" is our 4" Recessed) — and the
 * arithmetic is done here: per catalog item, the quantity we estimated
 * (every line of it, change orders and credits included) against what the
 * sub now lists, priced at OUR unit cost, never the sub's.
 *
 * Row kinds: increase (add that many at our cost), decrease (credit that
 * many) — both ADJUST the item's line on an open change order instead when
 * there is one (12.28's 26 recessed lights become 21, rather than a credit
 * against a line that is still editable; only what that line can't absorb
 * is credited against the original) — new (the sub lists work we never estimated; a catalog item was
 * matched), missing (we estimated it in this trade, the sub's document
 * doesn't list it — a possible credit, off by default), same (nothing to
 * do — a lump sum matched on both sides counts, with the sub's amount shown
 * against ours) and review (nothing could be matched or the units don't
 * compare).
 *
 * Matches an admin made or applied before are remembered (SubItemMemory):
 * a remembered wording is matched before the model is asked and overrides
 * its answer; the sub's other remembered wordings are shown to the model
 * as examples.
 */
class SubDocumentMatcher
{
    public function __construct(private readonly SubItemMemory $memory) {}

    private const SYSTEM = <<<'PROMPT'
You help a general contractor compare a subcontractor's updated estimate or invoice against the contractor's own estimate to the homeowner.
You are given the contractor's catalog of line items (each with an id), the lines of the contractor's estimate (which catalog item each is, and its quantity), and the sub's document items.
The document comes from ONE subcontractor in one trade, and every item on it is that trade's work: on an electrician's invoice, "A/C unit", "Furnace" or "Power for range" are the circuits and connections for those, never HVAC or plumbing work — match them to that trade's catalog items (dedicated circuits, appliance power) or to none.
Refer to each document item by the number printed before it (item_index), and answer for every one of them exactly once.
For EACH document item, name the catalog item id that describes the same work, even when the wording differs ("Recessed LED square light" → the contractor's recessed light item; "20AMP GFCI outlet" and "GFCI exterior" → the GFCI location item; "Power for range 240v" → a dedicated circuit item). Prefer a catalog item the estimate already uses when it fits. Several document items may map to one catalog item, and one document item may genuinely be work the contractor has no catalog item for — then use null.
Set comparable to false when the document item's quantity cannot be compared with the catalog item's unit (the item is a lump sum, or counts something else than the catalog item counts). Otherwise comparable is true.
Leave note empty unless there is something the contractor must know: why an item is not comparable, or a match you are unsure of. Never restate the match itself.
Also list the trades (the contractor's catalog categories) this document covers — an electrician's invoice covers "Electrical" — so unmatched estimate lines in other trades are not flagged.
Return JSON only, matching the schema.
PROMPT;

    /**
     * @param  array{items: list<array<string, mixed>>}  $document
     * @return array{trades: list<string>, rows: list<array<string, mixed>>, assignments: array<int, array{line_item_id: ?int, comparable: bool, note: string, learned: bool}>, model: string}
     */
    public function match(Estimate $estimate, array $document, ?int $subVendorId = null): array
    {
        $catalog = LineItem::query()
            ->where('belongs_to_vendor_id', $estimate->belongs_to_vendor_id)
            ->orderBy('category')->orderBy('name')
            ->get(['id', 'name', 'category', 'sub_category', 'unit_type', 'cost', 'desc']);

        $lines = EstimateLineItem::query()
            ->with(['section.bid'])
            ->where('estimate_id', $estimate->id)
            ->orderBy('section_id')->orderBy('order')
            ->get();

        $numbers = app(EstimateLineItemWriter::class);
        $items = $document['items'] ?? [];

        $known = $this->memory->lookup((int) $estimate->belongs_to_vendor_id, $subVendorId, array_column($items, 'description'));
        $examples = $this->memory->examples((int) $estimate->belongs_to_vendor_id, $subVendorId);

        $assignments = $items === [] ? ['trades' => [], 'assignments' => [], 'model' => ''] : $this->assign($catalog, $lines, $items, $known, $examples);

        // What was learned wins over what the model thinks.
        foreach ($items as $item) {
            $key = SubItemMemory::key($item['description']);
            if (isset($known[$key])) {
                $assignments['assignments'][$item['index']] = ['line_item_id' => $known[$key]['line_item_id'], 'comparable' => true, 'note' => '', 'learned' => true];
            }
        }

        $assignments['trades'] = $this->dominantTrades($catalog, $assignments);

        return [
            'trades' => $assignments['trades'],
            'rows' => $this->rows($catalog, $lines, $items, $assignments, $numbers),
            'assignments' => $assignments['assignments'],
            'model' => $assignments['model'],
        ];
    }

    /**
     * Ask the model which catalog item each document item is.
     *
     * @param  Collection<int, LineItem>  $catalog
     * @param  Collection<int, EstimateLineItem>  $lines
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, array{line_item_id: int, source: string, sub_vendor_id: ?int}>  $known  learned matches, by SubItemMemory::key()
     * @param  Collection<int, SubItemAlias>  $examples
     * @return array{trades: list<string>, assignments: array<int, array{line_item_id: ?int, comparable: bool, note: string, learned: bool}>, model: string}
     */
    protected function assign(Collection $catalog, Collection $lines, array $items, array $known = [], ?Collection $examples = null): array
    {
        $apiKey = (string) config('services.openai.api_key') ?: throw new RuntimeException('OpenAI API key missing (services.openai.api_key)');
        $model = (string) config('services.openai.sub_document_model', 'gpt-4.1');

        $catalogText = $catalog->map(fn (LineItem $item) => sprintf('[%d] %s — %s%s — %s @ $%s%s',
            $item->id, $item->name, $item->category, $item->sub_category ? '/'.$item->sub_category : '', $item->unit_type, number_format((float) $item->cost, 2),
            $item->desc ? ' — '.Str::limit(trim((string) $item->desc), 90, '…') : '',
        ))->implode("\n");

        $estimateText = $lines->map(fn (EstimateLineItem $line) => sprintf('%s [catalog %s] %s — %s%s — qty %s %s @ $%s%s%s',
            $line->credit_for_id ? 'credit' : 'line', $line->line_item_id ?? '?', $line->name, $line->category, $line->sub_category ? '/'.$line->sub_category : '',
            rtrim(rtrim(number_format((float) $line->quantity, 2, '.', ''), '0'), '.'), $line->unit_type, number_format((float) $line->cost, 2),
            $line->section?->bid && (int) $line->section->bid->type >= 2 ? ' (change order)' : '',
            $line->desc ? ' — '.Str::limit(trim((string) $line->desc), 80, '…') : '',
        ))->implode("\n");

        $itemsText = collect($items)->map(fn (array $item) => sprintf('%d. %s — qty %s%s%s%s',
            $item['index'], $item['description'],
            $item['quantity'] === null ? '—' : rtrim(rtrim(number_format((float) $item['quantity'], 2, '.', ''), '0'), '.'),
            $item['unit'] ? ' '.$item['unit'] : '',
            $item['unit_price'] !== null ? ' @ $'.number_format((float) $item['unit_price'], 2) : '',
            $item['amount'] !== null ? ' = $'.number_format((float) $item['amount'], 2) : '',
        ))->implode("\n");

        $learnedText = collect($items)
            ->filter(fn (array $item) => isset($known[SubItemMemory::key($item['description'])]))
            ->map(fn (array $item) => sprintf('item %d → catalog %d', $item['index'], $known[SubItemMemory::key($item['description'])]['line_item_id']))
            ->implode("\n");
        $knownKeys = array_keys($known);
        $exampleText = ($examples ?? collect())
            ->reject(fn (SubItemAlias $alias) => in_array($alias->description_key, $knownKeys, true))
            ->map(fn (SubItemAlias $alias) => sprintf('"%s" = [%d] %s', $alias->description, $alias->line_item_id, $alias->lineItem?->name))
            ->implode("\n");

        $schema = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['trades', 'assignments'],
            'properties' => [
                'trades' => ['type' => 'array', 'items' => ['type' => 'string']],
                'assignments' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['item_index', 'line_item_id', 'comparable', 'note'],
                        'properties' => [
                            'item_index' => ['type' => 'integer'],
                            'line_item_id' => ['type' => ['integer', 'null']],
                            'comparable' => ['type' => 'boolean'],
                            'note' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        ];

        $response = Http::withToken($apiKey)
            ->timeout(180)
            ->retry(2, 3000, throw: false)
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => $model,
                'temperature' => 0,
                'messages' => [
                    ['role' => 'system', 'content' => self::SYSTEM],
                    ['role' => 'user', 'content' => "Contractor's catalog (id in brackets):\n".$catalogText
                        ."\n\nContractor's estimate lines:\n".$estimateText
                        .($exampleText !== '' ? "\n\nHow this sub's wording has been matched before (follow the same reading for similar items):\n".$exampleText : '')
                        ."\n\nSub's document items:\n".$itemsText
                        .($learnedText !== '' ? "\n\nLearned matches — these items are settled, answer them exactly so:\n".$learnedText : '')],
                ],
                'response_format' => [
                    'type' => 'json_schema',
                    'json_schema' => ['name' => 'sub_document_match', 'strict' => true, 'schema' => $schema],
                ],
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('sub_document_match_http_'.$response->status().': '.mb_substr((string) $response->body(), 0, 300));
        }

        $parsed = json_decode((string) data_get($response->json(), 'choices.0.message.content'), true);

        if (! is_array($parsed) || ! is_array($parsed['assignments'] ?? null)) {
            throw new RuntimeException('sub_document_match_invalid_json');
        }

        $catalogIds = $catalog->pluck('id')->flip();
        $assignments = [];
        foreach ($parsed['assignments'] as $assignment) {
            $lineItemId = isset($assignment['line_item_id']) && $catalogIds->has((int) $assignment['line_item_id']) ? (int) $assignment['line_item_id'] : null;
            $assignments[(int) ($assignment['item_index'] ?? 0)] = [
                'line_item_id' => $lineItemId,
                'comparable' => (bool) ($assignment['comparable'] ?? true),
                'note' => trim((string) ($assignment['note'] ?? '')),
                'learned' => false,
            ];
        }

        return [
            'trades' => array_values(array_unique(array_filter(array_map(fn ($t) => trim((string) $t), (array) ($parsed['trades'] ?? []))))),
            'assignments' => $assignments,
            'model' => $model,
        ];
    }

    /**
     * The trades the document really covers: the categories its matched
     * items concentrate in (a quarter of them or more). The model's own list
     * is only a fallback — it called Smartech's invoice "Electrical, HVAC"
     * for two power-for-the-furnace lines, which flagged every HVAC line of
     * the estimate as missing.
     *
     * @param  Collection<int, LineItem>  $catalog
     * @param  array{trades: list<string>, assignments: array<int, array{line_item_id: ?int, comparable: bool, note: string}>}  $assignments
     * @return list<string>
     */
    protected function dominantTrades(Collection $catalog, array $assignments): array
    {
        $categories = $catalog->keyBy('id')->map(fn (LineItem $item) => (string) $item->category);
        $counts = collect($assignments['assignments'])
            ->pluck('line_item_id')
            ->filter()
            ->map(fn (int $id) => $categories->get($id))
            ->filter()
            ->countBy();

        if ($counts->isEmpty()) {
            return $assignments['trades'];
        }

        $total = $counts->sum();

        return $counts->filter(fn (int $count) => $count / $total >= 0.25)->keys()->values()->all();
    }

    /**
     * The arithmetic: one row per catalog item the document or the estimate
     * (in the document's trades) touches.
     *
     * @param  Collection<int, LineItem>  $catalog
     * @param  Collection<int, EstimateLineItem>  $lines
     * @param  list<array<string, mixed>>  $items
     * @param  array{trades: list<string>, assignments: array<int, array{line_item_id: ?int, comparable: bool, note: string}>}  $assignments
     * @return list<array<string, mixed>>
     */
    protected function rows(Collection $catalog, Collection $lines, array $items, array $assignments, EstimateLineItemWriter $numbers): array
    {
        $byCatalog = $lines->whereNotNull('line_item_id')->groupBy('line_item_id');
        $itemsByIndex = collect($items)->keyBy('index');
        $openChangeOrderSections = $lines->pluck('section')->filter()->unique('id')
            ->filter(fn ($section) => $section->bid && (int) $section->bid->type >= 2 && ! $section->isLocked())
            ->pluck('id')
            ->flip();
        $rows = [];
        $touched = [];

        // Document items grouped by the catalog item they were matched to.
        $grouped = [];
        foreach ($assignments['assignments'] as $index => $assignment) {
            if (! $itemsByIndex->has($index)) {
                continue;
            }
            $key = $assignment['line_item_id'] ?? 'unmatched-'.$index;
            $grouped[$key][] = $index;
        }
        // Items the model skipped entirely are still shown for review.
        foreach ($itemsByIndex->keys() as $index) {
            if (! isset($assignments['assignments'][$index])) {
                $grouped['unmatched-'.$index][] = $index;
            }
        }

        foreach ($grouped as $key => $indexes) {
            $docItems = $itemsByIndex->only($indexes)->values();
            $descriptions = $docItems->pluck('description')->all();
            $learned = collect($indexes)->contains(fn ($i) => $assignments['assignments'][$i]['learned'] ?? false);
            $subAmount = $docItems->sum(fn ($i) => (float) ($i['amount'] ?? 0)) ?: null;
            $notes = collect($indexes)->map(fn ($i) => $assignments['assignments'][$i]['note'] ?? '')->filter()->unique()->implode(' ');

            if (! is_int($key)) {
                $rows[] = $this->row('doc-'.$indexes[0], 'review', null, $docItems->first()['description'], null, null, null,
                    0.0, $docItems->sum(fn ($i) => (float) ($i['quantity'] ?? 0)) ?: null, null, (float) ($docItems->first()['unit_price'] ?? 0), $subAmount,
                    $indexes, $descriptions, [], null, $notes !== '' ? $notes : 'No matching catalog item — pick one to add it, or leave it out.');

                continue;
            }

            $touched[$key] = true;
            $catalogItem = $catalog->firstWhere('id', $key);
            $estimateLines = $byCatalog->get($key, collect());
            $estimated = round((float) $estimateLines->sum(fn (EstimateLineItem $l) => $l->credit_for_id ? -abs((float) $l->quantity) : (float) $l->quantity), 2);
            $documentQty = $docItems->every(fn ($i) => $i['quantity'] === null) ? null : round((float) $docItems->sum(fn ($i) => (float) ($i['quantity'] ?? 0)), 2);
            $comparable = collect($indexes)->every(fn ($i) => $assignments['assignments'][$i]['comparable'] ?? true);
            $cost = $this->unitCost($estimateLines, $catalogItem);
            $numbersList = $estimateLines->map(fn (EstimateLineItem $l) => $numbers->lineItemNumber($l))->all();
            $adjust = $this->adjustable($estimateLines, $openChangeOrderSections, $numbers);
            $name = $catalogItem?->name ?? $estimateLines->first()?->name ?? $descriptions[0];
            $unit = $catalogItem?->unit_type ?? $estimateLines->first()?->unit_type;
            $creditSource = $this->creditSource($estimateLines);

            if ($estimateLines->isEmpty()) {
                $kind = 'new';
                $delta = $documentQty ?? 1.0;
            } elseif ($unit === 'no_unit') {
                // A lump sum matched on both sides: nothing to compare, nothing to do.
                $kind = 'same';
                $delta = null;
                $ours = (float) $estimateLines->sum(fn (EstimateLineItem $l) => (float) $l->total);
                $notes = sprintf('Lump sum on both sides: the sub %s, our estimate %s.', $subAmount !== null ? money($subAmount) : '—', money($ours));
            } elseif (! $comparable || $documentQty === null) {
                $kind = 'review';
                $delta = null;
            } else {
                $delta = round($documentQty - $estimated, 2);
                $kind = abs($delta) < 0.005 ? 'same' : ($delta > 0 ? 'increase' : 'decrease');
                // A shortfall the open change-order line can't absorb needs a creditable original.
                if ($kind === 'decrease' && ! $creditSource && (! $adjust || abs($delta) > $adjust['quantity'] + 0.005)) {
                    $kind = 'review';
                    $notes = trim($notes.' The estimated lines cannot be credited automatically.');
                }
            }

            $rows[] = $this->row('li-'.$key, $kind, $key, $name, $catalogItem?->category ?? $estimateLines->first()?->category,
                $catalogItem?->sub_category ?? $estimateLines->first()?->sub_category, $unit,
                $estimated, $documentQty, $delta, $cost, $subAmount, $indexes, $descriptions, $numbersList, $creditSource?->id, $notes);
            $rows[array_key_last($rows)]['learned'] = $learned;
            $rows[array_key_last($rows)]['adjust'] = in_array($kind, ['increase', 'decrease'], true) ? $adjust : null;
        }

        // Estimated in this trade, not on the sub's document: a possible credit.
        $trades = array_map('strtolower', $assignments['trades']);
        foreach ($byCatalog as $lineItemId => $estimateLines) {
            $first = $estimateLines->first();
            if (isset($touched[$lineItemId]) || ! in_array(strtolower((string) $first->category), $trades, true)) {
                continue;
            }
            $estimated = round((float) $estimateLines->sum(fn (EstimateLineItem $l) => $l->credit_for_id ? -abs((float) $l->quantity) : (float) $l->quantity), 2);
            if ($estimated <= 0) {
                continue;
            }
            $catalogItem = $catalog->firstWhere('id', $lineItemId);
            $creditSource = $this->creditSource($estimateLines);
            $adjust = $this->adjustable($estimateLines, $openChangeOrderSections, $numbers);

            if (! $creditSource && ! $adjust) {
                $note = 'Not on the sub\'s document; cannot be credited automatically.';
            } else {
                $note = 'Not on the sub\'s document. Tick to '.($adjust ? 'take it off the change order' : 'credit it').'.';
            }

            $rows[] = $this->row('missing-'.$lineItemId, 'missing', (int) $lineItemId, $catalogItem?->name ?? $first->name, $first->category, $first->sub_category,
                $first->unit_type, $estimated, null, $first->unit_type === 'no_unit' ? -1.0 : -$estimated, $this->unitCost($estimateLines, $catalogItem), null, [], [],
                $estimateLines->map(fn (EstimateLineItem $l) => $numbers->lineItemNumber($l))->all(), $creditSource?->id, $note);
            $rows[array_key_last($rows)]['adjust'] = $adjust;
        }

        usort($rows, function (array $a, array $b) {
            $order = ['increase' => 0, 'decrease' => 1, 'new' => 2, 'review' => 3, 'missing' => 4, 'same' => 5];

            return [$order[$a['kind']], $a['name']] <=> [$order[$b['kind']], $b['name']];
        });

        return $rows;
    }

    /**
     * @param  list<int>  $indexes
     * @param  list<string>  $descriptions
     * @param  list<string>  $estimateNumbers
     * @return array<string, mixed>
     */
    private function row(string $key, string $kind, ?int $lineItemId, string $name, ?string $category, ?string $subCategory, ?string $unit,
        float $estimated, ?float $documentQty, ?float $delta, float $cost, ?float $subAmount, array $indexes, array $descriptions, array $estimateNumbers,
        ?int $creditSourceId, string $note): array
    {
        $quantity = $delta === null ? null : abs($delta);

        return [
            'key' => $key,
            'kind' => $kind,
            'line_item_id' => $lineItemId,
            'name' => $name,
            'category' => $category,
            'sub_category' => $subCategory,
            'unit_type' => $unit,
            'estimated_quantity' => $estimated,
            'document_quantity' => $documentQty,
            'delta' => $delta,
            'quantity' => $quantity,
            'cost' => round($cost, 2),
            'amount' => $quantity === null ? null : round(($delta < 0 ? -1 : 1) * $quantity * $cost, 2),
            'sub_amount' => $subAmount,
            'document_items' => array_values($indexes),
            'document_descriptions' => array_values($descriptions),
            'estimate_numbers' => array_values($estimateNumbers),
            'credit_source_id' => $creditSourceId,
            'note' => $note,
            'learned' => false,
            'adjust' => null,
            'include' => in_array($kind, ['increase', 'decrease', 'new'], true),
        ];
    }

    /**
     * The item's line on an open change order, if it has one: the newest
     * non-credit line in an unlocked change-order section. A quantity change
     * is written onto it rather than beside it.
     *
     * @param  Collection<int, EstimateLineItem>  $estimateLines
     * @param  Collection<int, int>  $openChangeOrderSections  section id => position
     * @return array{id: int, number: string, quantity: float, cost: float}|null
     */
    private function adjustable(Collection $estimateLines, Collection $openChangeOrderSections, EstimateLineItemWriter $numbers): ?array
    {
        $line = $estimateLines
            ->filter(fn (EstimateLineItem $l) => ! $l->credit_for_id && $openChangeOrderSections->has($l->section_id))
            ->sortByDesc('id')
            ->first();

        return $line ? ['id' => $line->id, 'number' => $numbers->lineItemNumber($line), 'quantity' => (float) $line->quantity, 'cost' => round((float) $line->cost, 2)] : null;
    }

    /** Our unit price for a catalog item: the newest estimate line of it, else the catalog. */
    private function unitCost(Collection $estimateLines, ?LineItem $catalogItem): float
    {
        $latest = $estimateLines->filter(fn (EstimateLineItem $l) => ! $l->credit_for_id)->sortByDesc('id')->first();

        return (float) ($latest?->cost ?? $catalogItem?->cost ?? 0);
    }

    /** The estimate line a credit is written against: the largest creditable one. */
    private function creditSource(Collection $estimateLines): ?EstimateLineItem
    {
        $writer = app(EstimateLineItemWriter::class);

        return $estimateLines
            ->filter(fn (EstimateLineItem $l) => ! $l->credit_for_id && $writer->canCredit($l))
            ->sortByDesc(fn (EstimateLineItem $l) => (float) $l->quantity)
            ->first();
    }
}
