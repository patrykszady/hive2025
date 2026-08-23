<?php

namespace App\Support;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Echo a download's one-shot token back to the browser as a cookie.
 *
 * A file download fires no event the page can observe — the browser never
 * navigates, so a button has no way to know the response arrived. The client
 * sends a token as ?dl=…, and seeing that token come back as a cookie is the
 * signal that the file is on its way, which is what lets the button re-enable
 * at the right moment instead of on a timer.
 */
class DownloadCookie
{
    /**
     * @template T of Response
     *
     * @param  T  $response
     * @return T
     */
    public static function attach(Response $response, Request $request): Response
    {
        $token = (string) $request->query('dl', '');

        // Client-generated and echoed straight back, so keep it to the shape we
        // issue — never reflect arbitrary input into a Set-Cookie header.
        if ($token === '' || preg_match('/^dl[a-z0-9]{1,32}$/i', $token) !== 1) {
            return $response;
        }

        // Readable by JS (that is the entire point) and short-lived.
        $response->headers->setCookie(
            Cookie::create($token, '1', time() + 120, '/', null, $request->isSecure(), false, false, Cookie::SAMESITE_LAX)
        );

        return $response;
    }
}
