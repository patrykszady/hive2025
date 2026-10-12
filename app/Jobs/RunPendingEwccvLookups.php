<?php

namespace App\Jobs;

use App\Models\VendorDoc;
use App\Support\Ewccv\BridgedSession;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/**
 * Verify every workers comp policy EWCCV is not yet tracking for us, the
 * moment a person's browser hands over a search session (the bridge
 * extension → EwccvSessionController). The session is the only credential
 * EWCCV's search accepts from this server, and it lasts about 45 minutes,
 * so the work runs right away rather than on a schedule that would mostly
 * find no session.
 *
 * One run covers every pending vendor in a single scraper process; a policy
 * EWCCV already tracks (status `tracking`, kept current by the daily
 * ewccv:sync-subscriptions) is left alone.
 */
class RunPendingEwccvLookups implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** The scraper may spend a minute per vendor. */
    public int $timeout = 3600;

    public function uniqueFor(): int
    {
        return 600;
    }

    public function handle(): void
    {
        $session = BridgedSession::fresh();
        if (! $session && blank(config('services.ewccv.bypass_key'))) {
            Log::info('RunPendingEwccvLookups: no fresh EWCCV browser session — nothing to run with');

            return;
        }

        $belongsToVendorId = (int) config('services.ewccv.belongs_to_vendor_id', 1);
        $vendorIds = self::pendingVendorIds($belongsToVendorId);
        if ($vendorIds === []) {
            Log::info('RunPendingEwccvLookups: every active workers comp policy is already tracked');

            return;
        }

        Log::info('RunPendingEwccvLookups: running the EWCCV lookup', [
            'vendor_ids' => $vendorIds,
            'session_age_minutes' => $session['age_minutes'] ?? null,
        ]);

        $exitCode = Artisan::call('ewccv:scrape-tracking', [
            '--belongs-to-vendor-id' => $belongsToVendorId,
            '--vendor-id' => $vendorIds,
        ]);

        if ($exitCode !== 0) {
            Log::warning('RunPendingEwccvLookups: scraper exited non-zero', ['exit_code' => $exitCode, 'vendor_ids' => $vendorIds]);
        }
    }

    /**
     * Vendors with an active workers comp policy EWCCV is not tracking yet
     * (never looked up, pending, failed or skipped).
     *
     * @return list<int>
     */
    public static function pendingVendorIds(int $belongsToVendorId): array
    {
        return VendorDoc::withoutGlobalScopes()
            ->where('type', 'workers')
            ->where('belongs_to_vendor_id', $belongsToVendorId)
            ->whereNotNull('vendor_id')
            ->where('expiration_date', '>=', Carbon::today())
            ->get(['vendor_id', 'options'])
            ->reject(fn (VendorDoc $doc) => (($doc->options['ewccv']['status'] ?? null) === 'tracking'))
            ->pluck('vendor_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}
