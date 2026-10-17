<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Auth\AppWebSessionLinks;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

/**
 * The guest-accessible other half of POST /api/app/v1/web-session: redeems
 * a single-use nonce (see AppWebSessionLinks), logs its user into the web
 * session with no "remember" cookie, and redirects to the path the app
 * asked for. Reached only through a `signed` route — see routes/web.php.
 */
class WebSessionLoginController extends Controller
{
    public function consume(string $nonce, AppWebSessionLinks $links): RedirectResponse
    {
        $payload = $links->consume($nonce);

        abort_unless($payload, 403, 'This link has expired or was already used.');

        $user = User::find($payload['user_id']);

        // Re-checked at the door, not just at issuance: a token revoked in
        // the 60 seconds between the app requesting this link and someone
        // opening it should not still log in — the same reasoning
        // EnsureAppUserAllowed re-checks on every app API request.
        abort_unless($user && (int) $user->primary_vendor_id === 1, 403);

        // A fresh session id at sign-in, as the web login does, so a session
        // cookie set before this link was opened never becomes the signed-in one.
        request()->session()->regenerate();
        Auth::login($user, remember: false);

        return redirect()->to($payload['path']);
    }
}
