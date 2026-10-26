<?php

namespace App\Services\Estimates;

use App\Models\EstimateLineItem;
use App\Models\EstimateSection;
use App\Models\EstimateSubDocument;
use App\Models\LineItem;
use App\Models\Vendor;
use App\Support\Windows\WindowDiagram;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Turns a read supplier quote (EstimateSubDocument, kind quote) into a
 * section of our estimate, written the way an admin would add it: one line
 * per unit, named by what it is and its size ("Casement Window 30¾ × 36⅛"),
 * priced at the supplier's LIST price plus the supplier's markup with the
 * tax folded in (SupplierQuotePricer), carrying the drawing spec the
 * estimate and its PDFs render (WindowDiagram). The supplier, its discount
 * and its document number never appear on the lines; the document itself
 * keeps the audit trail in `applied`. The markup is remembered on the
 * supplier vendor (options.quote_markup_percent) for the next quote.
 */
class SupplierQuoteSectionWriter
{
    public const DEFAULT_SECTION_NAME = 'Windows & Doors';

    public const CATEGORY = 'Windows';

    public const SUB_CATEGORY = 'Material';

    /** The catalog item each opening type lands on, created on first use. */
    public const CATALOG_NAMES = [
        'picture' => 'Picture Window',
        'casement' => 'Casement Window',
        'awning' => 'Awning Window',
        'hopper' => 'Hopper Window',
        'double_hung' => 'Double-Hung Window',
        'single_hung' => 'Single-Hung Window',
        'slider' => 'Sliding Window',
        'patio_door' => 'Sliding Patio Door',
        'french_door' => 'French Door',
        'tilt_turn' => 'Tilt & Turn Door',
        'product' => 'Windows & Doors Item',
    ];

    public function __construct(private EstimateSectionWriter $sections) {}

    public function write(EstimateSubDocument $document, float $markupPercent, ?string $sectionName = null): EstimateSection
    {
        if ($document->kind !== EstimateSubDocument::KIND_QUOTE || $document->status !== EstimateSubDocument::STATUS_MATCHED) {
            throw new RuntimeException('Only a read supplier quote can be added to the estimate.');
        }

        $estimate = $document->estimate;
        $read = (array) $document->document;
        $items = array_values((array) ($read['items'] ?? []));

        if ($items === []) {
            throw new RuntimeException('The quote has no lines to add.');
        }

        $priced = SupplierQuotePricer::price($items, (float) ($read['tax_amount'] ?? 0), $markupPercent);
        $pricedRows = collect($priced['rows'])->keyBy('index');
        $sectionName = trim((string) $sectionName) !== '' ? trim((string) $sectionName) : self::DEFAULT_SECTION_NAME;

        $section = DB::transaction(function () use ($document, $estimate, $items, $priced, $pricedRows, $markupPercent, $sectionName) {
            $section = $this->sections->addSection($estimate, $sectionName);
            $written = [];

            foreach ($items as $item) {
                $row = $pricedRows[$item['index']];
                $catalog = $this->catalogItemFor((int) $estimate->belongs_to_vendor_id, (string) data_get($item, 'opening.type', 'product'));

                $line = EstimateLineItem::create([
                    'estimate_id' => $estimate->id,
                    'line_item_id' => $catalog->id,
                    'section_id' => $section->id,
                    'order' => count($written) + 1,
                    'name' => self::lineName($item),
                    'category' => $catalog->category,
                    'sub_category' => $catalog->sub_category,
                    'unit_type' => 'pieces',
                    'quantity' => $row['quantity'],
                    'cost' => $row['client_unit_price'],
                    'total' => $row['client_total'],
                    'desc' => self::description($item),
                    'notes' => $item['notes'] ?? null,
                    'window_spec' => self::spec($item),
                ]);

                $written[] = [
                    'estimate_line_item_id' => $line->id,
                    'index' => $item['index'],
                    'name' => $line->name,
                    'quantity' => $row['quantity'],
                    'list_unit_price' => $row['list_unit_price'],
                    'markup_unit' => $row['markup_unit'],
                    'tax_unit' => $row['tax_unit'],
                    'client_unit_price' => $row['client_unit_price'],
                    'client_total' => $row['client_total'],
                ];
            }

            $document->items()->update(['applied' => true]);
            $document->update([
                'status' => EstimateSubDocument::STATUS_APPLIED,
                'applied_at' => now(),
                'markup_percent' => $markupPercent,
                'estimate_section_id' => $section->id,
                'applied' => [
                    'section_id' => $section->id,
                    'section_name' => $sectionName,
                    'markup_percent' => $markupPercent,
                    'lines' => $written,
                    'totals' => ['list_subtotal' => $priced['list_subtotal'], 'markup_total' => $priced['markup_total'], 'tax_total' => $priced['tax_total'], 'client_total' => $priced['client_total']],
                ],
            ]);

            return $section;
        });

        if ($document->sub_vendor_id) {
            self::rememberMarkup((int) $document->sub_vendor_id, $markupPercent);
        }

        return $section->fresh();
    }

