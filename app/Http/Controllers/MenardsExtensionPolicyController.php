<?php

namespace App\Http\Controllers;

use App\Services\MenardsRemoteBrowserService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * A Windows Registry file that force-installs the receipt extension in Edge
 * or Chrome via policy, for a PC joined to a Windows domain — the install
 * card's second, collapsed path.
 *
 * It is NOT the primary path: neither Edge nor Chrome honours a
 * force-install policy on a personal, non-domain-joined machine, which is
 * why the card leads with "Load unpacked" from a downloaded zip
 * (MenardsExtensionSourceController) instead. This stays useful for anyone
 * whose PC IS domain-joined, where IT can push the registry key once for
 * everyone.
 *
 * Refuses to serve anything when the extension id or the update secret is
 * unknown — the card disables its download button for the identical reason,
 * so reaching this directly with either missing means asking by hand.
 */
class MenardsExtensionPolicyController extends Controller
{
    public function __invoke(Request $request, MenardsRemoteBrowserService $browser): Response
    {
        abort_unless($request->user()?->can('menards-browser'), 403);

        $id = $browser->extensionId();
        $secret = trim((string) config('services.menards.update_secret'));

        if (! $id || $secret === '') {
            abort(404);
        }

        $updateUrl = rtrim((string) config('app.url'), '/') . '/menards-extension/' . $secret . '/update.xml';
        $entry = $id . ';' . $updateUrl;

        $contents = implode("\r\n", [
            'Windows Registry Editor Version 5.00',
            '',
            '[HKEY_LOCAL_MACHINE\SOFTWARE\Policies\Microsoft\Edge\ExtensionInstallForcelist]',
            '"1"="' . $entry . '"',
            '',
            '[HKEY_LOCAL_MACHINE\SOFTWARE\Policies\Google\Chrome\ExtensionInstallForcelist]',
            '"1"="' . $entry . '"',
            '',
        ]);

        return response($contents, 200, [
            'Content-Type' => 'text/plain',
            'Content-Disposition' => 'attachment; filename="hive-menards-extension-policy.reg"',
        ]);
    }
}
