<?php

namespace App\Support\Citations;

use App\Models\Citation;
use App\Models\PlatformSetting;

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
 */
class KnownListings
{
    /** Statuses a match may move to live — never a run in progress, a submission awaiting verification, or a deliberate decline. */
    protected const MOVABLE = [
        Citation::STATUS_PLANNED, Citation::STATUS_FAILED, Citation::STATUS_NEEDS_HUMAN,
        Citation::STATUS_UNREACHABLE, Citation::STATUS_NO_MECHANISM,
    ];

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
        $changed = 0;

        foreach (self::forCurrentSite() as $slug => $known) {
            $row = Citation::query()->where('slug', $slug)->first();
            if (! $row) {
                continue;
            }

            $dirty = false;
            if (blank($row->listing_url)) {
                $row->listing_url = $known['url'];
                $dirty = true;
            }
            if (in_array($row->status, self::MOVABLE, true)) {
                $row->status = Citation::STATUS_LIVE;
                $row->live_at ??= now();
                $row->human_reason = null;
                $row->note = 'Listed already — '.$known['source'].'.';
                $dirty = true;
            }
            if ($dirty) {
                $row->addLog('Matched from what Social Media already knows: '.$known['source'], 'sync');
                $row->save();
                $changed++;
            }
        }

        return $changed;
    }
}
