<?php

namespace App\Services\EstimateAI;

use App\Models\EstimateAiDraft;
use App\Models\EstimateAiRule;
use Illuminate\Support\Collection;

/**
 * Turns repeated corrections into proposed estimating rules for a person to
 * approve: an item estimators keep removing, an item they keep adding, and an
 * item they add whenever another one is drafted. Nothing is guessed by a
 * model here; it is counting.
 */
class RuleProposer
{
    /** Times a correction has to recur before it is worth a rule. */
    public const MIN_OCCURRENCES = 3;

    /** ...and the share of drafts it recurred in. */
    public const MIN_RATE = 0.6;

    /** Kept drafts looked at per company. */
    public const WINDOW = 100;

    /**
     * @return list<EstimateAiRule> the rules newly proposed
     */
    public function propose(int $vendorId): array
    {
        $drafts = EstimateAiDraft::query()
            ->where('vendor_id', $vendorId)
            ->where('status', '!=', EstimateAiDraft::DISCARDED)
            ->latest('id')
            ->limit(self::WINDOW)
            ->get();

        if ($drafts->count() < self::MIN_OCCURRENCES) {
            return [];
        }

        $draftedIn = [];   // line item → drafts it was drafted in
        $removedIn = [];   // line item → drafts it was drafted in and then removed from
        $addedIn = [];     // line item → drafts it was added to afterwards
        $addedWith = [];   // "added:drafted" → drafts where added appeared alongside drafted
        $names = [];

        foreach ($drafts as $draft) {
            $corrections = DraftCorrections::forDraft($draft);
            $draftedIds = [];

            foreach ($draft->drafted_items ?? [] as $item) {
                if (! isset($item['line_item_id'])) {
                    continue;
                }
                $id = (int) $item['line_item_id'];
                $draftedIds[$id] = true;
                $draftedIn[$id] = ($draftedIn[$id] ?? 0) + 1;
                $names[$id] = $item['name'] ?? $names[$id] ?? "#{$id}";
            }

            foreach ($corrections['removed'] as $item) {
                if (! isset($item['line_item_id'])) {
                    continue;
                }
                $id = (int) $item['line_item_id'];
                $removedIn[$id] = ($removedIn[$id] ?? 0) + 1;
            }

            foreach ($corrections['added'] as $item) {
                if (! isset($item['line_item_id'])) {
                    continue;
                }
                $id = (int) $item['line_item_id'];
                $addedIn[$id] = ($addedIn[$id] ?? 0) + 1;
                $names[$id] = $item['name'] ?? $names[$id] ?? "#{$id}";

                foreach (array_keys($draftedIds) as $draftedId) {
                    $addedWith["{$id}:{$draftedId}"] = ($addedWith["{$id}:{$draftedId}"] ?? 0) + 1;
                }
            }
        }

        $existing = EstimateAiRule::query()->forVendor($vendorId)->whereNotNull('fingerprint')->pluck('fingerprint')->flip();
        $proposals = [];

        foreach ($removedIn as $id => $removed) {
            $drafted = $draftedIn[$id] ?? 0;
            if ($removed < self::MIN_OCCURRENCES || $drafted === 0 || $removed / $drafted < self::MIN_RATE) {
                continue;
            }

            $proposals[] = [
                'fingerprint' => "removed:{$id}",
                'text' => "Leave out \"{$names[$id]}\" unless the description asks for it.",
                'evidence' => ['kind' => 'removed', 'line_item_id' => $id, 'removed' => $removed, 'drafted' => $drafted,
                    'note' => "Estimators removed it from {$removed} of {$drafted} drafts that included it."],
            ];
        }

        foreach ($addedIn as $id => $added) {
            if ($added < self::MIN_OCCURRENCES) {
                continue;
            }

            $companion = $this->companionFor($id, $addedWith, $draftedIn);

            if ($companion !== null) {
                [$draftedId, $together] = $companion;
                $proposals[] = [
                    'fingerprint' => "pair:{$id}:{$draftedId}",
                    'text' => "Draft \"{$names[$id]}\" whenever \"{$names[$draftedId]}\" is drafted.",
                    'evidence' => ['kind' => 'pair', 'line_item_id' => $id, 'with_line_item_id' => $draftedId, 'together' => $together, 'drafted' => $draftedIn[$draftedId],
                        'note' => "Estimators added it to {$together} of {$draftedIn[$draftedId]} drafts that had \"{$names[$draftedId]}\"."],
                ];

                continue;
            }

            $proposals[] = [
                'fingerprint' => "added:{$id}",
                'text' => "Include \"{$names[$id]}\" when the work calls for it; drafts tend to miss it.",
                'evidence' => ['kind' => 'added', 'line_item_id' => $id, 'added' => $added,
                    'note' => "Estimators added it after {$added} drafts."],
            ];
        }

        $created = [];
        foreach ($proposals as $proposal) {
            if ($existing->has($proposal['fingerprint'])) {
                continue;
            }

            $created[] = EstimateAiRule::create([
                'vendor_id' => $vendorId,
                'text' => $proposal['text'],
                'status' => EstimateAiRule::PROPOSED,
                'source' => EstimateAiRule::FROM_PATTERN,
                'fingerprint' => $proposal['fingerprint'],
                'evidence' => $proposal['evidence'],
            ]);
        }

        return $created;
    }

    /**
     * The drafted item an added one most reliably follows, if any.
     *
     * @param  array<string, int>  $addedWith
     * @param  array<int, int>  $draftedIn
     * @return array{0: int, 1: int}|null [drafted line item id, times together]
     */
    protected function companionFor(int $addedId, array $addedWith, array $draftedIn): ?array
    {
        $best = null;

        foreach ($addedWith as $key => $together) {
            [$added, $drafted] = array_map('intval', explode(':', $key));
            if ($added !== $addedId || $together < self::MIN_OCCURRENCES) {
                continue;
            }

            $rate = $together / max($draftedIn[$drafted] ?? 0, 1);
            if ($rate < self::MIN_RATE) {
                continue;
            }

            if ($best === null || $together > $best[1] || ($together === $best[1] && $rate > $best[2])) {
                $best = [$drafted, $together, $rate];
            }
        }

        return $best === null ? null : [$best[0], $best[1]];
    }

    /**
     * Every company with drafts to learn from.
     *
     * @return Collection<int, int>
     */
    public static function vendorsWithDrafts(): Collection
    {
        return EstimateAiDraft::query()->select('vendor_id')->distinct()->pluck('vendor_id')->map(fn ($id) => (int) $id);
    }
}