    /** The markup last applied to this supplier's quotes, if any. */
    public static function rememberedMarkup(?int $vendorId): ?float
    {
        if (! $vendorId) {
            return null;
        }

        $options = Vendor::withoutGlobalScopes()->whereKey($vendorId)->value('options');
        $options = is_string($options) ? json_decode($options, true) : (array) $options;
        $markup = $options['quote_markup_percent'] ?? null;

        return is_numeric($markup) ? (float) $markup : null;
    }

    public static function rememberMarkup(int $vendorId, float $markupPercent): void
    {
        $options = Vendor::withoutGlobalScopes()->whereKey($vendorId)->value('options');
        $options = is_string($options) ? (json_decode($options, true) ?: []) : (array) $options;
        $options['quote_markup_percent'] = round($markupPercent, 2);

        Vendor::withoutGlobalScopes()->whereKey($vendorId)->update(['options' => json_encode($options)]);
    }

    /** "Casement Window 30¾ × 36⅛" — or the supplier's own name for anything that is not a window. @param array<string, mixed> $item */
    public static function lineName(array $item): string
    {
        $item = SupplierQuoteReader::orient($item);
        $type = WindowDiagram::normaliseType(data_get($item, 'opening.type'));
        $size = self::size($item);

        if ($type === 'product') {
            return trim((string) ($item['name'] ?? self::CATALOG_NAMES['product']));
        }

        return trim(self::CATALOG_NAMES[$type].($size ? ' '.$size : ''));
    }

    /** @param array<string, mixed> $item */
    public static function size(array $item): ?string
    {
        $item = SupplierQuoteReader::orient($item);
        $w = (float) ($item['width_in'] ?? 0);
        $h = (float) ($item['height_in'] ?? 0);

        return $w > 0 && $h > 0 ? WindowDiagram::inches($w).' × '.WindowDiagram::inches($h) : null;
    }

    /** What the homeowner reads under the line: size, how it opens, colors, glass, screen, grid. @param array<string, mixed> $item */
    public static function description(array $item): string
    {
        $item = SupplierQuoteReader::orient($item);
        $parts = [];
        $size = self::size($item);
        $opening = self::openingSentence($item);

        if ($size) {
            $parts[] = $size.' in.';
        }
        if ($opening) {
            $parts[] = $opening;
        }
        $colors = array_filter([
            $item['color_exterior'] ?? null ? 'exterior '.$item['color_exterior'] : null,
            $item['color_interior'] ?? null ? 'interior '.$item['color_interior'] : null,
        ]);
        if ($colors !== []) {
            $parts[] = ucfirst(implode(', ', $colors));
        }
        foreach (['glass' => 'Glass: ', 'screen' => 'Screen: ', 'grid' => 'Grid: '] as $key => $label) {
            if (! empty($item[$key])) {
                $parts[] = $label.$item[$key];
            }
        }

        return implode('. ', array_map(fn ($part) => rtrim($part, '.'), $parts)).($parts !== [] ? '.' : '');
    }

