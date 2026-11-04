<?php

namespace App\Services\Estimates;

use App\Models\Bid;
use App\Models\Estimate;
use App\Models\EstimateLineItem;
use App\Models\EstimateSection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Marks up every line item of an estimate section — or of the whole
 * estimate — at once (2026-11-03, estimate 293; from the section's menu),
 * and reverts it.
 *
 * Each line's unit price goes up by "about" the percentage: a whole-dollar
 * price stays whole, any other price lands on a quarter (Patryk: "comes to
 * whole numbers if the price is a full number or to .25 if not"). Its total
 * is recomputed from the quantity, and every section total — and the sub
 * bid a section stands for — follows. A negative percentage marks down.
 *
 * Every markup is kept on the estimate (options.markups) with each line's
 * price before and after, so it can be reverted: newest first, and only on
 * lines still at the marked-up price — a line edited since keeps its edit.
 *
 * Credits are marked up with the rest, so a credit keeps cancelling its
 * original line exactly. Allowance budgets are left as they are. A signed
 * estimate is refused: its lines are the contract and change only through
 * credits and change-order lines (EstimateLineItemCreate).
 */
class EstimateMarkupApplier
{
    public const MIN_PERCENT = -50.0;

    public const MAX_PERCENT = 200.0;

    /**
     * What the markup would do, without saving anything.
     *
     * @return array{before: float, after: float, lines: int}
     */
    public function preview(Estimate $estimate, float $percent, ?int $sectionId = null): array
    {
        $lines = $this->lines($estimate, $sectionId);
        $factor = 1 + $percent / 100;

        return [
            'before' => round((float) $lines->sum('total'), 2),
            'after' => round($lines->sum(fn (EstimateLineItem $line) => self::lineTotal((float) $line->quantity, self::markedUp((float) $line->cost, $factor))), 2),
            'lines' => $lines->count(),
        ];
    }

    /**
     * @return array{before: float, after: float, lines: int}
     */
    public function apply(Estimate $estimate, float $percent, int $userId, ?int $sectionId = null): array
    {
        // The caller's model may predate an earlier markup (a dialog kept open): never write its stale options back.
        $estimate->refresh();
        $this->assertAllowed($estimate, $percent);

        return DB::transaction(function () use ($estimate, $percent, $userId, $sectionId): array {
            $lines = $this->lines($estimate, $sectionId);
            $before = round((float) $lines->sum('total'), 2);
            $factor = 1 + $percent / 100;
            $changes = [];

            foreach ($lines as $line) {
                $cost = self::markedUp((float) $line->cost, $factor);
                $total = self::lineTotal((float) $line->quantity, $cost);
                $changes[(string) $line->id] = [
                    'cost_before' => (float) $line->cost,
                    'total_before' => (float) $line->total,
                    'cost_after' => $cost,
                    'total_after' => $total,
                ];
                $line->update([
                    'cost' => number_format($cost, 2, '.', ''),
                    'total' => number_format($total, 2, '.', ''),
                ]);
            }

            $this->refreshTotals($lines->pluck('section_id')->unique()->all());
            $after = round((float) $this->lines($estimate, $sectionId)->sum('total'), 2);

            $options = (array) ($estimate->options ?? []);
            $options['markups'][] = [
                'id' => (string) Str::ulid(),
                'percent' => $percent,
                'section_id' => $sectionId,
                'before' => $before,
                'after' => $after,
                'lines' => $changes,
                'by_user_id' => $userId,
                'at' => now()->toIso8601String(),
                'reverted_at' => null,
            ];
            $estimate->update(['options' => $options]);

            return ['before' => $before, 'after' => $after, 'lines' => $lines->count()];
        });
    }

    /**
     * The newest markup not yet reverted — the only one revert() takes, so
     * stacked markups unwind in order.
     *
     * @return array<string, mixed>|null
     */
    public function latestRevertible(Estimate $estimate): ?array
    {
        return collect((array) ($estimate->options['markups'] ?? []))
            ->filter(fn ($markup) => is_array($markup) && filled($markup['id'] ?? null) && empty($markup['reverted_at']) && ! empty($markup['lines']))
            ->last();
    }

    /**
     * Puts the newest markup's lines back to their prices before it. A line
     * edited since (no longer at the marked-up price) or removed since is
     * left alone and counted as skipped.
     *
     * @return array{restored: int, skipped: int, percent: float}
     */
    public function revert(Estimate $estimate, int $userId): array
    {
        $estimate->refresh();
        if ($estimate->isSigned()) {
            throw ValidationException::withMessages([
                'markupPercent' => 'This estimate is signed, so its prices are the contract. Change them with a change order instead.',
            ]);
        }

        $markup = $this->latestRevertible($estimate);
        if ($markup === null) {
            throw ValidationException::withMessages(['markupPercent' => 'There is no markup to revert on this estimate.']);
        }

        return DB::transaction(function () use ($estimate, $markup, $userId): array {
            $restored = 0;
            $skipped = 0;
            $lines = EstimateLineItem::query()->where('estimate_id', $estimate->id)->whereKey(array_keys($markup['lines']))->get()->keyBy('id');

            foreach ($markup['lines'] as $lineId => $change) {
                $line = $lines->get((int) $lineId);
                if (! $line || abs((float) $line->cost - (float) $change['cost_after']) > 0.004) {
                    $skipped++;

                    continue;
                }

                $line->update([
                    'cost' => number_format((float) $change['cost_before'], 2, '.', ''),
                    'total' => number_format(self::lineTotal((float) $line->quantity, (float) $change['cost_before']), 2, '.', ''),
                ]);
                $restored++;
            }

            $this->refreshTotals($lines->pluck('section_id')->unique()->all());

            $options = (array) ($estimate->options ?? []);
            foreach ($options['markups'] as $i => $entry) {
                if (($entry['id'] ?? null) === $markup['id']) {
                    $options['markups'][$i]['reverted_at'] = now()->toIso8601String();
                    $options['markups'][$i]['reverted_by_user_id'] = $userId;
                }
            }
            $estimate->update(['options' => $options]);

            return ['restored' => $restored, 'skipped' => $skipped, 'percent' => (float) $markup['percent']];
        });
    }

    public function assertAllowed(Estimate $estimate, float $percent): void
    {
        if ($estimate->isSigned()) {
            throw ValidationException::withMessages([
                'markupPercent' => 'This estimate is signed, so its prices are the contract. Change them with a change order instead.',
            ]);
        }

        if ($percent == 0.0 || $percent < self::MIN_PERCENT || $percent > self::MAX_PERCENT) {
            throw ValidationException::withMessages([
                'markupPercent' => 'Enter a markup between '.self::MIN_PERCENT.'% and '.self::MAX_PERCENT.'%, other than 0.',
            ]);
        }
    }

    /**
     * A unit price raised by $factor: a whole-dollar price to the nearest
     * whole dollar, any other price to the nearest quarter. Negative prices
     * (credits) round the same way, so a credit still matches its line.
     */
    public static function markedUp(float $price, float $factor): float
    {
        $raised = $price * $factor;

        return self::isWhole($price) ? round($raised) : round($raised * 4) / 4;
    }

    private static function isWhole(float $price): bool
    {
        return abs($price - round($price)) < 0.005;
    }

    /**
     * The line items of one section, or of every section still on the
     * estimate — a disabled section's lines are not part of the price.
     *
     * @return Collection<int, EstimateLineItem>
     */
    private function lines(Estimate $estimate, ?int $sectionId = null): Collection
    {
        $sectionIds = EstimateSection::query()
            ->where('estimate_id', $estimate->id)
            ->when($sectionId, fn ($query) => $query->whereKey($sectionId))
            ->pluck('id');

        return EstimateLineItem::query()
            ->where('estimate_id', $estimate->id)
            ->whereIn('section_id', $sectionIds)
            ->get();
    }

    /** @param  list<int>  $sectionIds */
    private function refreshTotals(array $sectionIds): void
    {
        foreach (EstimateSection::query()->whereIn('id', $sectionIds)->get() as $section) {
            $section->total = $section->estimate_line_items()->sum('total');
            $section->save();

            if ($section->bid_id && ($bid = Bid::find($section->bid_id))) {
                $bid->amount = EstimateSection::where('bid_id', $bid->id)->sum('total');
                $bid->save();
            }
        }
    }

    private static function lineTotal(float $quantity, float $cost): float
    {
        return round($quantity * $cost, 2);
    }
}
