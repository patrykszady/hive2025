<?php

namespace App\Livewire\Estimates;

use App\Models\Estimate;
use App\Models\EstimateAiDraft;
use App\Models\EstimateAiRule;
use App\Models\EstimateLineItem;
use App\Models\EstimateSection;
use App\Services\EstimateAIService;
use Flux;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Http;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;

class EstimateAIGenerator extends Component
{
    use AuthorizesRequests;
    use WithFileUploads;

    public Estimate $estimate;

    public ?int $sectionId = null;

    public string $inquiry = '';

    public $floorplan = null;

    public array $generatedItems = [];

    public string $reasoning = '';

    public bool $isGenerating = false;

    public bool $showPreview = false;

    public string $error = '';

    /** The record of this run in the generator's memory. */
    public ?int $draftId = null;

    public bool $showRules = false;

    public string $newRule = '';

    /** Running total of the rows streamed so far; not component state. */
    protected float $draftTotal = 0.0;

    protected function rules(): array
    {
        return [
            'inquiry' => 'required|min:10',
            'floorplan' => 'nullable|file|mimes:pdf,jpg,jpeg,png,csv|max:10240',
            'sectionId' => 'required|exists:estimate_sections,id',
        ];
    }

    public function mount(): void
    {
        // Default to first section if exists
        if ($this->estimate->estimate_sections->isNotEmpty()) {
            $this->sectionId = $this->estimate->estimate_sections->first()->id;
        }
    }

    #[On('openAIGenerator')]
    public function openModal(?int $sectionId = null): void
    {
        if ($sectionId) {
            $this->sectionId = $sectionId;
        }
        $this->reset(['inquiry', 'floorplan', 'generatedItems', 'reasoning', 'error', 'showPreview', 'draftId', 'showRules', 'newRule']);
        $this->modal('estimate-ai-generator-modal')->show();
    }

    public function generate(): void
    {
        $this->validate([
            'inquiry' => 'required|min:10',
            'sectionId' => 'required',
        ]);

        $this->authorize('update', $this->estimate);

        $this->isGenerating = true;
        $this->error = '';
        $this->generatedItems = [];
        $this->reasoning = '';
        $this->draftId = null;
        $this->draftTotal = 0.0;
        $result = null;

        $section = EstimateSection::findOrFail($this->sectionId);

        try {
            $floorplanData = $this->floorplan ? $this->parseFloorplan() : null;

            $service = app(EstimateAIService::class);
            $result = $service->generateEstimate(
                inquiry: $this->inquiry,
                floorplanData: $floorplanData,
                vendorId: $this->estimate->belongs_to_vendor_id ?? 1,
                // Every line lands on the estimate the moment it is drafted.
                onLineItem: fn (array $item, int $index) => $this->draftLine($section, $item),
            );

            $this->reasoning = $result['reasoning'] ?? '';

            if (! $result['success']) {
                $this->error = $result['error'] ?? 'Failed to generate estimate';
            } elseif ($this->generatedItems === []) {
                $this->error = 'Nothing was drafted. Describe the work in more detail and try again.';
            }
        } catch (\Exception $e) {
            $this->error = 'An error occurred: '.$e->getMessage();
        } finally {
            $this->isGenerating = false;
        }

        // Whatever was drafted is on the estimate already, cut off or not.
        if ($this->generatedItems !== []) {
            // The section keeps what was asked for and how the scope was read.
            $section->forceFill(['ai_inquiry' => $this->inquiry, 'ai_scope' => $this->reasoning ?: null])->save();
            $this->recordDraft($section, $result['meta'] ?? []);

            $this->showPreview = true;
            $this->refreshEstimate();
            // Stay here to review the draft, whatever else re-rendered meanwhile.
            $this->modal('estimate-ai-generator-modal')->show();
        }
    }

