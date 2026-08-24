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
    ];
}
