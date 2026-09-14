<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves the receipt extension's update manifest and pack to the server's own
 * Chrome over HTTPS.
 *
 * The extension is force-installed by policy from an update URL. That URL was
 * a file:// path — which the Chrome of August accepted and the Chrome of
 * September (151) silently ignores: every repack since then was a pack nobody
 * ever fetched, and the browser kept running the August build. Chrome fetches
 * https without complaint, so the app serves the two files itself.
 *
 * The pack carries the bridge token, so the URL carries a secret segment and
 * anything else is a plain 404 — Chrome sends no credentials on these fetches.
 */
class MenardsExtensionUpdateController extends Controller
{
    public const FILES = [
        'update.xml' => 'application/xml',
        'menards.crx' => 'application/x-chrome-extension',
    ];

    public function __invoke(Request $request, string $secret, string $file): BinaryFileResponse
    {
        $expected = (string) config('services.menards.update_secret');

        if ($expected === '' || ! hash_equals($expected, $secret) || ! isset(self::FILES[$file])) {
            abort(404);
        }

        $path = rtrim((string) config('services.menards.extension_home'), '/') . '/' . $file;

        if (! is_file($path)) {
            abort(404);
        }

        $response = response()->file($path, ['Content-Type' => self::FILES[$file]]);
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
