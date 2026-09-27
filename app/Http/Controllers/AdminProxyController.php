<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use SsSystems\Platform\Http\AdminProxyRelay;
use Symfony\Component\HttpFoundation\Response;

/**
 * Transparent relay of /admin/* to the ss-systems central admin — the
 * implementation lives in SsSystems\Platform\Http\AdminProxyRelay (kit
 * 0.13.0, ss-platform-kit/docs/audit-2026-09-27/admin-api-skeleton.md unit
 * #1, ss-systems/CLAUDE.md's "What this app is" for the proxy contract).
 * This app has no admin of its own — the whole editing experience (login,
 * Livewire components, everything under /admin) lives on ss-systems and is
 * served here byte-for-byte. The browser only ever talks to THIS origin;
 * this controller is the only thing that knows ss-systems exists.
 *
 * Hive is single-tenant from ss-systems' point of view (one connected site,
 * key 'hive'), so like jpeterson there is no per-request Site overlay to
 * bind before rendering the down page — there is only ever one brand here,
 * unlike gsc's multi-tenant $beforeDown hook.
 *
 * $circuitBreaker: true and $forwardPort: true (the default) both preserve
 * hive's pre-kit behaviour byte-for-byte — hive already had the 60s breaker
 * and the empty-secret early return (the other three sites did not; see
 * AdminProxyRelay's class docblock), and already sent X-Forwarded-Port.
 * Neither is a behaviour change here.
 */
class AdminProxyController extends Controller
{
    public function handle(Request $request, string $path = ''): Response
    {
        return $this->relay()->handle($request, $path);
    }

    protected function relay(): AdminProxyRelay
    {
        return new AdminProxyRelay(
            baseUrl: (string) config('services.ss.url'),
            adminPrefix: (string) config('services.ss.admin_prefix'),
            siteKey: (string) config('services.ss.site_key'),
            serviceSecret: (string) config('services.ss.service_secret'),
            timeout: (int) config('services.ss.timeout', 30),
            connectTimeout: (int) config('services.ss.connect_timeout', 5),
            circuitBreaker: true,
        );
    }
}
