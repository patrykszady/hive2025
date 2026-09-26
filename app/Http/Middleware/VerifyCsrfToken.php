<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfToken extends Middleware
{
    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * @var array
     */
    protected $except = [
        'webhooks/plaid',
        'webhooks/mailtrap/*',
        'webhooks/telnyx/*',
        'webhooks/nylas',
        'webauthn/*',
        'push/*',
        'api/ewccv/session',
        'api/menards/session',
        'api/menards/receipts',
        'api/menards/sync-status',
        'api/menards/solve-challenge',
        // Signed with X-TMV-Signature instead.
        'webhooks/trackmyvendor',
        // The central-admin proxy: POSTs under /admin/* carry ss-systems'
        // CSRF token, not this app's (the route also strips this app's
        // whole session/CSRF middleware — routes/web.php).
        'admin',
        'admin/*',
        // ss-systems/platform-kit's Pulse beacon (routes/web.php,
        // SsSystems\Platform\Pulse\BeaconController): navigator.sendBeacon
        // cannot set a CSRF header.
        'pulse',
    ];
}
