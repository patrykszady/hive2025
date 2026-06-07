<?php

namespace App\Services;

use App\Models\EstimateLineItemAllowance;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class AllowanceAggregator
{
    /**
     * Words that carry no concept meaning and are stripped before grouping
     * allowances by concept (e.g. "Tile allowance" and "Tile purchase" both
     * collapse to the "tile" concept).
     *
     * @var array<int, string>
     */
    private const FILLER = [
        'allowance', 'allowances', 'purchase', 'purchased', 'purchases', 'budget',
        'budgets', 'all', 'approx', 'approximately', 'est', 'estimate', 'estimated',
        'for', 'the', 'of', 'a', 'an', 'and', 'to', 'with', 'per',
    ];

    /**
     * Unit tokens stripped from descriptions so they never become concept words.
     *
     * @var array<int, string>
     */
    private const UNITS = [
        'sqft', 'sq', 'ft', 'sf', 'lf', 'lnft', 'ln', 'ea', 'each', 'unit', 'units',
        'yd', 'yard', 'yards', 'cy', 'hr', 'hour', 'hours', 'pc', 'pcs', 'roll', 'rolls',
    ];

    /**
     * Collapse raw estimate-line-item allowances into canonical "like" allowances,
     * one row per global line item + concept, with the dominant per-unit price.
     *
     * Each allowance must have its `estimateLineItem` relation loaded (and that
     * line item's `line_item` relation when a global name is desired).
     *
     * @param  Collection<int, EstimateLineItemAllowance>  $allowances
     * @return Collection<int, array{
     *     line_item_id: int,
     *     line_item_name: string,
     *     unit_type: ?string,
     *     description: string,
     *     unit_amount: ?float,
     *     usage_count: int,
     * }>
     */
    public function aggregate(Collection $allowances): Collection
    {
        return $allowances
            ->filter(fn (EstimateLineItemAllowance $allowance) => $allowance->estimateLineItem?->line_item_id)
            ->groupBy(fn (EstimateLineItemAllowance $allowance) => $allowance->estimateLineItem->line_item_id)
            ->map(fn (Collection $group) => $this->canonicalizeLineItem($group))
            ->flatten(1)
            ->values();
    }

    /**
     * Build canonical allowance rows for a single line item's allowances.
     *
     * @param  Collection<int, EstimateLineItemAllowance>  $allowances
     * @return Collection<int, array<string, mixed>>
     */
    private function canonicalizeLineItem(Collection $allowances): Collection
    {
        $entries = $allowances->map(fn (EstimateLineItemAllowance $allowance) => [
            'model' => $allowance,
            'words' => $this->conceptWords($allowance->description),
            'label' => $this->cleanLabel($allowance->description),
            'per_unit' => $this->parsePerUnit($allowance),
            'derived' => $this->derivePerUnit($allowance),
            'created_at' => $allowance->created_at,
        ])->values();

        return $this->cluster($entries)->map(function (Collection $cluster) {
            $lineItem = $cluster->first()['model']->estimateLineItem;

            return [
                'line_item_id' => $lineItem->line_item_id,
                'line_item_name' => $lineItem->line_item?->name ?? $lineItem->name,
                'unit_type' => $lineItem->unit_type,
                'description' => $this->representativeLabel($cluster, $lineItem->line_item?->name ?? $lineItem->name),
                'unit_amount' => $this->dominantPerUnit($cluster),
                'usage_count' => $cluster->count(),
            ];
        })->values();
    }

    /**
     * Cluster entries whose concept word-sets are subset-related (e.g. "tile"
     * joins "wall tile"), keeping distinct concepts (e.g. "grout") apart.
     *
     * @param  Collection<int, array<string, mixed>>  $entries
     * @return Collection<int, Collection<int, array<string, mixed>>>
     */
    private function cluster(Collection $entries): Collection
    {
        $clusters = collect();

        foreach ($entries as $entry) {
            $target = $clusters->first(fn (array $cluster) => $this->wordsRelated($entry['words'], $cluster['words']));

            if ($target === null) {
                $clusters->push([
                    'words' => $entry['words'],
                    'entries' => collect([$entry]),
                ]);

                continue;
            }

            $target['entries']->push($entry);
            $target['words'] = array_values(array_unique(array_merge($target['words'], $entry['words'])));

            $clusters = $clusters->map(fn (array $cluster) => $cluster['entries'] === $target['entries'] ? $target : $cluster);
        }

        return $clusters->map(fn (array $cluster) => $cluster['entries']);
    }

    /**
     * Two concept word-sets are related when they share a word and one is a
     * subset of the other (or either is empty, which matches anything).
     *
     * @param  array<int, string>  $a
     * @param  array<int, string>  $b
     */
    private function wordsRelated(array $a, array $b): bool
    {
        if ($a === [] || $b === []) {
            return true;
        }

        $intersection = array_intersect($a, $b);

        if ($intersection === []) {
            return false;
        }

        return count($intersection) === count($a) || count($intersection) === count($b);
    }

    /**
     * The dominant (modal) per-unit price for a cluster, preferring prices that
     * are explicit or parsed from text; falling back to the median derived
     * (amount ÷ quantity) value only when no textual price exists.
     *
     * @param  Collection<int, array<string, mixed>>  $cluster
     */
    private function dominantPerUnit(Collection $cluster): ?float
    {
        $prices = $cluster->pluck('per_unit')->filter(fn ($price) => $price !== null);

        if ($prices->isNotEmpty()) {
            return $this->modalPrice($cluster->filter(fn (array $entry) => $entry['per_unit'] !== null));
        }

        $derived = $cluster->pluck('derived')->filter(fn ($price) => $price !== null)->sort()->values();

        if ($derived->isEmpty()) {
            return null;
        }

        return round((float) $derived->get((int) floor(($derived->count() - 1) / 2)), 2);
    }

    /**
     * Most frequent per-unit price in a cluster, breaking ties by most recent use.
     *
     * @param  Collection<int, array<string, mixed>>  $entries
     */
    private function modalPrice(Collection $entries): float
    {
        return (float) $entries
            ->groupBy(fn (array $entry) => number_format((float) $entry['per_unit'], 2, '.', ''))
            ->map(fn (Collection $group, string $price) => [
                'price' => (float) $price,
                'count' => $group->count(),
                'latest' => $group->max('created_at'),
            ])
            ->sortByDesc(fn (array $stats) => [$stats['count'], (string) $stats['latest']])
            ->first()['price'];
    }

    /**
     * The most representative human label for a cluster: the most frequent
     * cleaned description, breaking ties by shortest then most recent.
     *
     * @param  Collection<int, array<string, mixed>>  $cluster
     */
    private function representativeLabel(Collection $cluster, ?string $fallback): string
    {
        $label = $cluster
            ->filter(fn (array $entry) => $entry['label'] !== '')
            ->groupBy(fn (array $entry) => Str::lower($entry['label']))
            ->map(fn (Collection $group) => [
                'label' => $group->first()['label'],
                'count' => $group->count(),
                'length' => Str::length($group->first()['label']),
                'latest' => $group->max('created_at'),
            ])
            ->sortBy(fn (array $stats) => [-$stats['count'], $stats['length'], '-'.(string) $stats['latest']])
            ->first()['label'] ?? null;

        return Str::title($label ?? (string) $fallback);
    }

    /**
     * Extract the per-unit price explicitly stored or embedded in the
     * description text (e.g. "$5/sqft", "($7 / sq ft)"). Lump-sum allowances
     * never carry a per-unit price.
     */
    private function parsePerUnit(EstimateLineItemAllowance $allowance): ?float
    {
        if ($allowance->pricing_mode === 'lump_sum') {
            return null;
        }

        if ($allowance->unit_amount !== null) {
            return (float) $allowance->unit_amount;
        }

        if (preg_match('/\$\s*(\d+(?:\.\d{1,2})?)/', (string) $allowance->description, $matches) === 1) {
            return (float) $matches[1];
        }

        return null;
    }

    /**
     * The per-unit price derived from the recorded total and the line item
     * quantity, used only as a last resort when no textual price exists.
     * Lump-sum allowances are never derived into a per-unit price.
     */
    private function derivePerUnit(EstimateLineItemAllowance $allowance): ?float
    {
        if ($allowance->pricing_mode === 'lump_sum') {
            return null;
        }

        $quantity = (float) ($allowance->estimateLineItem->quantity ?? 0);
        $amount = (float) $allowance->amount;

        if ($quantity <= 0 || $amount <= 0) {
            return null;
        }

        return round($amount / $quantity, 2);
    }

    /**
     * Significant concept words from a description: price, units, punctuation,
     * filler words, and short tokens removed.
     *
     * @return array<int, string>
     */
    private function conceptWords(string $description): array
    {
        $text = $this->stripPrice(Str::lower($description));
        $text = preg_replace('/[^a-z\s]/', ' ', $text) ?? '';

        return collect(preg_split('/\s+/', trim($text)) ?: [])
            ->filter(fn (string $word) => $word !== '')
            ->reject(fn (string $word) => in_array($word, self::FILLER, true))
            ->reject(fn (string $word) => in_array($word, self::UNITS, true))
            ->reject(fn (string $word) => Str::length($word) < 2)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * A cleaned, display-ready label: the description with the price token and
     * surrounding punctuation removed, whitespace collapsed.
     */
    private function cleanLabel(string $description): string
    {
        $text = $this->stripPrice($description);
        $text = preg_replace('/[\s:.\-()\[\]]+$/', '', $text) ?? $text;
        $text = preg_replace('/^[\s:.\-()\[\]]+/', '', $text) ?? $text;
        $text = preg_replace('/\s{2,}/', ' ', $text) ?? $text;

        return trim($text);
    }

    /**
     * Remove a "$X", "$X/unit", or "($X per unit)" price token from text.
     */
    private function stripPrice(string $text): string
    {
        $unitPattern = implode('|', array_map('preg_quote', self::UNITS));

        $patterns = [
            '/\(?\s*\$\s*\d+(?:\.\d+)?\s*(?:\/|per)\s*(?:'.$unitPattern.')?\.?\s*\)?/i',
            '/\$\s*\d+(?:\.\d+)?/',
        ];

        return trim((string) preg_replace($patterns, ' ', $text));
    }
}
