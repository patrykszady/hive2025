<?php

namespace App\Support\Citations;

use App\Models\Citation;
use App\Models\PlatformSetting;
use SsSystems\Platform\Citations\KnownListingsReconciler;

/**
 * Adapted from gsc's/dawnsellshomes' app/Support/Citations/
 * KnownListings.php. Those sites match citation rows against review
 * imports and connected platform accounts that do not exist here (no
 * Projects domain, no Yelp/Meta connections). What this app DOES have is
 * `platform_settings` (App\Models\PlatformSetting) and the Social Media
 * screen's roster (config/social-platforms.php), which saves
 * `socials.url.*` keys — the one overlap between that roster and the
 * citations roster (config/citations.php) is `linkedin`, so that is the
 * only slug reconciled here. Any wider match would be inventing a
 * relationship the two rosters don't actually share.
 *
 * `reconcile()`'s loop moved to the kit's `Citations\
 * KnownListingsReconciler` (citations-admin-actions, 2026-09-27 —
 * verbatim; see that class's own docblock and citations.md #5). This
 * static method is now a one-line wrapper so `citations:sync`'s existing
 * `KnownListings::reconcile()` call keeps working unchanged;
 * `CitationsAdminActions` (the API's own path) constructs its own
 * `KnownListingsReconciler` instance the same way, with the same
 * "Social Media" source label. `forCurrentSite()` — genuinely per-site
 * content — is unchanged below; `SiteKnownListingsSource` is the thin
 * adapter that hands it to the kit.
 */
class KnownListings
{
    /**
     * @return array<string, array{url: string, source: string}> keyed by citation slug
     */
    public static function forCurrentSite(): array
    {
        $found = [];

        foreach (['linkedin'] as $slug) {
            $url = trim((string) PlatformSetting::get("socials.url.{$slug}"));
            if ($url !== '') {
                $found[$slug] = ['url' => $url, 'source' => 'Social Media: profile link'];
            }
        }

        return $found;
    }

    /**
     * Bring the board in line with what is known. A listing URL fills an
     * empty one; a matched row that was planned, failed or waiting on a
     * person becomes live. Returns how many rows changed.
     */
    public static function reconcile(): int
    {
        return (new KnownListingsReconciler(new SiteKnownListingsSource, fn () => Citation::query(), 'Social Media'))->reconcile();
    }
}
