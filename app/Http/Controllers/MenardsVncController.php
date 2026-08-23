<?php

namespace App\Http\Controllers;

use App\Services\MenardsRemoteBrowserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Frames the server-side Menards browser inside Hive.
 *
 * WHY THIS NEEDS CARE
 *
 * That browser holds a live, signed-in Menards session, and its VNC runs with
 * no password — until this page existed, the only thing protecting it was
 * x11vnc binding to loopback. Publishing it through nginx replaces that with
 * this gate, so `gate()` IS the security boundary: whoever passes it can drive
 * a browser logged into the company's Menards account.
 *
 * It therefore denies unless it can affirmatively prove an Admin is asking.
 *
 * EVERYTHING THAT GOES WRONG HERE IS LOGGED TO THE `menards` CHANNEL.
 * This page is the place someone looks when the sync has stopped, so it is the
 * worst possible place for a silent failure — a blank frame with no explanation
 * is how the original two-week outage stayed invisible. Server-side problems are
 * logged when the page renders; failures that only exist in the viewer's browser
 * (the proxy refusing, the WebSocket dropping) are reported back by the page
 * itself through report().
 */
class MenardsVncController extends Controller
{
    /**
     * nginx auth_request target for /menards-vnc/*.
     *
     * nginx discards the body and reads only the status: 2xx allows, 401/403
     * deny, anything else becomes a 500. Never redirect from here — Laravel's
     * `auth` middleware did exactly that and turned every proxied request into
     * a 500 ("auth request unexpected status: 302").
     */
    public function gate(Request $request): Response
    {
        $user = $request->user();

        if (! $user) {
            // Not logged in at all: ordinary, and noisy if logged per asset
            // request, so this one stays silent by design.
            return response('', 401);
        }

        if ($user->vendor_role !== 'Admin') {
            Log::channel('menards')->warning('Menards VNC: non-admin denied', [
                'user_id' => $user->id,
                'email' => $user->email,
                'ip' => $request->ip(),
            ]);

            return response('', 403);
        }

        return response('', 204);
    }

    /** The page that frames it. Same rule as the gate, enforced independently. */
    public function show(Request $request, MenardsRemoteBrowserService $browser)
    {
        $user = $request->user();

        if ($user?->vendor_role !== 'Admin') {
            Log::channel('menards')->warning('Menards VNC: page access denied', [
                'user_id' => $user?->id,
                'ip' => $request->ip(),
            ]);

            abort(403);
        }

        $status = $browser->status();
        $reachable = $browser->vncReachable();

        $this->logPageState($status, $reachable, $user->id);

        return view('menards.vnc', [
            'status' => $status,
            'vncReachable' => $reachable,
            'vncBase' => (string) config('services.menards.vnc_url', '/menards-vnc'),
        ]);
    }

    /**
     * Client-side failure reports from the viewer page.
     *
     * The interesting failures are only visible in the browser: the proxy
     * returning 401/500 for the iframe, or the WebSocket closing mid-session.
     * Without this they would show as a blank rectangle and nothing else.
     */
    public function report(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user?->vendor_role !== 'Admin') {
            return response()->json(['ok' => false], 403);
        }

        $data = $request->validate([
            'kind' => ['required', 'string', 'max:40'],
            'detail' => ['nullable', 'string', 'max:500'],
            'code' => ['nullable', 'integer'],
        ]);

        Log::channel('menards')->error('Menards VNC: viewer reported a failure', [
            'kind' => $data['kind'],
            'detail' => $data['detail'] ?? null,
            'code' => $data['code'] ?? null,
            'user_id' => $user->id,
        ]);

        return response()->json(['ok' => true]);
    }

    /**
     * Record what the page found, at a level that matches how broken it is.
     *
     * Deliberately not one blanket error: a challenge wall is expected and
     * recoverable by clicking, while a missing extension means nothing will ever
     * sync no matter who clicks what. Logging both the same way would bury the
     * second in the noise of the first.
     */
    protected function logPageState(array $status, bool $reachable, int $userId): void
    {
        $context = ['user_id' => $userId, 'page' => $status['page'] ?? null];

        if (! $status['running'] || ! $status['chrome']) {
            Log::channel('menards')->error('Menards VNC: viewer opened but the browser is not running', $context + [
                'running' => $status['running'],
                'chrome' => $status['chrome'],
            ]);

            return;
        }

        if (! $reachable) {
            Log::channel('menards')->error(
                'Menards VNC: viewer opened but the noVNC gateway is unreachable — websockify is probably dead',
                $context
            );

            return;
        }

        if (! $status['extension']) {
            Log::channel('menards')->error('Menards VNC: the receipt extension is not installed — nothing can sync', $context);
        }

        if (! $status['configured']) {
            Log::channel('menards')->error('Menards VNC: the extension has no Hive URL or token — it cannot deliver receipts', $context);
        }

        // Same test the service uses: every real Menards page titles itself
        // "… at Menards®", and the Imperva interstitial sets no title at all.
        $page = $status['page'] ?? '';

        if (str_contains($page, 'menards.com/') && ! str_contains($page, 'at Menards')) {
            Log::channel('menards')->warning('Menards VNC: viewer opened to an Imperva challenge wall', $context);
        }
    }
}
