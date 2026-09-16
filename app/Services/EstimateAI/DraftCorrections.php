<?php

namespace App\Services\EstimateAI;

use App\Models\EstimateAiDraft;
use App\Models\EstimateLineItem;
use App\Models\LineItem;
use Illuminate\Support\Collection;

/**
 * What an estimator changed after a draft: the lines they removed, the
 * lines they added and the quantities they corrected. This is the signal
 * the generator learns from.
 */
class DraftCorrections
{
    /**
     * Drafted items against the section's lines. A draft that knows which
     * estimate lines it created is compared row by row: those rows gone means
     * removed, changed means corrected, and lines the estimator created after
     * the draft mean added — lines that were in the section before the draft
     * are not its business. Older drafts without row ids fall back to
     * comparing catalog items.
     *
     * @param  list<array<string, mixed>>  $draftedItems
     * @param  Collection<int, EstimateLineItem>  $finalLines
     * @return array{added: list<array<string, mixed>>, removed: list<array<string, mixed>>, quantity_changed: list<array<string, mixed>>, cost_changed: list<array<string, mixed>>, text_edited: list<array<string, mixed>>}
     */
    public static function between(array $draftedItems, Collection $finalLines, ?\DateTimeInterface $draftedAt = null): array
    {
        $byRow = collect($draftedItems)->every(fn (array $item) => ! empty($item['estimate_line_item_id']));

        if ($byRow && $draftedItems !== []) {
            $drafted = collect($draftedItems)->keyBy(fn (array $item) => (int) $item['estimate_line_item_id']);
            $final = $finalLines->keyBy('id');
            $kept = $drafted->intersectByKeys($final);
            $added = $final->diffKeys($drafted)->filter(fn (EstimateLineItem $line) => $draftedAt === null || $line->created_at === null || $line->created_at >= $draftedAt);
            $removed = $drafted->diffKeys($final);
        } else {
            $drafted = collect($draftedItems)->keyBy(fn (array $item) => self::key($item['line_item_id'] ?? null, $item['name'] ?? ''));
            $final = $finalLines->keyBy(fn (EstimateLineItem $line) => self::key($line->line_item_id, $line->name));
            $kept = $drafted->intersectByKeys($final);
            $added = $final->diffKeys($drafted);
            $removed = $drafted->diffKeys($final);
        }

        $added = $added->map(fn (EstimateLineItem $line) => [
            'line_item_id' => $line->line_item_id,
            'name' => $line->name,
            'quantity' => (float) $line->quantity,
        ])->values()->all();

        $removed = $removed->map(fn (array $item) => [
            'line_item_id' => $item['line_item_id'] ?? null,
            'name' => $item['name'] ?? '',
            'quantity' => (float) ($item['quantity'] ?? 1),
        ])->values()->all();

        $quantityChanged = [];
        $costChanged = [];
        foreach ($kept as $key => $item) {
            $line = $final->get($key);
            $from = (float) ($item['quantity'] ?? 1);
            $to = (float) $line->quantity;

            if ($line->unit_type !== 'no_unit' && abs($from - $to) > 0.01) {
                $quantityChanged[] = ['line_item_id' => $line->line_item_id, 'name' => $line->name, 'from' => $from, 'to' => $to];
            }

            if (isset($item['cost']) && abs((float) $item['cost'] - (float) $line->cost) > 0.01) {
                $costChanged[] = ['line_item_id' => $line->line_item_id, 'name' => $line->name, 'from' => (float) $item['cost'], 'to' => (float) $line->cost];
            }
        }

        // Text the estimator rewrote on a kept line: the catalog's wording was not what they wanted.
        $textEdited = [];
        $keptLines = $kept->keys()->map(fn ($key) => $final->get($key));
        $catalog = LineItem::withoutGlobalScopes()->whereIn('id', $keptLines->pluck('line_item_id')->filter())->get(['id', 'desc', 'notes'])->keyBy('id');
        foreach ($keptLines as $line) {
            $source = $catalog->get($line->line_item_id);
            if ($source === null) {
                continue;
            }
            $fields = array_keys(array_filter([
                'desc' => trim((string) $line->desc) !== trim((string) $source->desc),
                'notes' => trim((string) $line->notes) !== trim((string) $source->notes),
            ]));
            if ($fields !== []) {
                $textEdited[] = ['line_item_id' => $line->line_item_id, 'name' => $line->name, 'fields' => $fields];
            }
        }

        return ['added' => $added, 'removed' => $removed, 'quantity_changed' => $quantityChanged, 'cost_changed' => $costChanged, 'text_edited' => $textEdited];
    }

