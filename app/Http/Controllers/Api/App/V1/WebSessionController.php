<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\OpenWebSessionRequest;
use App\Services\Auth\AppWebSessionLinks;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\URL;

/**
 * Lets the Hive Contractors app open any web page already signed in — the
 * "Open full page" escape hatch for anything the app hasn't built a native
 * screen for yet. See WebSessionLoginController for the other half (the
 * guest route the returned URL points at) and AppWebSessionLinks for the
 * single-use nonce the signature protects.
 */
class WebSessionController extends Controller
{
    public function store(OpenWebSessionRequest $request, AppWebSessionLinks $links): JsonResponse
    {
        $nonce = $links->issue($request->user(), (string) $request->validated('path'));

        $url = URL::temporarySignedRoute(
            'web-session.consume',
            now()->addSeconds(AppWebSessionLinks::TTL),
            ['nonce' => $nonce]
        );

        return response()->json(['url' => $url]);
    }
}