    /**
     * One drafted item onto the section, then into the table the estimator
     * is watching, with the running total and the status line kept in step.
     *
     * @param  array<string, mixed>  $item
     */
    protected function draftLine(EstimateSection $section, array $item): void
    {
        $line = app(EstimateAIService::class)->applyLineItem($this->estimate, $section, $item);

        if ($line === null) {
            return;
        }

        $row = $this->rowFor($line);
        $this->generatedItems[] = $row;
        $this->draftTotal += $row['total'];
        $count = count($this->generatedItems);

        $this->stream(
            to: 'draft-rows',
            content: view('livewire.estimates.partials.ai-draft-row', ['item' => $row, 'index' => $count - 1, 'editable' => false])->render(),
        );
        $this->stream(to: 'draft-total', content: money($this->draftTotal), replace: true);
        $this->stream(to: 'draft-status', content: "Drafting from your catalog… {$count} line ".($count === 1 ? 'item' : 'items').' so far', replace: true);
    }

    /**
     * The run goes into the generator's memory: what was asked, what came
     * back. What the estimator changes afterwards is read from the section.
     *
     * @param  array<string, mixed>  $meta
     */
    protected function recordDraft(EstimateSection $section, array $meta): void
    {
        $draft = EstimateAiDraft::create([
            'vendor_id' => $this->estimate->belongs_to_vendor_id ?? 1,
            'estimate_id' => $this->estimate->id,
            'section_id' => $section->id,
            'user_id' => auth()->id(),
            'request_id' => $meta['request_id'] ?? null,
            'model' => $meta['model'] ?? null,
            'inquiry' => $this->inquiry,
            'floorplan' => $meta['floorplan'] ?? null,
            'reasoning' => $this->reasoning ?: null,
            'drafted_items' => array_map(fn (array $row) => [
                'estimate_line_item_id' => $row['id'],
                'line_item_id' => $row['line_item_id'],
                'name' => $row['name'],
                'quantity' => $row['quantity'],
                'unit_type' => $row['unit_type'],
                'cost' => $row['cost'],
            ], $this->generatedItems),
            'usage' => $meta['usage'] ?? null,
            'stop_reason' => $meta['stop_reason'] ?? null,
            'status' => EstimateAiDraft::DRAFTED,
        ]);

        $this->draftId = $draft->id;
    }

    /**
     * @return array<string, mixed>
     */
    protected function rowFor(EstimateLineItem $line): array
    {
        return [
            'id' => $line->id,
            'line_item_id' => $line->line_item_id,
            'name' => $line->name,
            'category' => $line->category,
            'sub_category' => $line->sub_category,
            'quantity' => (float) $line->quantity,
            'unit_type' => $line->unit_type,
            'cost' => (float) $line->cost,
            'total' => (float) $line->total,
        ];
    }

    /**
     * A quantity typed in the table is saved to its line straight away; a
     * blank or zero counts as one.
     */
    public function updatedGeneratedItems(mixed $value, string $key): void
    {
        [$index, $field] = array_pad(explode('.', $key, 2), 2, null);

        if ($field !== 'quantity' || ! isset($this->generatedItems[$index])) {
            return;
        }

        $this->authorize('update', $this->estimate);

        $line = EstimateLineItem::find($this->generatedItems[$index]['id']);
        if ($line === null) {
            return;
        }

        $quantity = is_numeric($value) && (float) $value > 0 ? (float) $value : 1.0;
        $line->quantity = $quantity;
        $line->total = $quantity * (float) $line->cost;
        $line->save();

        $this->generatedItems[$index]['total'] = (float) $line->total;
        $this->refreshEstimate();
    }

    public function removeItem(int $index): void
    {
        if (! isset($this->generatedItems[$index])) {
            return;
        }

        $this->authorize('update', $this->estimate);

        // Never wanted: gone for good, not parked among the restorable lines.
        EstimateLineItem::find($this->generatedItems[$index]['id'])?->forceDelete();

        unset($this->generatedItems[$index]);
        $this->generatedItems = array_values($this->generatedItems);
        $this->refreshEstimate();
    }

