<?php

namespace App\Livewire\Estimates;

use App\Models\Estimate;
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
        $this->reset(['inquiry', 'floorplan', 'generatedItems', 'reasoning', 'error', 'showPreview']);
        $this->modal('estimate-ai-generator-modal')->show();
    }

    public function generate(): void
    {
        $this->validate([
            'inquiry' => 'required|min:10',
            'sectionId' => 'required',
        ]);

        $this->isGenerating = true;
        $this->error = '';
        $this->generatedItems = [];
        $this->reasoning = '';

        try {
            $floorplanData = null;

            // Parse floorplan if uploaded
            if ($this->floorplan) {
                $floorplanData = $this->parseFloorplan();
            }

            $service = app(EstimateAIService::class);
            $result = $service->generateEstimate(
                inquiry: $this->inquiry,
                floorplanData: $floorplanData,
                vendorId: $this->estimate->belongs_to_vendor_id ?? 1
            );

            if ($result['success']) {
                $this->generatedItems = $result['line_items'];
                $this->reasoning = $result['reasoning'];
                $this->showPreview = true;
            } else {
                $this->error = $result['error'] ?? 'Failed to generate estimate';
            }
        } catch (\Exception $e) {
            $this->error = 'An error occurred: ' . $e->getMessage();
        } finally {
            $this->isGenerating = false;
        }
    }

    public function applyEstimate(): void
    {
        if (empty($this->generatedItems)) {
            return;
        }

        $this->authorize('update', $this->estimate);

        $section = EstimateSection::findOrFail($this->sectionId);

        $service = app(EstimateAIService::class);
        $createdItems = $service->applyToEstimate($this->estimate, $section, $this->generatedItems);

        $this->modal('estimate-ai-generator-modal')->close();

        $this->dispatch('refreshComponent')->to('estimates.estimate-show');
        $this->dispatch('refresh')->to('projects.project-finances');

        Flux::toast(
            duration: 5000,
            position: 'top right',
            variant: 'success',
            heading: 'AI Estimate Applied',
            text: count($createdItems) . ' line items added to ' . ($section->name ?? 'section'),
        );

        $this->reset(['inquiry', 'floorplan', 'generatedItems', 'reasoning', 'showPreview']);
    }

    public function removeItem(int $index): void
    {
        unset($this->generatedItems[$index]);
        $this->generatedItems = array_values($this->generatedItems);
    }

    public function updateQuantity(int $index, float $quantity): void
    {
        if (isset($this->generatedItems[$index])) {
            $this->generatedItems[$index]['quantity'] = max(0.1, $quantity);
        }
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
        $total = 0;
        foreach ($this->generatedItems as $item) {
            $quantity = $item['quantity'] ?? 1;
            $cost = $item['cost'] ?? 0;
            $total += $quantity * $cost;
        }

        return $total;
    }

    public function render()
    {
        return view('livewire.estimates.estimate-ai-generator', [
            'sections' => $this->estimate->estimate_sections,
            'estimatedTotal' => $this->getEstimatedTotalProperty(),
        ]);
    }
}
