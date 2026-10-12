<?php

namespace App\Jobs;

use App\Models\VendorDoc;
use App\Support\Ewccv\BridgedSession;
use App\Support\Ewccv\ScrapeLauncher;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
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
 *
 * The run itself is started detached (ScrapeLauncher) and this job is over
 * in milliseconds: a run that held a worker for its 4–30 minutes made every
 * deploy in that window wait for it before Horizon restarted (2026-10-12),
 * so it never runs inside a worker again.
 */
class RunPendingEwccvLookups implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function uniqueFor(): int
    {
        return 600;
    }

    public function handle(ScrapeLauncher $launcher): void
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

        $pid = $launcher->launch($vendorIds, $belongsToVendorId);

        Log::info('RunPendingEwccvLookups: EWCCV lookup started detached', [
            'vendor_ids' => $vendorIds,
            'session_age_minutes' => $session['age_minutes'] ?? null,
            'pid' => $pid,
        ]);
    }

    /** A search that found nothing, or a tracking refusal, is not retried for this long. */
    public const RETRY_AFTER_HOURS = 24;

    /**
     * Vendors with an active workers comp policy EWCCV is not tracking yet
     * (never looked up, pending, failed or skipped). A vendor whose policy
     * EWCCV could not find, or refused, in the last RETRY_AFTER_HOURS is left
     * out: every browser session would otherwise search it again for the same
     * answer. Failures the run itself caused (no session, no token) retry.
     *
     * @return list<int>
     */
    public static function pendingVendorIds(int $belongsToVendorId): array
    {
        $retryBefore = Carbon::now()->subHours(self::RETRY_AFTER_HOURS);

        return VendorDoc::withoutGlobalScopes()
            ->where('type', 'workers')
            ->where('belongs_to_vendor_id', $belongsToVendorId)
            ->whereNotNull('vendor_id')
            ->where('expiration_date', '>=', Carbon::today())
            ->get(['vendor_id', 'options'])
            ->reject(function (VendorDoc $doc) use ($retryBefore) {
                $state = (array) ($doc->options['ewccv'] ?? []);
                if (($state['status'] ?? null) === 'tracking') {
                    return true;
                }
                $settled = in_array($state['reason'] ?? '', ['no_results', 'create_subscription_failed', 'no_match'], true);
                $checkedAt = filled($state['checked_at'] ?? null) ? Carbon::parse($state['checked_at']) : null;

                return $settled && $checkedAt && $checkedAt->gt($retryBefore);
            })
            ->pluck('vendor_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}