    /** Every drafted line off the estimate, and back to the description. */
    public function discardDraft(): void
    {
        $this->authorize('update', $this->estimate);

        foreach ($this->generatedItems as $row) {
            EstimateLineItem::find($row['id'])?->forceDelete();
        }

        EstimateSection::find($this->sectionId)?->forceFill(['ai_inquiry' => null, 'ai_scope' => null])->save();
        EstimateAiDraft::whereKey($this->draftId)->update(['status' => EstimateAiDraft::DISCARDED]);

        $this->reset(['generatedItems', 'reasoning', 'error', 'showPreview', 'draftId']);
        $this->refreshEstimate();

        Flux::toast(
            duration: 5000,
            position: 'top right',
            heading: 'Draft discarded',
            text: 'The drafted line items were removed from the estimate.',
        );
    }

    /** The draft is on the estimate already; this closes up. */
    public function finish(): void
    {
        $count = count($this->generatedItems);
        $section = EstimateSection::find($this->sectionId);

        EstimateAiDraft::whereKey($this->draftId)->update(['status' => EstimateAiDraft::FINISHED]);

        $this->modal('estimate-ai-generator-modal')->close();
        $this->refreshEstimate();

        Flux::toast(
            duration: 5000,
            position: 'top right',
            variant: 'success',
            heading: 'AI Estimate Applied',
            text: $count.' line '.($count === 1 ? 'item' : 'items').' added to '.($section?->name ?: 'section'),
        );

        $this->reset(['inquiry', 'floorplan', 'generatedItems', 'reasoning', 'error', 'showPreview', 'draftId']);
    }

    // ---- The company's estimating rules: read on every draft, written here. ----

    public function addRule(): void
    {
        $this->authorize('update', $this->estimate);
        $this->validate(['newRule' => 'required|string|min:5|max:500']);

        EstimateAiRule::create([
            'vendor_id' => $this->vendorId(),
            'text' => trim($this->newRule),
            'status' => EstimateAiRule::ACTIVE,
            'source' => EstimateAiRule::MANUAL,
            'created_by' => auth()->id(),
        ]);

        $this->newRule = '';
    }

    public function approveRule(int $ruleId): void
    {
        $this->authorize('update', $this->estimate);
        $this->rule($ruleId)?->update(['status' => EstimateAiRule::ACTIVE]);
    }

    public function dismissRule(int $ruleId): void
    {
        $this->authorize('update', $this->estimate);
        $this->rule($ruleId)?->update(['status' => EstimateAiRule::DISMISSED]);
    }

    public function deleteRule(int $ruleId): void
    {
        $this->authorize('update', $this->estimate);
        $rule = $this->rule($ruleId);

        // A proposal that was approved and then deleted is not proposed again.
        $rule?->fingerprint ? $rule->update(['status' => EstimateAiRule::DISMISSED]) : $rule?->delete();
    }

    protected function rule(int $ruleId): ?EstimateAiRule
    {
        return EstimateAiRule::query()->forVendor($this->vendorId())->find($ruleId);
    }

    protected function vendorId(): int
    {
        return (int) ($this->estimate->belongs_to_vendor_id ?? 1);
    }

    /** The edit modal saved or removed a line: re-read the drafted rows. */
    #[On('estimate-line-item-saved')]
    public function reloadDraftLines(): void
    {
        if ($this->generatedItems === []) {
            return;
        }

        $lines = EstimateLineItem::query()->whereIn('id', array_column($this->generatedItems, 'id'))->get()->keyBy('id');

        $this->generatedItems = collect($this->generatedItems)
            ->filter(fn (array $row) => $lines->has($row['id']))
            ->map(fn (array $row) => $this->rowFor($lines->get($row['id'])))
            ->values()
            ->all();
    }

    protected function refreshEstimate(): void
    {
        $this->dispatch('refreshComponent')->to('estimates.estimate-show');
        $this->dispatch('refresh')->to('projects.project-finances');
    }