    /** @param array<string, mixed> $item */
    private static function openingSentence(array $item): ?string
    {
        $opening = (array) ($item['opening'] ?? []);
        $type = WindowDiagram::normaliseType($opening['type'] ?? null);
        $side = fn ($value) => in_array($value, ['left', 'right'], true) ? $value : null;

        return match ($type) {
            'picture' => 'Fixed picture window',
            'casement' => 'Casement'.($side($opening['hinge'] ?? null) ? ', hinged on the '.$side($opening['hinge']) : '').(! empty($opening['tilt']) ? ', tilts in' : ''),
            'awning' => 'Awning, hinged at the top',
            'hopper' => 'Hopper, hinged at the bottom',
            'double_hung' => 'Double-hung, both sashes slide',
            'single_hung' => 'Single-hung, bottom sash slides',
            'slider' => self::slidingSentence('Sliding window', $opening, $side),
            'patio_door' => self::slidingSentence('Sliding patio door', $opening, $side),
            'french_door', 'tilt_turn' => self::panelsSentence($type === 'tilt_turn' ? 'Tilt & turn door' : 'French door', $opening, $side),
            default => null,
        };
    }

    /** @param array<string, mixed> $opening */
    private static function slidingSentence(string $what, array $opening, callable $side): string
    {
        $active = $side($opening['active_panel'] ?? null);
        $direction = $side($opening['slide_direction'] ?? null);

        if (! $active && ! $direction) {
            return $what;
        }

        return $what.', '.($active ? $active.' panel' : 'one panel').' slides'.($direction ? ' '.$direction : '');
    }

    /** @param array<string, mixed> $opening */
    private static function panelsSentence(string $what, array $opening, callable $side): string
    {
        $panels = array_values(array_filter((array) ($opening['panels'] ?? []), 'is_array'));

        if ($panels === []) {
            $hinge = $side($opening['hinge'] ?? null);

            return $what.($hinge ? ', hinged on the '.$hinge : '').(! empty($opening['tilt']) ? ', tilts in' : '');
        }

        $described = [];
        foreach ($panels as $i => $panel) {
            $hinge = $side($panel['hinge'] ?? null);
            $described[] = ($i === 0 ? 'left' : 'right').' panel'.($hinge ? ' hinged '.$hinge : '').(! empty($panel['tilt']) ? ', tilts in' : '');
        }

        return $what.': '.implode('; ', $described);
    }

    /** The drawing's spec, kept on the line (estimate_line_items.window_spec). @param array<string, mixed> $item @return array<string, mixed> */
    public static function spec(array $item): array
    {
        $item = SupplierQuoteReader::orient($item);
        $opening = (array) ($item['opening'] ?? []);

        return [
            'type' => WindowDiagram::normaliseType($opening['type'] ?? null),
            'width_in' => (float) ($item['width_in'] ?? 0) ?: null,
            'height_in' => (float) ($item['height_in'] ?? 0) ?: null,
            'hinge' => $opening['hinge'] ?? null,
            'tilt' => (bool) ($opening['tilt'] ?? false),
            'active_panel' => $opening['active_panel'] ?? null,
            'slide_direction' => $opening['slide_direction'] ?? null,
            'panels' => array_values(array_filter((array) ($opening['panels'] ?? []), 'is_array')),
        ];
    }

    /**
     * The company's catalog item for an opening type, created the first time
     * a quote brings one — without LineItemObserver, which stamps the vendor
     * from the signed-in user (there is none on the queue).
     */
    private function catalogItemFor(int $vendorId, string $type): LineItem
    {
        $type = WindowDiagram::normaliseType($type);
        $name = self::CATALOG_NAMES[$type];

        $existing = LineItem::query()->where('belongs_to_vendor_id', $vendorId)->where('name', $name)->orderBy('id')->first();

        if ($existing) {
            return $existing;
        }

        return LineItem::withoutEvents(fn () => LineItem::query()->create([
            'name' => $name,
            'desc' => $type === 'product' ? 'Supplied with the window and door order.' : 'Supply of a '.strtolower($name).', sized and configured per the line.',
            'notes' => null,
            'category' => self::CATEGORY,
            'sub_category' => self::SUB_CATEGORY,
            'unit_type' => 'pieces',
            'cost' => 0,
            'belongs_to_vendor_id' => $vendorId,
        ]));
    }
}
