<?php

namespace App\Http\Controllers;

use App\Jobs\RunPendingEwccvLookups;
use App\Support\Ewccv\BridgedSession;
use App\Support\Ewccv\ScraperLock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Receives an EWCCV search session from the browser extension.
 *
 * Why this exists: ewccv.com gates its search API behind reCAPTCHA v3
 * Enterprise, and every token this server can produce is rejected — provider
 * tokens (anti-captcha, 2captcha) AND tokens minted by EWCCV's own page inside
 * an automated Chrome. A control probe proved the tokens are structurally
 * valid (an invalid one comes back "MALFORMED", ours "No reason provided"),
 * so the wall is Google's score for an automated session, which no amount of
 * solver spend changes.
 *
 * Patryk's own browser has no such problem — real person, residential IP,
 * clean fingerprint. So the server stops trying to pass reCAPTCHA and instead
 * borrows the accessToken his browser already earned. Same play as the Yelp
 * session bridge on gs.construction, which solved the same class of problem.
 *
 * Called by an MV3 service worker, which carries no session and no CSRF token:
 * auth is a bearer token compared in constant time.
 */
class EwccvSessionController extends Controller
{
    /** Where the scraper looks for the handed-off session. */
    public const CACHE_KEY = 'ewccv.search_session';

    /** An accessToken is a JWT; a jar of them is still small. */
    protected const MAX_BYTES = 64 * 1024;

    public function __invoke(Request $request): JsonResponse
    {
        if ($denied = $this->denyUnlessBridge($request)) {
            return $denied;
        }

        if (strlen((string) $request->getContent()) > self::MAX_BYTES) {
            return response()->json(['error' => 'Payload too large.'], 413);
        }

        $data = $request->validate([
            'accessToken' => ['required', 'string', 'min:20', 'max:8000'],
            'jwt' => ['nullable', 'string', 'max:8000'],
            'stateToken' => ['nullable', 'string', 'max:200'],
            'reused' => ['nullable', 'boolean'],
        ]);

        $belongsToVendorId = (int) config('services.ewccv.belongs_to_vendor_id', 1);

        // When minting fails in the browser, the minter falls back to the copy
        // the page already holds — which an earlier run has usually spent. Two
        // runs on 2026-10-12 died on their first search that way. Refuse it,
        // and say what to do, rather than store a credential that cannot search.
        if (! empty($data['reused'])) {
            Log::info('EWCCV bridge: reused credential refused');

            return response()->json([
                'ok' => false,
                'reused' => true,
                'error' => 'That EWCCV credential was already used up. Do one search on ewccv.com by hand, then send again.',
                'pending_lookups' => RunPendingEwccvLookups::pendingCount($belongsToVendorId),
            ], 409);
        }

        // A file under storage, not the cache: a deploy's optimize:clear
        // empties the file cache and took the session with it (2026-10-12).
        // The extension refreshes on its own schedule, so a stale entry is
        // replaced long before BridgedSession stops reading it.
        BridgedSession::store([
            'access_token' => $data['accessToken'],
            'jwt' => $data['jwt'] ?? null,
            'state_token' => $data['stateToken'] ?? null,
        ]);

        // The credential lives minutes: whatever is waiting for a search runs
        // now, in one run per credential, unless a run is already on (it
        // reads its own copy; the next hand-off covers what it leaves). The
        // extension sends only when Hive reports pending work, and on every
        // page load and button press; a minute's debounce covers the latter.
        // Pending work is tracking lookups plus an audit's as-of coverage checks.
        $pending = RunPendingEwccvLookups::pendingCount($belongsToVendorId);
        if ($pending > 0 && ! ScraperLock::isHeld() && Cache::add('ewccv:lookup-dispatched', true, now()->addMinute())) {
            RunPendingEwccvLookups::dispatch();
        }

        Log::info('EWCCV bridge: session received', [
            'access_token_len' => strlen($data['accessToken']),
            'has_jwt' => ! empty($data['jwt']),
            'pending_lookups' => $pending,
        ]);

        return response()->json([
            'ok' => true,
            'message' => $pending === 0
                ? 'EWCCV search session stored; every active policy is already tracked.'
                : sprintf('EWCCV search session stored; verifying %d vendor(s) now.', $pending),
            'expires_in_hours' => 12,
            'pending_lookups' => $pending,
        ]);
    }

    /**
     * What a browser session would be spent on right now: the extension polls
     * this while an ewccv.com tab is open and mints only when something is
     * waiting and no run is on.
     */
    public function pending(Request $request): JsonResponse
    {
        if ($denied = $this->denyUnlessBridge($request)) {
            return $denied;
        }

        $belongsToVendorId = (int) config('services.ewccv.belongs_to_vendor_id', 1);

        return response()->json([
            'pending' => RunPendingEwccvLookups::pendingCount($belongsToVendorId),
            'running' => ScraperLock::isHeld(),
            'session' => BridgedSession::fresh() ? 'fresh' : 'none',
        ]);
    }

    /** The bearer token the extension carries, compared in constant time. */
    protected function denyUnlessBridge(Request $request): ?JsonResponse
    {
        $expected = (string) config('services.ewccv.bridge_token');

        if ($expected === '') {
            return response()->json(['error' => 'EWCCV bridge is not configured on the server.'], 503);
        }

        if (! hash_equals($expected, (string) $request->bearerToken())) {
            Log::warning('EWCCV bridge: bad token', ['ip' => $request->ip()]);

            return response()->json(['error' => 'Unauthorized.'], 401);
        }

        return null;
    }
}