    protected function parseFloorplan(): ?array
    {
        // Basic floorplan parsing - in a real implementation you might use
        // OCR or a specialized service to extract dimensions from PDFs
        // For now, we return basic metadata

        if (! $this->floorplan) {
            return null;
        }

        $extension = $this->floorplan->getClientOriginalExtension();
        $filename = $this->floorplan->getClientOriginalName();

        if (strtolower($extension) === 'csv') {
            $metrics = $this->extractCsvFloorplanMetrics(file_get_contents($this->floorplan->getRealPath()));

            return array_merge([
                'filename' => $filename,
                'type' => 'csv',
                'source' => 'csv',
            ], $metrics);
        }

        $apiKey = config('services.ocr_space.api_key');
        $endpoint = config('services.ocr_space.endpoint');

        if (empty($apiKey) || empty($endpoint)) {
            return [
                'filename' => $filename,
                'type' => $extension,
                'note' => 'OCR is not configured. Set OCR_SPACE_API in .env to enable floorplan parsing.',
            ];
        }

        $fileContents = file_get_contents($this->floorplan->getRealPath());

        $response = Http::timeout(90)
            ->attach('file', $fileContents, $filename)
            ->post($endpoint, [
                'apikey' => $apiKey,
                'language' => 'eng',
                'isOverlayRequired' => 'false',
                'detectOrientation' => 'true',
                'OCREngine' => '2',
            ]);

        if (! $response->successful()) {
            return [
                'filename' => $filename,
                'type' => $extension,
                'note' => 'OCR failed to process floorplan. Please verify manually.',
            ];
        }

        $parsedText = data_get($response->json(), 'ParsedResults.0.ParsedText', '');
        $metrics = $this->extractFloorplanMetrics($parsedText);

        return array_merge([
            'filename' => $filename,
            'type' => $extension,
            'source' => 'ocr_space',
        ], $metrics);
    }

    protected function extractFloorplanMetrics(string $text): array
    {
        $clean = str_replace([',', "\r", "\n"], ['',' ', ' '], $text);

        $floorSqft = $this->extractLabeledSqft($clean, ['floor', 'area', 'total']);
        $wallSqft = $this->extractLabeledSqft($clean, ['wall', 'walls']);

        $dimensions = $this->extractLargestDimensions($clean);
        if (! $floorSqft && $dimensions) {
            $floorSqft = $dimensions['area'];
        }

        if (! $wallSqft && $dimensions) {
            $wallSqft = $this->estimateWallSqft($dimensions['length'], $dimensions['width'], 8);
        }

        $cementBoardSqft = null;
        if ($floorSqft || $wallSqft) {
            $cementBoardSqft = (float) ($floorSqft ?? 0) + (float) ($wallSqft ?? 0);
        }

        return [
            'floor_sqft' => $floorSqft ? round($floorSqft, 2) : null,
            'wall_sqft' => $wallSqft ? round($wallSqft, 2) : null,
            'cement_board_sqft' => $cementBoardSqft ? round($cementBoardSqft, 2) : null,
            'ceiling_height_ft' => $dimensions ? 8 : null,
        ];
    }

