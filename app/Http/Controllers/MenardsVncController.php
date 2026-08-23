<?php

namespace App\Http\Controllers;

use App\Services\MenardsRemoteBrowserService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Frames the server-side Menards browser inside Hive.
 *
 * WHY THIS NEEDS CARE
 *
 * That browser holds a live, signed-in Menards session, and its VNC runs with
 * no password — until now the only thing protecting it was x11vnc binding to
 * loopback. Publishing it through nginx replaces that protection with this
 * gate, so `gate()` IS the security boundary: whoever passes it can drive a
 * browser logged into the company's Menards account.
 *
 * It therefore denies unless it can affirmatively prove an Admin is asking.
 * An auth_request handler that fails open is the standard way this kind of
 * proxy gets breached, so there is no path through here that returns success
 * by omission.
 */
class MenardsVncController extends Controller
{
    /**
     * nginx auth_request target for /menards-vnc/*.
     *
     * nginx discards the body and reads only the status: 2xx allows, 401/403
     * deny, anything else becomes a 500. Never redirect from here.
     */
    public function gate(Request $request): Response
    {
        $user = $request->user();

        if (! $user) {
            return response('', 401);
        }

        if ($user->vendor_role !== 'Admin') {
            Log::channel('menards')->warning('Menards VNC: non-admin denied', [
                'user_id' => $user->id,
                'ip' => $request->ip(),
            ]);

            return response('', 403);
        }

        return response('', 204);
    }

    /** The page that frames it. Same rule as the gate, enforced independently. */
    public function show(Request $request, MenardsRemoteBrowserService $browser)
    {
        abort_unless($request->user()?->vendor_role === 'Admin', 403);

        return view('menards.vnc', ['status' => $browser->status()]);
    }
}
