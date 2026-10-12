<?php

namespace App\Http\Controllers;

use App\Jobs\RunPendingEwccvLookups;
use App\Models\VendorDoc;
use App\Support\Ewccv\BridgedSession;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * The no-install way to hand Hive an EWCCV search session: a bookmarklet.
 *
 * ewccv.com's search credential only ever comes from a real person's browser
 * (see EwccvSessionController). The extension in scripts/ewccv-session-bridge
 * does the hand-off automatically, but it has to be loaded unpacked; this page
 * offers the same hand-off as a bookmark to drag once and click on a signed-in
 * ewccv.com tab. The bookmark loads the extension's own page-world minter
 * from this server (ewccv.com sets no CSP), reads or mints the accessToken,
 * and POSTs it to /api/ewccv/session with the bridge token — which is why the
 * page is for the platform admin only. The page also shows what is waiting.
 */
class EwccvBridgeController extends Controller
{
    public function show(): View
    {
        Gate::authorize('platform-admin');

        $belongsTo = (int) config('services.ewccv.belongs_to_vendor_id', 1);
        $pendingIds = RunPendingEwccvLookups::pendingVendorIds($belongsTo);
        $pending = VendorDoc::withoutGlobalScopes()
            ->where('type', 'workers')
            ->where('belongs_to_vendor_id', $belongsTo)
            ->whereIn('vendor_id', $pendingIds)
            ->with('vendor')
            ->get()
            ->unique('vendor_id')
            ->map(fn (VendorDoc $doc) => [
                'vendor' => (string) ($doc->vendor?->business_name ?? "vendor {$doc->vendor_id}"),
                'policy' => (string) $doc->number,
                'state' => (string) ($doc->options['ewccv']['status'] ?? 'never looked up'),
            ])
            ->values();

        return view('ewccv.bridge', [
            'bookmarklet' => $this->bookmarklet(),
            'configured' => filled(config('services.ewccv.bridge_token')),
            'session' => BridgedSession::fresh(),
            'pending' => $pending,
        ]);
    }

    /** The extension's page-world minter, served so the bookmarklet can load it on ewccv.com. */
    public function minter(): Response
    {
        return response(file_get_contents(base_path('scripts/ewccv-session-bridge/minter.js')), 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'public, max-age=300',
        ]);
    }

    /**
     * Runs in the page world of a signed-in ewccv.com tab: loads the minter,
     * takes its reply (an accessToken the app already minted, or one minted
     * now), and hands it to Hive. Every outcome ends in an alert, so the click
     * always says what happened.
     */
    protected function bookmarklet(): string
    {
        $base = rtrim((string) config('app.url'), '/');
        $token = json_encode((string) config('services.ewccv.bridge_token'), JSON_UNESCAPED_SLASHES);
        $host = json_encode($base, JSON_UNESCAPED_SLASHES);
        $minter = json_encode($base.'/ewccv/minter.js', JSON_UNESCAPED_SLASHES);

        $js = <<<JS
(function(){var T={$token},H={$host},M={$minter};
function tell(m){alert('Hive: '+m);}
function post(p){fetch(H+'/api/ewccv/session',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json','Authorization':'Bearer '+T},body:JSON.stringify(p)}).then(function(r){return r.json();}).then(function(j){tell(j.message||j.error||JSON.stringify(j));}).catch(function(e){tell('could not reach Hive: '+e.message);});}
if(location.hostname!=='www.ewccv.com'){tell('open www.ewccv.com/cvs/search, sign in, then click this bookmark');return;}
function onReply(e){if(e.source!==window||!e.data||e.data.source!=='hive-ewccv-bridge')return;window.removeEventListener('message',onReply);if(e.data.ok){post({accessToken:e.data.accessToken,reused:!!e.data.reused,jwt:e.data.jwt||null,stateToken:e.data.stateToken||null});}else{tell(e.data.error||'EWCCV did not hand over a session');}}
window.addEventListener('message',onReply);
var s=document.createElement('script');s.src=M+'?v='+Date.now();s.onerror=function(){window.removeEventListener('message',onReply);tell('could not load the minter from Hive');};document.head.appendChild(s);
setTimeout(function(){window.removeEventListener('message',onReply);},35000);
})();
JS;

        return 'javascript:'.str_replace(["\n", "\r"], '', $js);
    }
}