    protected function extractCsvFloorplanMetrics(string $contents): array
    {
        $rows = $this->parseCsvRows($contents);

        if (count($rows) < 2) {
            return [
                'floor_sqft' => null,
                'wall_sqft' => null,
                'cement_board_sqft' => null,
                'ceiling_height_ft' => null,
            ];
        }

        $header = array_shift($rows);
        $normalizedHeaders = array_map($this->normalizeCsvHeader(...), $header);

        // Detect Polycam key-value format: Room, Description, Value
        if ($this->isPolycamFormat($normalizedHeaders)) {
            return $this->extractPolycamMetrics($rows);
        }

        // Fallback to columnar format parsing
        $floorAreaIndex = $this->findCsvColumn($normalizedHeaders, ['floor', 'area']);
        $wallAreaIndex = $this->findCsvColumn($normalizedHeaders, ['wall', 'area']);
        $ceilingHeightIndex = $this->findCsvColumn($normalizedHeaders, ['ceiling', 'height']);

        $areaIndex = $this->findCsvColumn($normalizedHeaders, ['area']);
        $surfaceIndex = $this->findCsvColumn($normalizedHeaders, ['surface'])
            ?? $this->findCsvColumn($normalizedHeaders, ['type'])
            ?? $this->findCsvColumn($normalizedHeaders, ['name']);

        $floorSqft = 0.0;
        $wallSqft = 0.0;
        $ceilingHeight = null;
        $hasFloor = false;
        $hasWall = false;

        foreach ($rows as $row) {
            if ($floorAreaIndex !== null) {
                $value = $this->parseCsvNumber($row[$floorAreaIndex] ?? null);
                if ($value !== null) {
                    $floorSqft += $value;
                    $hasFloor = true;
                }
            }

            if ($wallAreaIndex !== null) {
                $value = $this->parseCsvNumber($row[$wallAreaIndex] ?? null);
                if ($value !== null) {
                    $wallSqft += $value;
                    $hasWall = true;
                }
            }

            if ($ceilingHeightIndex !== null) {
                $value = $this->parseCsvNumber($row[$ceilingHeightIndex] ?? null);
                if ($value !== null) {
                    $ceilingHeight = max($ceilingHeight ?? 0, $value);
                }
            }

            if ($areaIndex !== null && $surfaceIndex !== null) {
                $area = $this->parseCsvNumber($row[$areaIndex] ?? null);
                $surface = strtolower(trim((string) ($row[$surfaceIndex] ?? '')));

                if ($area !== null) {
                    if (! $hasFloor && str_contains($surface, 'floor')) {
                        $floorSqft += $area;
                        $hasFloor = true;
                    }

                    if (! $hasWall && str_contains($surface, 'wall')) {
                        $wallSqft += $area;
                        $hasWall = true;
                    }
                }
            }
        }

        $floorSqft = $floorSqft > 0 ? round($floorSqft, 2) : null;
        $wallSqft = $wallSqft > 0 ? round($wallSqft, 2) : null;
        $cementBoardSqft = ($floorSqft || $wallSqft) ? round(($floorSqft ?? 0) + ($wallSqft ?? 0), 2) : null;
        $ceilingHeight = $ceilingHeight ? round($ceilingHeight, 2) : null;

        return [
            'floor_sqft' => $floorSqft,
            'wall_sqft' => $wallSqft,
            'cement_board_sqft' => $cementBoardSqft,
            'ceiling_height_ft' => $ceilingHeight,
        ];
    }

    protected function isPolycamFormat(array $normalizedHeaders): bool
    {
        // Polycam exports: Room, Description, Value
        $hasRoom = in_array('room', $normalizedHeaders);
        $hasDescription = in_array('description', $normalizedHeaders);
        $hasValue = in_array('value', $normalizedHeaders);

        return $hasRoom && $hasDescription && $hasValue;
    }

