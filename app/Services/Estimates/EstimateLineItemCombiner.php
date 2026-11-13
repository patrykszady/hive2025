<?php

namespace App\Services\Estimates;

use App\Models\Estimate;
use App\Models\EstimateAiDraft;
use App\Models\EstimateLineItem;
use App\Models\EstimateSection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Combines the same catalog item priced in several sections of an estimate
 * into one line in a section of its own — "Hardwood Refinish" in the dining
 * room, kitchen and sitting room becomes one Hardwood Refinish line under
 * "Flooring", quantities and totals added up (2026-11-13, for estimates
 * drafted room by room). The estimate's total never changes: the combined
 * line's total is the sum of the lines it replaces, and so are its
 * allowances. The replaced lines are removed the restorable way.
 *
 * A unit price is never invented: where the sections price the item
 * differently (tile at $22 in the kitchen, $24 in the bath) it becomes one
 * line per unit price in the combined section, each quantity × price as
 * the estimator priced it — not one line at an averaged price that
 * multiplies out to a different total (2026-11-13).
 *
 * The body of App\Livewire\Estimates\EstimateCombineLineItems.
 */
class EstimateLineItemCombiner
{
    /** The part of a group's key that stands for "no bid" (an estimate not yet accepted, or its original sections). */
    private const NO_BID = 0;

    /**
     * The catalog items that can be combined: on the estimate in two or more
     * sections. Credits and lines that carry one, supplier-quote lines (each
     * its own window) and lines locked by a signature are left alone.
     *
     * Money never crosses from one bid to another (the contract and each
     * change order are their own bid): an item is offered once per bid whose
     * sections share it, under a key of its own.
     *
     * `becomes` is what combining makes of it: one entry per line it turns
     * into — more than one when its sections price it differently.
     *
     * @return list<array{key: string, line_item_id: int, name: string, category: ?string, sub_category: ?string, unit_type: string, quantity: float, total: float, allowances: float, lines: list<array{id: int, section_id: int, section: string, quantity: float, cost: float, total: float}>, becomes: list<array{quantity: float, cost: float, total: float}>}>
     */
    public function candidates(Estimate $estimate): array
    {
        return $this->groups($estimate)
            ->map(function (Collection $lines, string $key) {
                $first = $lines->first();
                $lumpSum = $first->unit_type === 'no_unit';

                return [
                    'key' => $key,
                    'line_item_id' => (int) $first->line_item_id,
                    'name' => (string) $first->name,
                    'category' => $first->category,
                    'sub_category' => $first->sub_category,
                    'unit_type' => (string) $first->unit_type,
                    'quantity' => $lumpSum ? 1.0 : round((float) $lines->sum('quantity'), 2),
                    'total' => round((float) $lines->sum('total'), 2),
                    'allowances' => round((float) $lines->sum(fn (EstimateLineItem $line) => $line->allowances->sum('amount')), 2),
                    'lines' => $lines->map(fn (EstimateLineItem $line) => [
                        'id' => $line->id,
                        'section_id' => (int) $line->section_id,
                        'section' => filled($line->section?->name) ? (string) $line->section->name : 'Unnamed Section',
                        'quantity' => (float) $line->quantity,
                        'cost' => round((float) $line->cost, 2),
                        'total' => (float) $line->total,
                    ])->values()->all(),
                    'becomes' => $this->byPrice($lines)->map(fn (Collection $priced) => [
                        'quantity' => $lumpSum ? 1.0 : round((float) $priced->sum('quantity'), 2),
                        'cost' => $lumpSum ? round((float) $priced->sum('total'), 2) : round((float) $priced->first()->cost, 2),
                        'total' => round((float) $priced->sum('total'), 2),
                    ])->values()->all(),
                ];
            })
            ->sortBy([['category', 'asc'], ['name', 'asc']])
            ->values()
            ->all();
    }

