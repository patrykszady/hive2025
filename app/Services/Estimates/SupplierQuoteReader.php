<?php

namespace App\Services\Estimates;

use App\Support\Windows\WindowDiagram;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Reads a window and door supplier's quote or order acknowledgement — a PDF
 * or a photo — into a schedule: one item per unit with its size, colors,
 * glass, screen, grid, how it opens, and its LIST unit price, plus the
 * document's discount and the tax it charged. Same model call as
 * SubDocumentReader (which this extends for the file handling), with a
 * schema shaped for window orders: Advanced Window Corp's estimates and
 * Climate Solutions' acknowledgements are the two it was built on.
 */
class SupplierQuoteReader extends SubDocumentReader
{
    private const QUOTE_SYSTEM = <<<'PROMPT'
You read window and door suppliers' quotes, estimates and order acknowledgements for a general contractor.
Extract EVERY unit as its own item, in document order, numbered from 1. For each: the supplier's product code or series (e.g. "PW63", "6000 TT French In Door", "8100 2-Lite Slider"), a short plain name of what it is, the quantity, the width and height in inches as decimals (24, 59.25, 79.375 — convert fractions like 30 3/4 to 30.75), the exterior and interior colors if given, the glass package, the screen, the grid, and how it opens.
How it opens (the `opening` object): type is one of picture (fixed), casement, awning, hopper, double_hung, single_hung, slider (a sliding window), patio_door (a sliding door), french_door (hinged door panels), tilt_turn (a panel that tilts in at the top and swings), or product (anything that is not a window or door, e.g. blinds, hardware, a delivery fee). hinge is the hinge side as seen from inside (left/right for a casement or a single hinged panel; top for awning; bottom for hopper). For a slider or patio door give active_panel (left/right, the one that moves) and slide_direction (left/right, the way it moves to open; "opens right to left" means the right panel is active and slides left). For a two-panel french or tilt-turn door give panels: one entry per panel from left to right with that panel's hinge side and whether it tilts ("Right Hinge + Tilt" means hinge right, tilt true).
Prices: unit_price is the supplier's LIST unit price for the unit as printed on its line — before any document-level discount — and amount is that line's extended price; include per-unit add-ons (color, paint, upcharges) that the document rolls into the unit price. Never invent numbers; leave them null. Keep supplier remarks about a unit (e.g. "egress not met") in notes.
Also report the supplier's name, what the document is (document_type: quote, estimate, order, acknowledgement, invoice or other), the document number, its date (YYYY-MM-DD), the customer purchase-order name if any, the subtotal before discount, the discount percent and amount if any, the sales tax rate (as a percent) and amount, and the grand total.
Return JSON only, matching the schema.
PROMPT;

    /**
     * @return array{vendor_name: ?string, document_type: string, document_number: ?string, document_date: ?string, customer_po: ?string, subtotal: ?float, discount_percent: ?float, discount_amount: ?float, tax_rate: ?float, tax_amount: ?float, total: ?float, items: list<array<string, mixed>>, model: string}
     */
    public function readQuote(string $absolutePath, string $mimeType, string $originalName): array
    {
        $apiKey = (string) config('services.openai.api_key') ?: throw new RuntimeException('OpenAI API key missing (services.openai.api_key)');
        $model = (string) config('services.openai.sub_document_model', 'gpt-4.1');

        $response = Http::withToken($apiKey)
            ->timeout(180)
            ->retry(2, 3000, throw: false)
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => $model,
                'temperature' => 0,
                'messages' => [
                    ['role' => 'system', 'content' => self::QUOTE_SYSTEM],
                    ['role' => 'user', 'content' => $this->userContent($absolutePath, $mimeType, $originalName)],
                ],
                'response_format' => [
                    'type' => 'json_schema',
                    'json_schema' => ['name' => 'supplier_quote', 'strict' => true, 'schema' => self::quoteSchema()],
                ],
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('supplier_quote_read_http_'.$response->status().': '.mb_substr((string) $response->body(), 0, 300));
        }

        $parsed = json_decode((string) data_get($response->json(), 'choices.0.message.content'), true);

        if (! is_array($parsed) || ! is_array($parsed['items'] ?? null)) {
            throw new RuntimeException('supplier_quote_read_invalid_json');
        }