    /**
     * Everything a Polycam room scan tells us that sizes an estimate, not
     * just the four totals: each room's floor, walls, ceiling, perimeter and
     * casings; the cabinets as linear feet, split into base and upper by
     * depth (uppers are the shallow ones), with the countertop run as the
     * base cabinets plus what sits under the counter; the appliances by
     * count. Totals come from the "Entire Roomplan" rows when the export
     * has them, else from the rooms.
     *
     * @param  array<int, array<int, string>>  $rows  Room, Description, Value
     */
    protected function extractPolycamMetrics(array $rows): array
    {
        $rooms = [];
        $totals = ['floor_sqft' => null, 'wall_sqft' => null, 'perimeter_ft' => null, 'window_area_sqft' => null];
        $cabinets = [];
        $underCounterWidths = [];
        $appliances = [];
        $skipRooms = ['entire roomplan', 'key', 'settings', ''];

        foreach ($rows as $row) {
            $room = trim((string) ($row[0] ?? ''));
            $description = strtolower(trim((string) ($row[1] ?? '')));
            $value = trim((string) ($row[2] ?? ''));
            $isTotal = strtolower($room) === 'entire roomplan';
            $perRoom = ! in_array(strtolower($room), $skipRooms, true);

            if ($perRoom && ! isset($rooms[$room])) {
                $rooms[$room] = ['name' => $room];
            }

            if ($isTotal && str_contains($description, 'livable floor area')) {
                $totals['floor_sqft'] = $this->parsePolycamValue($value);
            } elseif ($isTotal && str_contains($description, 'wall area')) {
                $totals['wall_sqft'] = $this->parsePolycamValue($value);
            } elseif ($isTotal && str_contains($description, 'total perimeter')) {
                $totals['perimeter_ft'] = $this->parsePolycamFeetInches($value);
            } elseif ($isTotal && str_contains($description, 'total window area')) {
                $totals['window_area_sqft'] = $this->parsePolycamValue($value);
            } elseif ($isTotal && preg_match('/^# (.+)$/', $description, $m)) {
                $appliances[str_replace(' ', '_', trim($m[1]))] = (int) $this->parsePolycamValue($value);
            } elseif ($perRoom && str_starts_with($description, 'floor area')) {
                $rooms[$room]['floor_sqft'] = $this->parsePolycamValue($value);
            } elseif ($perRoom && str_starts_with($description, 'wall area')) {
                $rooms[$room]['wall_sqft'] = $this->parsePolycamValue($value);
            } elseif ($perRoom && str_starts_with($description, 'ceiling height')) {
                $rooms[$room]['ceiling_height_ft'] = $this->parsePolycamFeetInches($value);
            } elseif ($perRoom && str_starts_with($description, 'perimeter')) {
                $rooms[$room]['perimeter_ft'] = $this->parsePolycamFeetInches($value);
            } elseif ($perRoom && str_starts_with($description, 'dimensions') && ! str_contains($description, 'bounding')) {
                $rooms[$room]['dimensions'] = preg_replace('/\s+/', ' ', $value);
            } elseif ($perRoom && str_contains($description, 'windows total casing length')) {
                $rooms[$room]['window_casing_lf'] = $this->parsePolycamFeetInches($value);
            } elseif ($perRoom && str_contains($description, 'doors total casing length')) {
                $rooms[$room]['door_casing_lf'] = $this->parsePolycamFeetInches($value);
            } elseif ($perRoom && str_starts_with($description, 'cabinet dimensions')) {
                if ($dims = $this->parsePolycamDimensions($value)) {
                    $cabinets[] = $dims;
                }
            } elseif ($perRoom && str_starts_with($description, 'dishwasher dimensions')) {
                if ($dims = $this->parsePolycamDimensions($value)) {
                    $underCounterWidths[] = $dims[0];
                }
            }
        }

        $rooms = array_values(array_filter($rooms, fn ($r) => count($r) > 1));
        $sum = fn (string $key) => collect($rooms)->pluck($key)->filter(fn ($v) => is_numeric($v))->sum();
        $round = fn ($v) => is_numeric($v) && $v > 0 ? round((float) $v, 2) : null;

        $floorSqft = $round($totals['floor_sqft'] ?? $sum('floor_sqft'));
        $wallSqft = $round($totals['wall_sqft'] ?? $sum('wall_sqft'));
        $ceilingHeight = $round(collect($rooms)->pluck('ceiling_height_ft')->filter()->max());

        // Base cabinets stand deep (about 2'); uppers are the shallow boxes
        // (about 1'). Tall pantries (5'+) are neither. Widths add up to the
        // run in linear feet — what cabinet and countertop items are sized by.
        $base = collect($cabinets)->filter(fn ($c) => $c[2] >= 1.75 && $c[1] < 5);
        $upper = collect($cabinets)->filter(fn ($c) => $c[2] < 1.75);
        $tall = collect($cabinets)->filter(fn ($c) => $c[2] >= 1.75 && $c[1] >= 5);
        $baseLf = $round($base->sum(fn ($c) => $c[0]));

        return array_filter([
            'floor_sqft' => $floorSqft,
            'wall_sqft' => $wallSqft,
            // Kept for the bath heuristic downstream (floor plus walls, as
            // before); a kitchen will not tile every wall.
            'cement_board_sqft' => ($floorSqft || $wallSqft) ? round(($floorSqft ?? 0) + ($wallSqft ?? 0), 2) : null,
            'ceiling_height_ft' => $ceilingHeight,
            'perimeter_ft' => $round($totals['perimeter_ft'] ?? $sum('perimeter_ft')),
            'window_area_sqft' => $round($totals['window_area_sqft']),
            'window_casing_lf' => $round($sum('window_casing_lf')),
            'door_casing_lf' => $round($sum('door_casing_lf')),
            'cabinet_count' => $cabinets !== [] ? count($cabinets) : ($appliances['cabinets'] ?? null),
            'base_cabinet_lf' => $baseLf,
            'upper_cabinet_lf' => $round($upper->sum(fn ($c) => $c[0])),
            'tall_cabinet_lf' => $round($tall->sum(fn ($c) => $c[0])),
            'countertop_lf' => $baseLf !== null ? round($baseLf + array_sum($underCounterWidths), 2) : null,
            'appliances' => array_filter(array_intersect_key($appliances, array_flip(['fridges', 'stoves', 'ovens', 'dishwashers', 'sinks', 'microwaves', 'washers', 'dryers', 'toilets', 'bathtubs', 'showers']))),
            'rooms' => array_map(fn ($r) => array_filter(array_map(fn ($v) => is_float($v) ? round($v, 2) : $v, $r), fn ($v) => $v !== null && $v !== ''), $rooms),
        ], fn ($v) => $v !== null && $v !== [] && $v !== 0);
    }

