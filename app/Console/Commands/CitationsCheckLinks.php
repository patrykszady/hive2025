<?php

namespace App\Console\Commands;

use App\Support\Citations\SiteCitationLinkStore;
use App\Support\Citations\SiteLinkTarget;
use Illuminate\Console\Command;
use SsSystems\Platform\Citations\LinkCheckRunner;

/**
 * The one caller of SsSystems\Platform\Citations\LinkCheckRunner on this
 * app (kit 0.13.0 — the loop this command ran inline before moved to the
 * kit, shared with gsc's/jpeterson's `citations:control check` sub-action,
 * see LinkCheckRunner's docblock): for every directory with a listing
 * URL, fetch it like a visitor and record whether it links back to this
 * site. Constructed with `transitionsStatus: false` — an owner decision
 * already taken (2026-09-27): this command has never carried the
 * STATUS_LIVE/NEEDS_HUMAN/FAILED transitions gsc's/jpeterson's action
 * does, and that gap is not closed here. Not scheduled anywhere yet (no
 * cron entry in routes/console.php) — run by hand, or wire it to a
 * weekly schedule entry the same way gsc/dawnsellshomes do, once there is
 * a reason to.
 *
 *   php artisan citations:check-links
 */
class CitationsCheckLinks extends Command
{
    protected $signature = 'citations:check-links';

    protected $description = 'Check every citation with a listing URL for a link back to this site';

    public function handle(): int
    {
        $runner = new LinkCheckRunner(new SiteCitationLinkStore, new SiteLinkTarget, transitionsStatus: false);
        $checked = $runner->run();

        $this->info('Checked '.count($checked).' citation(s) with a listing URL.');

        return self::SUCCESS;
    }
}
