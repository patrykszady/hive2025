<?php

namespace App\Services\Estimates;

use App\Models\SubItemAlias;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * What a sub's wording means in our catalog, learned from the compare modal
 * the way the AI estimator learns from corrections: match "2'' cans" to our
 * 4" Recessed once and the next document that says it is matched before
 * the model is asked. An alias is kept per sub when the sub is known and
 * for any sub otherwise; a sub's own alias wins over a general one.
 */
class SubItemMemory
{
    /** The wording with case, punctuation, ligature quirks and sizes' quote marks evened out. */
    public static function key(string $description): string
    {
        $text = Str::lower($description);
        // Curly quotes, primes and backticks read as plain quotes; two singles are an inch mark.
        $text = preg_replace('/[\x{2018}\x{2019}\x{201A}\x{2032}`´]/u', "'", $text) ?? $text;
        $text = preg_replace('/[\x{201C}\x{201D}\x{2033}]/u', '"', $text) ?? $text;
        $text = preg_replace("/'\s*'/", '"', $text) ?? $text;
        $text = Str::ascii($text);
        $text = preg_replace('/[^a-z0-9"\'\/.\s]+/', ' ', $text) ?? $text;

        return mb_substr(trim(preg_replace('/\s+/', ' ', $text) ?? ''), 0, 191);
    }

    /**
     * The learned catalog item for each description that has one.
     *
     * @param  list<string>  $descriptions
     * @return array<string, array{line_item_id: int, source: string, sub_vendor_id: ?int}> keyed by key()
     */
    public function lookup(int $belongsToVendorId, ?int $subVendorId, array $descriptions): array
    {
        $keys = array_values(array_unique(array_filter(array_map([self::class, 'key'], $descriptions))));

        if ($keys === []) {
            return [];
        }

        $aliases = SubItemAlias::query()
            ->where('belongs_to_vendor_id', $belongsToVendorId)
            ->whereIn('description_key', $keys)
            ->where(fn ($query) => $query->whereNull('sub_vendor_id')->when($subVendorId, fn ($q) => $q->orWhere('sub_vendor_id', $subVendorId)))
            ->whereHas('lineItem')
            ->get();

        $found = [];
        foreach ($aliases->sortBy(fn (SubItemAlias $alias) => $alias->sub_vendor_id ? 0 : 1) as $alias) {
            $found[$alias->description_key] ??= ['line_item_id' => (int) $alias->line_item_id, 'source' => $alias->source, 'sub_vendor_id' => $alias->sub_vendor_id];
        }

        return $found;
    }

    /**
     * Every learned match for this sub (and the general ones), newest use
     * first — shown to the model as examples of this sub's wording.
     *
     * @return Collection<int, SubItemAlias>
     */
    public function examples(int $belongsToVendorId, ?int $subVendorId, int $limit = 60): Collection
    {
        return SubItemAlias::query()
            ->with('lineItem:id,name')
            ->where('belongs_to_vendor_id', $belongsToVendorId)
            ->where(fn ($query) => $query->whereNull('sub_vendor_id')->when($subVendorId, fn ($q) => $q->orWhere('sub_vendor_id', $subVendorId)))
            ->whereHas('lineItem')
            ->orderByDesc('last_used_at')
            ->limit($limit)
            ->get();
    }

    /** Remember (or confirm again) that this wording is that catalog item. */
    public function remember(int $belongsToVendorId, ?int $subVendorId, string $description, int $lineItemId, string $source, ?int $userId = null): ?SubItemAlias
    {
        $key = self::key($description);

        if ($key === '' || $lineItemId <= 0) {
            return null;
        }

        $alias = SubItemAlias::query()->firstOrNew([
            'belongs_to_vendor_id' => $belongsToVendorId,
            'sub_vendor_id' => $subVendorId,
            'description_key' => $key,
        ]);

        $alias->fill([
            'description' => mb_substr(trim($description), 0, 500),
            'line_item_id' => $lineItemId,
            // A hand-picked match stays "manual" however often it is confirmed after.
            'source' => $alias->exists && $alias->source === SubItemAlias::SOURCE_MANUAL ? SubItemAlias::SOURCE_MANUAL : $source,
            'times_used' => $alias->exists ? $alias->times_used + 1 : 1,
            'created_by_user_id' => $alias->created_by_user_id ?? $userId,
            'last_used_at' => now(),
        ])->save();

        return $alias;
    }
}