    /** "3' 11\" x 3'  0\" x 2'  2\"" -> [width, height, depth] in feet, or null. */
    protected function parsePolycamDimensions(string $value): ?array
    {
        $parts = array_map('trim', preg_split('/\s*[x×]\s*/i', $value) ?: []);
        if (count($parts) < 3) {
            return null;
        }

        $dims = array_map(fn ($p) => $this->parsePolycamFeetInches($p), array_slice($parts, 0, 3));

        return in_array(null, $dims, true) ? null : $dims;
    }

    protected function parsePolycamValue(string $value): ?float
    {
        // Handle simple numeric values like "64.0" or "274.6"
        $clean = trim($value);
        if ($clean === '') {
            return null;
        }

        // Remove any units suffix
        $clean = preg_replace('/\s*(ft\^?2?|sq\s*ft|sqft|sf)?\s*$/i', '', $clean);
        $clean = str_replace(',', '', $clean);

        if (is_numeric($clean)) {
            return (float) $clean;
        }

        return null;
    }

    protected function parsePolycamFeetInches(string $value): ?float
    {
        // Handle feet-inches format like "8' 0.0"" or "35' 5.0""
        $clean = trim($value);

        // Pattern: X' Y.Z" or X' Y"
        if (preg_match("/(\d+(?:\.\d+)?)\s*'\s*(\d+(?:\.\d+)?)\s*\"?/", $clean, $matches)) {
            $feet = (float) $matches[1];
            $inches = (float) $matches[2];
            return $feet + ($inches / 12);
        }

        // Pattern: just feet X'
        if (preg_match("/(\d+(?:\.\d+)?)\s*'/", $clean, $matches)) {
            return (float) $matches[1];
        }

        // Plain number
        if (is_numeric($clean)) {
            return (float) $clean;
        }

        return null;
    }

    protected function parseCsvRows(string $contents): array
    {
        $rows = [];
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $contents);
        rewind($handle);

