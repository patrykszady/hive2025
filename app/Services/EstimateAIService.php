<?php

namespace App\Services;

use App\Models\Estimate;
use App\Models\EstimateLineItem;
use App\Models\EstimateSection;
use App\Models\LineItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use OpenAI\Client;
use OpenAI\Transporters\HttpTransporter;
use OpenAI\ValueObjects\ApiKey;
use OpenAI\ValueObjects\Transporter\BaseUri;
use OpenAI\ValueObjects\Transporter\Headers;
use OpenAI\ValueObjects\Transporter\QueryParams;
use GuzzleHttp\Client as GuzzleClient;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class EstimateAIService
{
    protected Client $client;

    public function __construct()
    {
        $guzzleClient = new GuzzleClient;
        $baseUri = BaseUri::from('https://api.openai.com/v1');
        
        $apiKeyValue = config('services.openai.api_key') ?: env('OPENAI_API_KEY');
        
        if (empty($apiKeyValue)) {
            throw new \RuntimeException('OpenAI API key is not configured. Set OPENAI_API_KEY in your .env file.');
        }
        
        $apiKey = ApiKey::from($apiKeyValue);
        $headers = Headers::withAuthorization($apiKey);
        $queryParams = QueryParams::create();
        $streamHandler = function (RequestInterface $request) use ($guzzleClient): ResponseInterface {
            return $guzzleClient->send($request, ['stream' => true]);
        };

        $transporter = new HttpTransporter($guzzleClient, $baseUri, $headers, $queryParams, $streamHandler);

        $this->client = new Client($transporter);
    }

    /**
     * Generate estimate line items based on inquiry and optional floorplan data.
     *
     * @param  string  $inquiry  The customer inquiry describing the work needed
     * @param  array|null  $floorplanData  Optional parsed floorplan data (dimensions, room info)
     * @param  int  $vendorId  The vendor ID to filter line items
     * @return array{success: bool, line_items: array, reasoning: string, error?: string}
     */
    public function generateEstimate(string $inquiry, ?array $floorplanData = null, int $vendorId = 1): array
    {
        try {
            // Get available line items for this vendor
            $availableLineItems = $this->getAvailableLineItems($vendorId);

            // Narrow line items to relevant categories to avoid large prompts
            $availableLineItems = $this->filterRelevantLineItems($availableLineItems, $inquiry);

            // Get example estimates for context (bathroom remodels)
            $exampleEstimates = $this->getExampleEstimates($vendorId);

            // Build the prompt
            $prompt = $this->buildPrompt($inquiry, $floorplanData, $availableLineItems, $exampleEstimates);

            // Call OpenAI
            $response = $this->client->chat()->create([
                'model' => 'gpt-4',
                'messages' => [
                    ['role' => 'system', 'content' => $this->getSystemPrompt()],
                    ['role' => 'user', 'content' => $prompt],
                ],
                'max_tokens' => 1800,
                'temperature' => 0.3,
            ]);

            $content = $response['choices'][0]['message']['content'];

            // Parse the JSON response
            $result = $this->parseResponse($content);

            if ($result['success'] && ! empty($floorplanData)) {
                $result['line_items'] = $this->applyFloorplanQuantities($result['line_items'], $floorplanData);
            }

            return $result;
        } catch (\Exception $e) {
            $message = $e->getMessage();
            Log::error('EstimateAIService error: ' . $message);

            $friendlyMessage = $message;
            if (str_contains($message, 'You exceeded your current quota')) {
                $friendlyMessage = 'OpenAI API quota exceeded. Please update billing or use a different API key.';
            }

            return [
                'success' => false,
                'line_items' => [],
                'reasoning' => '',
                'error' => $friendlyMessage,
            ];
        }
    }

    /**
     * Apply AI-generated line items to an estimate section.
     */
    public function applyToEstimate(Estimate $estimate, EstimateSection $section, array $generatedItems): array
    {
        $createdItems = [];
        $currentOrder = $section->estimate_line_items()->max('order') ?? -1;

        foreach ($generatedItems as $item) {
            // Find the matching line item by ID
            $lineItem = LineItem::find($item['line_item_id']);

            if (! $lineItem) {
                continue;
            }

            $currentOrder++;
            $quantity = $item['quantity'] ?? 1;
            $cost = $item['cost'] ?? $lineItem->cost;
            $total = $quantity * $cost;

            $estimateLineItem = EstimateLineItem::create([
                'estimate_id' => $estimate->id,
                'line_item_id' => $lineItem->id,
                'section_id' => $section->id,
                'order' => $currentOrder,
                'name' => $lineItem->name,
                'category' => $lineItem->category,
                'sub_category' => $lineItem->sub_category,
                'unit_type' => $lineItem->unit_type,
                'quantity' => $quantity,
                'cost' => $cost,
                'total' => $total,
                'desc' => $item['desc'] ?? $lineItem->desc,
                'notes' => $item['notes'] ?? $lineItem->notes,
            ]);

            $createdItems[] = $estimateLineItem;
        }

        // Update section total
        $section->total = $section->estimate_line_items()->sum('total');
        $section->save();

        return $createdItems;
    }

    protected function getSystemPrompt(): string
    {
        return <<<'PROMPT'
You are an expert construction estimator for a home remodeling company. You specialize in bathroom, kitchen, and general home renovations.

Your job is to analyze customer inquiries and generate accurate estimates using the company's standard line items catalog.

IMPORTANT RULES:
1. Only use line items from the provided catalog - never invent new ones
2. Select quantities based on typical bathroom/room sizes unless specific dimensions are provided
3. Follow the logical order of construction phases: Demo → Framing → Plumbing Rough → Electrical Rough → Insulation → Drywall → Tile Prep → Tile → Finish Work → Painting
4. For "rip and replace" jobs, always include demolition first
5. For tile work, include cement boards and related prep work
6. For electrical work in bathrooms, include GFCI outlets (code requirement)
7. Always include exhaust fan for bathrooms (code requirement)
8. Consider supporting items (switches for new lights, patching for electrical work, etc.)

When given floorplan data, use the square footage to calculate quantities for:
- Floor tile (sq.ft.)
- Wall tile (sq.ft. - estimate wall area from floor area)
- Drywall pieces (1 piece = ~32 sq.ft.)
- Painting (estimate based on room size)

RESPONSE FORMAT:
Return a valid JSON object with this structure:
{
  "reasoning": "Brief explanation of your estimate logic",
  "line_items": [
    {
      "line_item_id": 123,
      "name": "Item Name",
      "quantity": 1.0,
      "cost": 100.00,
      "desc": "Optional modified description",
      "notes": "Optional notes"
    }
  ]
}
PROMPT;
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

    protected function getExampleEstimates(int $vendorId): array
    {
        // Get recent bathroom estimates as examples
        $estimates = Estimate::query()
            ->where('belongs_to_vendor_id', $vendorId)
            ->whereHas('project', function ($query) {
                $query->where('project_name', 'like', '%bath%');
            })
            ->with(['estimate_sections.estimate_line_items' => function ($query) {
                $query->orderBy('order');
            }])
            ->latest()
            ->limit(2)
            ->get();

        $examples = [];

        foreach ($estimates as $estimate) {
            $items = [];
            foreach ($estimate->estimate_sections as $section) {
                foreach ($section->estimate_line_items as $lineItem) {
                    $items[] = [
                        'name' => $lineItem->name,
                        'category' => $lineItem->category,
                        'quantity' => $lineItem->quantity,
                        'unit_type' => $lineItem->unit_type,
                        'cost' => $lineItem->cost,
                    ];
                }
            }
            if (! empty($items)) {
                $examples[] = [
                    'project' => $estimate->project?->project_name ?? 'Bathroom Remodel',
                    'items' => $items,
                ];
            }
        }

        return $examples;
    }

    protected function buildPrompt(string $inquiry, ?array $floorplanData, Collection $lineItems, array $examples): string
    {
        $prompt = "CUSTOMER INQUIRY:\n{$inquiry}\n\n";

        if ($floorplanData) {
            $prompt .= "FLOORPLAN DATA:\n";
            $prompt .= json_encode($floorplanData, JSON_PRETTY_PRINT);
            $prompt .= "\n\n";
        }

        $prompt .= "AVAILABLE LINE ITEMS CATALOG:\n";
        $prompt .= "Format: ID | Name | Category | SubCategory | UnitType | Cost\n";
        $prompt .= str_repeat('-', 80) . "\n";

        foreach ($lineItems as $item) {
            $prompt .= sprintf(
                "%d | %s | %s | %s | %s | $%.2f\n",
                $item->id,
                $item->name,
                $item->category,
                $item->sub_category ?? 'N/A',
                $item->unit_type,
                $item->cost
            );
        }

        if (! empty($examples)) {
            $prompt .= "\n\nEXAMPLE BATHROOM ESTIMATES (for reference):\n";
            foreach ($examples as $i => $example) {
                $prompt .= "\nExample " . ($i + 1) . " - {$example['project']}:\n";
                foreach ($example['items'] as $item) {
                    $prompt .= sprintf(
                        "  - %s (%s): Qty %.1f %s @ $%.2f\n",
                        $item['name'],
                        $item['category'],
                        $item['quantity'],
                        $item['unit_type'],
                        $item['cost']
                    );
                }
            }
        }

        $prompt .= "\n\nGenerate an estimate for this inquiry. Return valid JSON only.";

        return $prompt;
    }

    protected function applyFloorplanQuantities(array $items, array $floorplanData): array
    {
        $floorSqft = $floorplanData['floor_sqft'] ?? null;
        $wallSqft = $floorplanData['wall_sqft'] ?? null;
        $cementBoardSqft = $floorplanData['cement_board_sqft'] ?? null;

        if (! $floorSqft && ! $wallSqft && ! $cementBoardSqft) {
            return $items;
        }

        $lineItemIds = collect($items)
            ->pluck('line_item_id')
            ->filter()
            ->unique()
            ->values();

        $lineItems = LineItem::query()
            ->whereIn('id', $lineItemIds)
            ->get(['id', 'name', 'category', 'sub_category', 'unit_type'])
            ->keyBy('id');

        foreach ($items as $index => $item) {
            $lineItem = $lineItems->get($item['line_item_id'] ?? null);
            if (! $lineItem) {
                continue;
            }

            $name = strtolower($lineItem->name ?? ($item['name'] ?? ''));
            $subCategory = strtolower($lineItem->sub_category ?? '');
            $unitType = $lineItem->unit_type;

            if ($floorSqft && $unitType === 'sq.ft.' && str_contains($name, 'floor') && str_contains($name, 'tile')) {
                $items[$index]['quantity'] = round((float) $floorSqft, 2);
                continue;
            }

            if ($wallSqft && $unitType === 'sq.ft.' && str_contains($name, 'wall') && str_contains($name, 'tile')) {
                $items[$index]['quantity'] = round((float) $wallSqft, 2);
                continue;
            }

            $isCementBoard = str_contains($name, 'cement boards') || $subCategory === 'cement boards';
            if ($cementBoardSqft && $isCementBoard) {
                if ($unitType === 'pieces') {
                    $items[$index]['quantity'] = (float) ceil(((float) $cementBoardSqft) / 32);
                } else {
                    $items[$index]['quantity'] = round((float) $cementBoardSqft, 2);
                }
            }
        }

        return $items;
    }

    protected function filterRelevantLineItems(Collection $lineItems, string $inquiry): Collection
    {
        $keywords = strtolower($inquiry);

        $categoryMap = [
            'demo' => ['Demolition', 'Demo'],
            'demolition' => ['Demolition', 'Demo'],
            'tile' => ['Tiles', 'Drywall'],
            'tiles' => ['Tiles', 'Drywall'],
            'tub' => ['Plumbing', 'Tiles'],
            'bath' => ['Demolition', 'Plumbing', 'Electrical', 'Tiles', 'Drywall', 'Services', 'Painting'],
            'bathroom' => ['Demolition', 'Plumbing', 'Electrical', 'Tiles', 'Drywall', 'Services', 'Painting'],
            'vanity' => ['Plumbing', 'Carpentry', 'Services'],
            'electrical' => ['Electrical'],
            'light' => ['Electrical'],
            'lights' => ['Electrical'],
            'exhaust' => ['Electrical', 'HVAC'],
            'fan' => ['Electrical', 'HVAC'],
            'paint' => ['Painting'],
            'drywall' => ['Drywall'],
            'insulation' => ['Insulation'],
            'floor' => ['Tiles', 'Flooring'],
        ];

        $categories = collect();
        foreach ($categoryMap as $keyword => $mappedCategories) {
            if (str_contains($keywords, $keyword)) {
                $categories = $categories->merge($mappedCategories);
            }
        }

        if ($categories->isEmpty()) {
            return $lineItems->take(120);
        }

        $categories = $categories->unique()->values()->all();

        return $lineItems->filter(function ($item) use ($categories) {
            return in_array($item->category, $categories, true);
        })->values()->take(160);
    }

    protected function parseResponse(string $content): array
    {
        // Extract JSON from the response (in case there's extra text)
        if (preg_match('/\{[\s\S]*\}/', $content, $matches)) {
            $json = $matches[0];
        } else {
            $json = $content;
        }

        $data = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'success' => false,
                'line_items' => [],
                'reasoning' => '',
                'error' => 'Failed to parse AI response: ' . json_last_error_msg(),
            ];
        }

        return [
            'success' => true,
            'line_items' => $data['line_items'] ?? [],
            'reasoning' => $data['reasoning'] ?? '',
        ];
    }
}