    /**
     * Combine the chosen items, each into one line of the section named —
     * an unlocked section already carrying that name (and on the same bid as
     * the lines), or a new one at the end of the estimate, on that bid.
     *
     * @param  list<string>  $keys  as candidates() lists them
     * @return array{section: EstimateSection, combined: list<EstimateLineItem>, items: int, removed: int, emptied: list<string>}  `combined` holds every line made — more than `items` when an item was priced more than one way
     */
    public function combine(Estimate $estimate, array $keys, string $sectionName): array
    {
        $groups = $this->groups($estimate)->only(array_map('strval', $keys));
        $sectionName = trim($sectionName);

        if ($groups->isEmpty() || $sectionName === '') {
            throw new \InvalidArgumentException('Nothing to combine.');
        }

        return DB::transaction(function () use ($estimate, $groups, $sectionName) {
            $combined = [];
            $removed = 0;
            $touched = [];
            $targets = [];

            foreach ($groups as $lines) {
                $bidId = $lines->first()->section->bid_id;
                $section = $targets[$bidId ?? self::NO_BID] ??= $this->targetSection($estimate, $sectionName, $bidId);

                foreach ($this->byPrice($lines) as $priced) {
                    $line = $this->combineInto($section, $priced);
                    $this->tellDrafts($priced, $line);
                    $combined[] = $line;
                }

                foreach ($lines as $replaced) {
                    $touched[$replaced->section_id] = true;
                    $replaced->delete();
                    $removed++;
                }
            }

            // Totals re-read rather than trusted to the running adjustments.
            $emptied = [];
            $targetIds = array_map(fn (EstimateSection $target) => $target->id, array_values($targets));
            $section = array_values($targets)[0];

            foreach (array_unique([...array_keys($touched), ...$targetIds]) as $sectionId) {
                $affected = EstimateSection::query()->find($sectionId);
                if ($affected === null) {
                    continue;
                }

                $affected->total = $affected->estimate_line_items()->sum('total');
                $affected->save();

                if ($affected->bid) {
                    $affected->bid->amount = EstimateSection::query()->where('bid_id', $affected->bid_id)->sum('total');
                    $affected->bid->save();
                }

                if (! in_array($affected->id, $targetIds, true) && ! $affected->estimate_line_items()->exists()) {
                    $emptied[] = filled($affected->name) ? (string) $affected->name : 'Unnamed Section';
                }
            }

            return ['section' => $section->fresh(), 'combined' => $combined, 'items' => $groups->count(), 'removed' => $removed, 'emptied' => $emptied];
        });
    }

    /**
     * The section a combined line goes in: an unlocked one of that name on
     * the same bid as the lines it replaces, or a new one on that bid. Never
     * through the "+ Section" path, which on a running job opens a new change
     * order — combining must not turn contract money into a change order.
     */
    protected function targetSection(Estimate $estimate, string $name, ?int $bidId): EstimateSection
    {
        return $estimate->estimate_sections()->get()
            ->first(fn (EstimateSection $candidate) => Str::lower(trim((string) $candidate->name)) === Str::lower($name)
                && $candidate->bid_id === $bidId
                && ! $candidate->isLocked())
            ?? EstimateSection::create(['estimate_id' => $estimate->id, 'name' => $name, 'total' => 0.00, 'bid_id' => $bidId]);
    }

    /**
     * The estimate's combinable lines by catalog item and bid ("{item}" or
     * "{item}-b{bid}"), only where the item is in two or more sections of
     * that bid.
     *
     * @return Collection<string, Collection<int, EstimateLineItem>>
     */
    protected function groups(Estimate $estimate): Collection
    {
        $locked = $estimate->estimate_sections()->get()->filter(fn (EstimateSection $section) => $section->isLocked())->pluck('id')->all();

        return EstimateLineItem::query()
            ->where('estimate_id', $estimate->id)
            ->whereNotNull('line_item_id')
            ->whereNull('credit_for_id')
            ->whereNull('window_spec')
            ->whereDoesntHave('credits')
            ->when($locked !== [], fn ($query) => $query->whereNotIn('section_id', $locked))
            ->whereHas('section')
            ->with(['section', 'allowances'])
            ->get()
            ->toBase()
            ->sortBy(fn (EstimateLineItem $line) => [$line->section->order, $line->order])
            ->groupBy(fn (EstimateLineItem $line) => $line->line_item_id.($line->section->bid_id ? '-b'.$line->section->bid_id : ''))
            ->filter(fn (Collection $lines) => $lines->pluck('section_id')->unique()->count() > 1
                && $lines->pluck('unit_type')->unique()->count() === 1);
    }

    /**
     * An item's lines by the unit price each carries, in the order the
     * estimate lists them: every set becomes one combined line. Lump sums
     * have no unit price to disagree on — they are always one line, the sum
     * of the lumps.
     *
     * @param  Collection<int, EstimateLineItem>  $lines
     * @return Collection<int, Collection<int, EstimateLineItem>>
     */
    protected function byPrice(Collection $lines): Collection
    {
        if ($lines->first()->unit_type === 'no_unit') {
            return collect([$lines->values()]);
        }

        return $lines->groupBy(fn (EstimateLineItem $line) => number_format((float) $line->cost, 2, '.', ''))->values();
    }

