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
            'floorplan' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:10240',
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

        $apiKey = config('services.ocr_space.api_key');
        $endpoint = config('services.ocr_space.endpoint');

        $extension = $this->floorplan->getClientOriginalExtension();
        $filename = $this->floorplan->getClientOriginalName();

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
