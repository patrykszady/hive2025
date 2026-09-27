<?php

namespace App\Support\Citations;

use App\Models\Citation;
use Illuminate\Support\Facades\Log;
use SsSystems\Platform\Citations\Contracts\CitationLinkStore;

/**
 * hive's read/write for the kit's `LinkCheckRunner` (kit 0.13.0) —
 * unscoped (single tenant, no site_id). Moved unchanged from
 * `App\Console\Commands\CitationsCheckLinks`'s own loop body, including
 * its own log line/step wording and per-row `Log::info()` call, neither
 * of which match gsc's/jpeterson's `SiteCitationLinkStore` — this
 * command has never carried the STATUS_LIVE/NEEDS_HUMAN/FAILED
 * transitions those sites' `citations:control check` does (an owner
 * decision already taken, not a gap this port closes: the runner is
 * constructed with `transitionsStatus: false`, so `$status`/
 * `$humanReason`/`$note` are always null here).
 */
class SiteCitationLinkStore implements CitationLinkStore
{
    public function withListingUrl(): array
    {
        return Citation::query()
            ->whereNotNull('listing_url')
            ->get()
            ->map(fn (Citation $c) => ['slug' => $c->slug, 'name' => (string) $c->name, 'status' => (string) $c->status, 'listing_url' => (string) $c->listing_url])
            ->all();
    }

    public function recordLinkCheck(string $slug, array $result, ?string $status, ?string $humanReason, ?string $note): void
    {
        $citation = Citation::query()->where('slug', $slug)->first();
        if (! $citation) {
            return;
        }

        $citation->links_to_us = $result['links_to_us'] === null ? null : (bool) $result['links_to_us'];
        $citation->nofollow = $result['nofollow'] === null ? null : (bool) $result['nofollow'];
        $citation->last_checked_at = now();
        $citation->addLog('Link check: '.($result['note'] ?? ('HTTP '.$result['status'])), 'link-check');
        $citation->save();

        Log::info('citations: link check', ['slug' => $citation->slug] + $result);
    }
}