        while (($row = fgetcsv($handle)) !== false) {
            if ($row === [null] || $row === false) {
                continue;
            }

            $isEmpty = true;
            foreach ($row as $cell) {
                if (trim((string) $cell) !== '') {
                    $isEmpty = false;
                    break;
                }
            }

            if (! $isEmpty) {
                $rows[] = $row;
            }
        }

        fclose($handle);

        return $rows;
    }

    protected function normalizeCsvHeader(string $value): string
    {
        $normalized = preg_replace('/[^a-z0-9]+/i', ' ', $value);

        return strtolower(trim(preg_replace('/\s+/', ' ', $normalized)));
    }

    protected function findCsvColumn(array $headers, array $tokens): ?int
    {
        foreach ($headers as $index => $header) {
            $matches = true;
            foreach ($tokens as $token) {
                if (! str_contains($header, $token)) {
                    $matches = false;
                    break;
                }
            }

            if ($matches) {
                return $index;
            }
        }

        return null;
    }

    protected function parseCsvNumber($value): ?float
    {
        if ($value === null) {
            return null;
        }

        $clean = trim((string) $value);
        if ($clean === '') {
            return null;
        }

        $clean = str_replace([',', ' '], ['', ''], $clean);
        $clean = preg_replace('/[^0-9.\-]/', '', $clean);

        if ($clean === '' || $clean === '-' || $clean === '.') {
            return null;
        }

        return (float) $clean;
    }

    protected function extractLabeledSqft(string $text, array $labels): ?float
    {
        foreach ($labels as $label) {
            if (preg_match('/' . preg_quote($label, '/') . '\\s*[:\-]?\\s*(\d+(?:\\.\d+)?)\\s*(sq\\.?\\s*ft|sqft|sf)\b/i', $text, $matches)) {
                return (float) $matches[1];
            }
        }

        if (preg_match_all('/(\d+(?:\.\d+)?)\s*(sq\.?\s*ft|sqft|sf)\b/i', $text, $matches)) {
            $values = array_map('floatval', $matches[1]);
            if (! empty($values)) {
                return max($values);
            }
        }

        return null;
    }

    protected function extractLargestDimensions(string $text): ?array
    {
        if (! preg_match_all('/(\d+(?:\.\d+)?)\s*(?:\'|ft)\s*[xX×]\s*(\d+(?:\.\d+)?)\s*(?:\'|ft)/', $text, $matches, PREG_SET_ORDER)) {
            return null;
        }

        $largest = null;
        foreach ($matches as $match) {
            $length = (float) $match[1];
            $width = (float) $match[2];
            $area = $length * $width;

            if (! $largest || $area > $largest['area']) {
                $largest = [
                    'length' => $length,
                    'width' => $width,
                    'area' => $area,
                ];
            }
        }

        return $largest;
    }

    protected function estimateWallSqft(float $length, float $width, int $height): float
    {
        $perimeter = 2 * ($length + $width);
        return $perimeter * $height;
    }

    public function getEstimatedTotalProperty(): float
    {
        return array_sum(array_map(fn (array $row) => (float) ($row['total'] ?? 0), $this->generatedItems));
    }

    public function render()
    {
        $sections = $this->estimate->estimate_sections;
        $rules = EstimateAiRule::query()->forVendor($this->vendorId())->whereIn('status', [EstimateAiRule::ACTIVE, EstimateAiRule::PROPOSED])->orderBy('id')->get();

        return view('livewire.estimates.estimate-ai-generator', [
            'sections' => $sections,
            'sectionName' => $sections->firstWhere('id', $this->sectionId)?->name ?: 'Unnamed Section',
            'estimatedTotal' => $this->getEstimatedTotalProperty(),
            'activeRules' => $rules->where('status', EstimateAiRule::ACTIVE)->values(),
            'proposedRules' => $rules->where('status', EstimateAiRule::PROPOSED)->values(),
        ]);
    }
}