    /**
     * The snapshot taken at signing when there is one, otherwise the
     * section as it stands now.
     *
     * @return array{added: list<array<string, mixed>>, removed: list<array<string, mixed>>, quantity_changed: list<array<string, mixed>>}
     */
    public static function forDraft(EstimateAiDraft $draft): array
    {
        if (is_array($draft->corrections) && $draft->finalized_at !== null) {
            return $draft->corrections + ['added' => [], 'removed' => [], 'quantity_changed' => [], 'cost_changed' => [], 'text_edited' => []];
        }

        $lines = EstimateLineItem::query()->where('section_id', $draft->section_id)->orderBy('order')->get();

        return self::between($draft->drafted_items ?? [], $lines, $draft->created_at);
    }

    public static function isEmpty(array $corrections): bool
    {
        foreach (['added', 'removed', 'quantity_changed', 'cost_changed', 'text_edited'] as $kind) {
            if (($corrections[$kind] ?? []) !== []) {
                return false;
            }
        }

        return true;
    }

    /** One line a person, or the model, can read. */
    public static function summarize(array $corrections): string
    {
        $parts = [];

        if (($corrections['removed'] ?? []) !== []) {
            $parts[] = 'removed '.collect($corrections['removed'])->pluck('name')->implode(', ');
        }
        if (($corrections['added'] ?? []) !== []) {
            $parts[] = 'added '.collect($corrections['added'])->map(fn ($item) => $item['name'].' × '.self::quantity($item['quantity']))->implode(', ');
        }
        if (($corrections['quantity_changed'] ?? []) !== []) {
            $parts[] = 'changed '.collect($corrections['quantity_changed'])->map(fn ($item) => $item['name'].' '.self::quantity($item['from']).' → '.self::quantity($item['to']))->implode(', ');
        }
        if (($corrections['cost_changed'] ?? []) !== []) {
            $parts[] = 'repriced '.collect($corrections['cost_changed'])->map(fn ($item) => $item['name'].' $'.number_format($item['from'], 2).' → $'.number_format($item['to'], 2))->implode(', ');
        }
        if (($corrections['text_edited'] ?? []) !== []) {
            $parts[] = 'rewrote the '.collect($corrections['text_edited'])->map(fn ($item) => implode('/', $item['fields']).' of '.$item['name'])->implode(', ');
        }

        return implode('; ', $parts);
    }

    /**
     * Snapshot every kept draft of an estimate against its lines as signed.
     */
    public static function finalize(int $estimateId): int
    {
        $count = 0;

        EstimateAiDraft::query()->where('estimate_id', $estimateId)->where('status', '!=', EstimateAiDraft::DISCARDED)->each(function (EstimateAiDraft $draft) use (&$count) {
            $lines = EstimateLineItem::query()->where('section_id', $draft->section_id)->orderBy('order')->get();

            $draft->forceFill([
                'final_items' => $lines->map(fn (EstimateLineItem $line) => [
                    'line_item_id' => $line->line_item_id,
                    'name' => $line->name,
                    'quantity' => (float) $line->quantity,
                    'unit_type' => $line->unit_type,
                    'cost' => (float) $line->cost,
                ])->values()->all(),
                'corrections' => self::between($draft->drafted_items ?? [], $lines, $draft->created_at),
                'finalized_at' => now(),
            ])->save();

            $count++;
        });

        return $count;
    }

    private static function key(?int $lineItemId, string $name): string
    {
        return $lineItemId ? 'id:'.$lineItemId : 'name:'.mb_strtolower(trim($name));
    }

    private static function quantity(float|int|string $quantity): string
    {
        return rtrim(rtrim(number_format((float) $quantity, 2, '.', ''), '0'), '.');
    }
}