        $items = [];
        foreach ($parsed['items'] as $item) {
            $name = trim((string) ($item['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $opening = is_array($item['opening'] ?? null) ? $item['opening'] : [];
            $panels = [];
            foreach ((array) ($opening['panels'] ?? []) as $panel) {
                if (is_array($panel)) {
                    $panels[] = ['hinge' => self::side($panel['hinge'] ?? null), 'tilt' => (bool) ($panel['tilt'] ?? false)];
                }
            }
            $items[] = self::orient([
                'index' => count($items) + 1,
                'product_code' => self::clean($item['product_code'] ?? null),
                'name' => mb_substr($name, 0, 120),
                'quantity' => self::num($item['quantity'] ?? null) ?: 1.0,
                'width_in' => self::num($item['width_in'] ?? null, 3),
                'height_in' => self::num($item['height_in'] ?? null, 3),
                'color_exterior' => self::clean($item['color_exterior'] ?? null),
                'color_interior' => self::clean($item['color_interior'] ?? null),
                'glass' => self::clean($item['glass'] ?? null),
                'screen' => self::clean($item['screen'] ?? null),
                'grid' => self::clean($item['grid'] ?? null),
                'opening' => [
                    'type' => WindowDiagram::normaliseType($opening['type'] ?? null),
                    'hinge' => self::side($opening['hinge'] ?? null),
                    'tilt' => (bool) ($opening['tilt'] ?? false),
                    'active_panel' => self::side($opening['active_panel'] ?? null),
                    'slide_direction' => self::side($opening['slide_direction'] ?? null),
                    'panels' => $panels,
                ],
                'unit_price' => self::num($item['unit_price'] ?? null),
                'amount' => self::num($item['amount'] ?? null),
                'notes' => self::clean($item['notes'] ?? null),
            ]);
        }

        return [
            'vendor_name' => self::clean($parsed['vendor_name'] ?? null),
            'document_type' => in_array($parsed['document_type'] ?? null, ['quote', 'estimate', 'order', 'acknowledgement', 'invoice'], true) ? $parsed['document_type'] : 'quote',
            'document_number' => self::clean($parsed['document_number'] ?? null),
            'document_date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($parsed['document_date'] ?? '')) ? $parsed['document_date'] : null,
            'customer_po' => self::clean($parsed['customer_po'] ?? null),
            'subtotal' => self::num($parsed['subtotal'] ?? null),
            'discount_percent' => self::num($parsed['discount_percent'] ?? null),
            'discount_amount' => self::num($parsed['discount_amount'] ?? null),
            'tax_rate' => self::num($parsed['tax_rate'] ?? null, 3),
            'tax_amount' => self::num($parsed['tax_amount'] ?? null),
            'total' => self::num($parsed['total'] ?? null),
            'items' => $items,
            'model' => $model,
        ];
    }

    /**
     * A casement stands taller than wide. When one is read wider than tall the
     * two sizes were swapped on the document (Patryk, 2026-10-26: "any
     * casement where the width is more than the height should be reversed"),
     * so it is turned upright here — every casement in the section stands the
     * same way and its drawing is right. Applied when the quote is read and
     * again wherever an item is used, so quotes read before this rule follow it too.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    public static function orient(array $item): array
    {
        $width = (float) ($item['width_in'] ?? 0);
        $height = (float) ($item['height_in'] ?? 0);

        if (WindowDiagram::normaliseType(data_get($item, 'opening.type')) === 'casement' && $width > 0 && $height > 0 && $width > $height) {
            $item['width_in'] = $height;
            $item['height_in'] = $width;
        }

        return $item;
    }

    /** @return array<string, mixed> */
    public static function quoteSchema(): array
    {
        $nullableNumber = ['type' => ['number', 'null']];
        $nullableString = ['type' => ['string', 'null']];
        $side = ['type' => ['string', 'null'], 'enum' => ['left', 'right', 'top', 'bottom', null]];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['vendor_name', 'document_type', 'document_number', 'document_date', 'customer_po', 'subtotal', 'discount_percent', 'discount_amount', 'tax_rate', 'tax_amount', 'total', 'items'],
            'properties' => [
                'vendor_name' => $nullableString,
                'document_type' => ['type' => 'string', 'enum' => ['quote', 'estimate', 'order', 'acknowledgement', 'invoice', 'other']],
                'document_number' => $nullableString,
                'document_date' => $nullableString,
                'customer_po' => $nullableString,
                'subtotal' => $nullableNumber,
                'discount_percent' => $nullableNumber,
                'discount_amount' => $nullableNumber,
                'tax_rate' => $nullableNumber,
                'tax_amount' => $nullableNumber,
                'total' => $nullableNumber,
                'items' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['index', 'product_code', 'name', 'quantity', 'width_in', 'height_in', 'color_exterior', 'color_interior', 'glass', 'screen', 'grid', 'opening', 'unit_price', 'amount', 'notes'],
                        'properties' => [
                            'index' => ['type' => 'integer'],
                            'product_code' => $nullableString,
                            'name' => ['type' => 'string'],
                            'quantity' => $nullableNumber,
                            'width_in' => $nullableNumber,
                            'height_in' => $nullableNumber,
                            'color_exterior' => $nullableString,
                            'color_interior' => $nullableString,
                            'glass' => $nullableString,
                            'screen' => $nullableString,
                            'grid' => $nullableString,
                            'opening' => [
                                'type' => 'object',
                                'additionalProperties' => false,
                                'required' => ['type', 'hinge', 'tilt', 'active_panel', 'slide_direction', 'panels'],
                                'properties' => [
                                    'type' => ['type' => 'string', 'enum' => WindowDiagram::TYPES],
                                    'hinge' => $side,
                                    'tilt' => ['type' => 'boolean'],
                                    'active_panel' => $side,
                                    'slide_direction' => $side,
                                    'panels' => [
                                        'type' => 'array',
                                        'items' => [
                                            'type' => 'object',
                                            'additionalProperties' => false,
                                            'required' => ['hinge', 'tilt'],
                                            'properties' => ['hinge' => $side, 'tilt' => ['type' => 'boolean']],
                                        ],
                                    ],
                                ],
                            ],
                            'unit_price' => $nullableNumber,
                            'amount' => $nullableNumber,
                            'notes' => $nullableString,
                        ],
                    ],
                ],
            ],
        ];
    }

    private static function num(mixed $value, int $decimals = 2): ?float
    {
        return is_numeric($value) ? round((float) $value, $decimals) : null;
    }

    private static function clean(mixed $value): ?string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        return $value === '' ? null : mb_substr($value, 0, 250);
    }

    private static function side(mixed $value): ?string
    {
        $value = strtolower(trim((string) $value));

        return in_array($value, ['left', 'right', 'top', 'bottom'], true) ? $value : null;
    }
}
