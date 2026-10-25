<?php

namespace App\Services\Estimates;

use App\Models\EstimateLineItem;
use App\Models\EstimateSubDocument;
use App\Models\LineItem;
use App\Models\SubItemAlias;
use Illuminate\Support\Facades\DB;

/**
 * Writes the rows an admin ticked on a sub document comparison
 * (SubDocumentMatcher's rows, with their quantity/cost/catalog item as
 * edited) into the estimate's change order, through the same writers the
 * estimate page uses: increases and new work as line items at OUR cost,
 * decreases and missing work as credits — except where the item already
 * has a line on an open change order: that line's quantity is adjusted
 * instead, and only what it can't absorb is credited. The sub's document is cited in
 * each line's internal notes, never in the description the homeowner reads.
 * Applying a row also confirms its match for next time (SubItemMemory) and
 * marks the sub's lines as taken into a change order.
 */
class SubDocumentChangeOrderApplier
{
    public function __construct(
        private readonly EstimateLineItemWriter $lineItems,
        private readonly EstimateSectionWriter $sections,
        private readonly SubItemMemory $memory,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $rows  the ticked rows
     * @return array{added: list<int>, credited: list<int>, adjusted: list<int>, skipped: list<string>, rows: array<string, array{outcome: string, line_item_ids: list<int>, quantity: float, cost: float, amount: float, detail: string}>}
     */
    public function apply(EstimateSubDocument $document, array $rows, ?int $userId = null): array
    {
        $estimate = $document->estimate;
        $result = ['added' => [], 'credited' => [], 'adjusted' => [], 'skipped' => [], 'rows' => []];
        $reference = $this->reference($document);
        $applied = [];

        DB::transaction(function () use ($estimate, $rows, $reference, &$result, &$applied) {
            $section = null;

            foreach ($rows as $row) {
                $quantity = round((float) ($row['quantity'] ?? 0), 2);
                $cost = round((float) ($row['cost'] ?? 0), 2);
                $kind = $row['kind'] ?? '';
                $key = (string) ($row['key'] ?? '');
                $sign = in_array($kind, ['decrease', 'missing'], true) ? -1 : 1;
                // A real closure: an arrow function would write into a copy of $result.
                $outcome = function (string $what, array $ids, float $qty, string $detail) use (&$result, $key, $cost, $sign): void {
                    $result['rows'][$key] = ['outcome' => $what, 'line_item_ids' => $ids, 'quantity' => $qty, 'cost' => $cost, 'amount' => round($sign * $qty * $cost, 2), 'detail' => $detail];
                };

                if ($quantity <= 0 || $cost < 0) {
                    $result['skipped'][] = (string) ($row['name'] ?? $row['key'] ?? '?');

                    continue;
                }

                $adjustable = $this->adjustableLine($estimate, $row, $cost);

                if ($kind === 'increase' && $adjustable) {
                    $was = (float) $adjustable->quantity;
                    $this->setQuantity($adjustable, $was + $quantity, $reference, $row);
                    $result['adjusted'][] = $adjustable->id;
                    $applied[] = $row;
                    $outcome('adjusted', [$adjustable->id], $quantity, sprintf('Line %s adjusted %s → %s', $row['adjust']['number'] ?? '', self::qty($was), self::qty($was + $quantity)));

                    continue;
                }

                if (in_array($kind, ['decrease', 'missing'], true) && $adjustable) {
                    $was = (float) $adjustable->quantity;
                    $absorbed = min($quantity, $was);
                    $this->setQuantity($adjustable, $was - $absorbed, $reference, $row);
                    $result['adjusted'][] = $adjustable->id;
                    $applied[] = $row;
                    $outcome('adjusted', [$adjustable->id], $absorbed, sprintf('Line %s adjusted %s → %s', $row['adjust']['number'] ?? '', self::qty($was), self::qty($was - $absorbed)));
                    $quantity = round($quantity - $absorbed, 2);

                    if ($quantity <= 0) {
                        continue;
                    }
                    // What the change-order line couldn't absorb is credited against the original below.
                }

                if (in_array($kind, ['increase', 'new'], true)) {
                    $catalogItem = LineItem::query()->where('belongs_to_vendor_id', $estimate->belongs_to_vendor_id)->find($row['line_item_id'] ?? 0);

                    if (! $catalogItem) {
                        $result['skipped'][] = (string) ($row['name'] ?? '?');

                        continue;
                    }

                    $section ??= $this->sections->resolveChangeOrderSection($estimate);
                    $created = $this->lineItems->create($estimate->id, $section, $catalogItem, [
                        'category' => $catalogItem->category,
                        'sub_category' => $catalogItem->sub_category,
                        'unit_type' => $catalogItem->unit_type,
                        'quantity' => $quantity,
                        'cost' => $cost,
                        'total' => number_format($quantity * $cost, 2, '.', ''),
                        'desc' => (string) $catalogItem->desc,
                        'notes' => $this->note($kind, $row, $reference),
                    ], []);

                    $result['added'][] = $created->id;
                    $applied[] = $row;
                    $outcome('added', [$created->id], $quantity, sprintf('Added %s @ %s', self::qty($quantity), money($cost)));

                    continue;
                }

                if (in_array($kind, ['decrease', 'missing'], true)) {
                    $original = EstimateLineItem::query()->where('estimate_id', $estimate->id)->find($row['credit_source_id'] ?? 0);
                    $credit = $original ? $this->lineItems->creditToChangeOrder($original, $quantity, $cost) : null;

                    if (! $credit) {
                        $result['skipped'][] = (string) ($row['name'] ?? '?');

                        continue;
                    }

                    $credit->update(['notes' => $this->note($kind, $row, $reference)]);
                    $result['credited'][] = $credit->id;
                    $applied[] = $row;
                    $previous = $result['rows'][$key] ?? null;
                    $outcome($previous ? 'adjusted+credited' : 'credited', array_merge($previous['line_item_ids'] ?? [], [$credit->id]), ($previous['quantity'] ?? 0) + $quantity,
                        trim(($previous['detail'] ?? '').sprintf(' Credited %s @ %s', self::qty($quantity), money($cost))));

                    continue;
                }

                $result['skipped'][] = (string) ($row['name'] ?? '?');
            }
        });

        foreach ($applied as $row) {
            foreach ((array) ($row['document_descriptions'] ?? []) as $description) {
                $this->memory->remember((int) $document->belongs_to_vendor_id, $document->sub_vendor_id, (string) $description, (int) $row['line_item_id'], SubItemAlias::SOURCE_CONFIRMED, $userId);
            }
            if (! empty($row['document_items'])) {
                $document->items()->whereIn('index', (array) $row['document_items'])->update(['applied' => true, 'line_item_id' => (int) $row['line_item_id']]);
            }
        }

        $document->update([
            'status' => EstimateSubDocument::STATUS_APPLIED,
            'applied' => ['added' => $result['added'], 'credited' => $result['credited'], 'adjusted' => $result['adjusted'], 'skipped' => $result['skipped'], 'rows' => $result['rows']],
            'applied_at' => now(),
        ]);

        return $result;
    }

    /**
     * The open change-order line a row's quantity change is written onto —
     * still there, still that item, still unlocked, and priced as the row is
     * (a changed unit cost can't be folded into an existing line).
     *
     * @param  array<string, mixed>  $row
     */
    protected function adjustableLine($estimate, array $row, float $cost): ?EstimateLineItem
    {
        $adjust = $row['adjust'] ?? null;

        if (! is_array($adjust) || empty($adjust['id'])) {
            return null;
        }

        $line = EstimateLineItem::query()->with('section.bid')->where('estimate_id', $estimate->id)->find((int) $adjust['id']);

        if (! $line || $line->credit_for_id || (int) $line->line_item_id !== (int) ($row['line_item_id'] ?? 0)
            || ! $line->section?->bid || (int) $line->section->bid->type < 2 || $line->section->isLocked()
            || abs((float) $line->cost - $cost) >= 0.005) {
            return null;
        }

        return $line;
    }

    /** A new quantity on an existing line; at zero the line goes (restorable, like any deleted line). */
    protected function setQuantity(EstimateLineItem $line, float $quantity, string $reference, array $row): void
    {
        $was = self::qty($line->quantity);
        $note = trim((string) $line->notes."\n".sprintf('Adjusted %s → %s: %s lists %s, we estimated %s.', $was, self::qty(max(0, $quantity)), $reference, self::qty($row['document_quantity'] ?? null), self::qty($row['estimated_quantity'] ?? null)));

        if ($quantity <= 0.005) {
            $line->update(['notes' => $note]);
            $line->delete();

            return;
        }

        $line->update([
            'quantity' => round($quantity, 2),
            'total' => number_format(round($quantity, 2) * (float) $line->cost, 2, '.', ''),
            'notes' => $note,
        ]);
    }

    /**
     * What happened to each row of an applied document. Documents applied
     * before this was recorded (2026-10-25, 2:41 PM on Violet) only hold the
     * ids of the lines written: the record is rebuilt from those lines —
     * each added line by its catalog item (or the sub's line its notes cite),
     * each adjusted line by the row that targeted it, each credit by the
     * line it credits — and saved, so the review reads right from then on.
     *
     * @return array<string, array{outcome: string, line_item_ids: list<int>, quantity: float, cost: float, amount: float, detail: string}>
     */
    public function outcomes(EstimateSubDocument $document): array
    {
        $applied = (array) $document->applied;

        if (isset($applied['rows']) || $document->status !== EstimateSubDocument::STATUS_APPLIED) {
            return (array) ($applied['rows'] ?? []);
        }

        $rows = collect((array) data_get($document->proposals, 'rows', []));
        $ids = array_merge((array) ($applied['added'] ?? []), (array) ($applied['adjusted'] ?? []), (array) ($applied['credited'] ?? []));
        $lines = EstimateLineItem::withTrashed()->whereIn('id', $ids)->get()->keyBy('id');
        $outcomes = [];
        $record = function (?array $row, string $what, EstimateLineItem $line, float $qty, float $amount, string $detail) use (&$outcomes): void {
            if (! $row) {
                return;
            }
            $previous = $outcomes[$row['key']] ?? null;
            $outcomes[$row['key']] = [
                'outcome' => $previous ? $previous['outcome'].'+'.$what : $what,
                'line_item_ids' => array_merge($previous['line_item_ids'] ?? [], [$line->id]),
                'quantity' => round(($previous['quantity'] ?? 0) + $qty, 2),
                'cost' => abs((float) $line->cost),
                'amount' => round(($previous['amount'] ?? 0) + $amount, 2),
                'detail' => trim(($previous['detail'] ?? '').' '.$detail),
            ];
        };

        foreach ((array) ($applied['added'] ?? []) as $id) {
            if (! $line = $lines->get($id)) {
                continue;
            }
            $row = $rows->first(fn ($r) => $r['key'] === 'li-'.$line->line_item_id)
                ?? $rows->first(fn ($r) => collect($r['document_descriptions'] ?? [])->contains(fn ($d) => $d !== '' && str_contains((string) $line->notes, (string) $d)));
            $record($row, 'added', $line, (float) $line->quantity, (float) $line->total, sprintf('Added %s @ %s', self::qty($line->quantity), money($line->cost)));
        }

        foreach ((array) ($applied['adjusted'] ?? []) as $id) {
            if (! $line = $lines->get($id)) {
                continue;
            }
            $row = $rows->first(fn ($r) => (int) data_get($r, 'adjust.id') === (int) $id);
            preg_match_all('/Adjusted (\S+) → (\S+)/u', (string) $line->notes, $m);
            $from = (float) (end($m[1]) ?: 0);
            $to = (float) (end($m[2]) ?: $line->quantity);
            $qty = round(abs($to - $from), 2);
            $record($row, 'adjusted', $line, $qty, round(($to - $from) * (float) $line->cost, 2), sprintf('Line %s adjusted %s → %s', data_get($row, 'adjust.number', ''), self::qty($from), self::qty($to)));
        }

        foreach ((array) ($applied['credited'] ?? []) as $id) {
            if (! $line = $lines->get($id)) {
                continue;
            }
            $row = $rows->first(fn ($r) => (int) ($r['credit_source_id'] ?? 0) === (int) $line->credit_for_id)
                ?? $rows->first(fn ($r) => $r['key'] === 'li-'.$line->line_item_id);
            $record($row, 'credited', $line, (float) $line->quantity, (float) $line->total, sprintf('Credited %s @ %s', self::qty($line->quantity), money(abs((float) $line->cost))));
        }

        $document->update(['applied' => $applied + ['rows' => $outcomes]]);

        return $outcomes;
    }

    /**
     * A "same" row is a match the admin saw and had no quarrel with: remember
     * it like an applied one, so the next document is read the same way.
     *
     * @param  list<array<string, mixed>>  $rows  every row of the comparison
     */
    public function confirmUndisputed(EstimateSubDocument $document, array $rows, ?int $userId = null): int
    {
        $confirmed = 0;

        foreach ($rows as $row) {
            if (($row['kind'] ?? null) !== 'same' || empty($row['line_item_id'])) {
                continue;
            }
            foreach ((array) ($row['document_descriptions'] ?? []) as $description) {
                if ($this->memory->remember((int) $document->belongs_to_vendor_id, $document->sub_vendor_id, (string) $description, (int) $row['line_item_id'], SubItemAlias::SOURCE_CONFIRMED, $userId)) {
                    $confirmed++;
                }
            }
        }

        return $confirmed;
    }

    /** "Smartech Electric invoice #0062 (Oct 22, 2026)" */
    protected function reference(EstimateSubDocument $document): string
    {
        return $document->label();
    }

    /** @param  array<string, mixed>  $row */
    protected function note(string $kind, array $row, string $reference): string
    {
        $documentQty = $row['document_quantity'] ?? null;
        $estimated = $row['estimated_quantity'] ?? null;
        $lines = implode(', ', (array) ($row['document_descriptions'] ?? []));

        $what = match ($kind) {
            'increase' => sprintf('%s lists %s, we estimated %s.', $reference, self::qty($documentQty), self::qty($estimated)),
            'decrease' => sprintf('%s lists %s, we estimated %s.', $reference, self::qty($documentQty), self::qty($estimated)),
            'new' => sprintf('Not in our estimate; %s lists it.', $reference),
            'missing' => sprintf('Not on %s.', $reference),
            default => $reference,
        };

        return trim($what.($lines !== '' ? ' Sub\'s line: '.$lines : ''));
    }

    private static function qty(mixed $value): string
    {
        return $value === null ? '—' : rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    }
}