    /**
     * One line for all of them — lines that share a unit price (byPrice):
     * quantities added up (a lump sum stays one lump), the total exactly the
     * sum of theirs, the unit price the one they share. Where each quantity
     * came from goes into the notes, since the sections no longer say it.
     *
     * @param  Collection<int, EstimateLineItem>  $lines
     */
    protected function combineInto(EstimateSection $section, Collection $lines): EstimateLineItem
    {
        $first = $lines->first();
        $lumpSum = $first->unit_type === 'no_unit';
        $total = round((float) $lines->sum('total'), 2);
        $quantity = $lumpSum ? 1.0 : round((float) $lines->sum('quantity'), 2);
        $cost = $lumpSum ? $total : round((float) $first->cost, 2);

        $from = $lines
            ->groupBy('section_id')
            ->map(function (Collection $inSection) use ($lumpSum) {
                $name = filled($inSection->first()->section?->name) ? $inSection->first()->section->name : 'Unnamed Section';

                return $lumpSum ? $name : $name.' '.$this->number((float) $inSection->sum('quantity')).' '.$inSection->first()->unit_type;
            })
            ->implode(' · ');

        $line = EstimateLineItem::create([
            'estimate_id' => $section->estimate_id,
            'line_item_id' => $first->line_item_id,
            'section_id' => $section->id,
            'name' => $first->name,
            'category' => $first->category,
            'sub_category' => $first->sub_category,
            'unit_type' => $first->unit_type,
            'quantity' => $quantity,
            'cost' => $cost,
            'total' => $total,
            'desc' => $this->distinct($lines->pluck('desc')),
            'notes' => $this->distinct($lines->pluck('notes')->push($from)),
        ]);

        // Written straight onto the line. The line-item form's own path also
        // updates the catalog's default for each allowance — right when an
        // estimator sets one, wrong here: three baths' "Faucet $250" combined
        // to $750 must not become every future bath's default.
        foreach ($this->allowancesOf($lines, $quantity) as $allowance) {
            $line->allowances()->create($allowance);
        }

        return $line;
    }

    /**
     * Their allowances as the combined line's: one per description. A
     * per-unit allowance every line carries at the same rate stays per unit
     * (and so follows the combined quantity); anything else becomes a lump
     * sum of what the lines carried, so the allowance money is unchanged.
     *
     * @param  Collection<int, EstimateLineItem>  $lines
     * @return list<array{line_item_allowance_id: ?int, description: string, pricing_mode: string, unit_amount: ?float, amount: float}>
     */
    protected function allowancesOf(Collection $lines, float $quantity): array
    {
        return $lines
            ->flatMap(fn (EstimateLineItem $line) => $line->allowances->map(fn ($allowance) => ['line' => $line->id, 'allowance' => $allowance]))
            ->groupBy(fn (array $row) => Str::lower(trim((string) $row['allowance']->description)))
            ->map(function (Collection $rows) use ($lines, $quantity) {
                $allowances = $rows->pluck('allowance');
                $rates = $allowances->map(fn ($allowance) => $allowance->pricing_mode === 'per_unit' && $allowance->unit_amount !== null ? (string) $allowance->unit_amount : null);
                $sameRateOnEveryLine = $rates->filter()->count() === $allowances->count()
                    && $rates->unique()->count() === 1
                    && $rows->pluck('line')->unique()->count() === $lines->count()
                    && $allowances->count() === $lines->count();

                return [
                    'line_item_allowance_id' => $allowances->first()->line_item_allowance_id,
                    'description' => (string) $allowances->first()->description,
                    'pricing_mode' => $sameRateOnEveryLine ? 'per_unit' : 'lump_sum',
                    'unit_amount' => $sameRateOnEveryLine ? (float) $rates->first() : null,
                    // Per unit it follows the combined quantity; otherwise it is exactly what the lines carried.
                    'amount' => $sameRateOnEveryLine ? round((float) $rates->first() * $quantity, 2) : round((float) $allowances->sum('amount'), 2),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * The generator's memory of an AI draft is told its line went into the
     * combined one, and with how much. Otherwise the draft would read as "the
     * estimator removed Hardwood Refinish from the dining room" — a
     * correction nobody made — and removing the draft later would leave its
     * money standing in the combined line.
     *
     * @param  Collection<int, EstimateLineItem>  $lines
     */
    protected function tellDrafts(Collection $lines, EstimateLineItem $combined): void
    {
        $totals = $lines->mapWithKeys(fn (EstimateLineItem $line) => [(int) $line->id => (float) $line->total])->all();

        EstimateAiDraft::query()
            ->where('estimate_id', $combined->estimate_id)
            ->whereIn('section_id', $lines->pluck('section_id')->unique())
            ->get()
            ->each(function (EstimateAiDraft $draft) use ($totals, $combined) {
                $items = collect($draft->drafted_items ?? []);
                $marked = $items->map(fn (array $item) => isset($totals[(int) ($item['estimate_line_item_id'] ?? 0)])
                    ? ['combined_into' => $combined->id, 'combined_total' => $totals[(int) $item['estimate_line_item_id']]] + $item
                    : $item);

                if ($marked->all() !== $items->all()) {
                    $draft->forceFill(['drafted_items' => $marked->all()])->save();
                }
            });
    }

    /** The different texts among them, each once, one under another. */
    protected function distinct(Collection $texts): ?string
    {
        $distinct = $texts->map(fn ($text) => trim((string) $text))->filter()->unique()->values();

        return $distinct->isEmpty() ? null : $distinct->implode("\n");
    }

    protected function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
