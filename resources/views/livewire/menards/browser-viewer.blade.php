{{-- Poll keeps the status honest on its own: after a captcha click and a
     queued retry, the red callout flips green here without anyone refreshing.
     The iframe sits behind wire:ignore so polling never remounts the VNC
     session mid-click. --}}
<div class="space-y-4" wire:poll.10s>
    <div class="flex items-center justify-between">
        <div>
            <flux:heading size="xl">Menards Browser</flux:heading>
            <flux:text class="mt-1">The receipt-sync browser running on the server. When a security challenge or sign-in is needed, complete it right here.</flux:text>
        </div>
        @if($hasBrowserStack)
            <div class="flex items-center gap-2">
                <flux:button wire:click="syncNow" icon="arrow-down-tray">Sync now</flux:button>
                <flux:button wire:click="retrySignin" icon="arrow-path">Retry sign-in</flux:button>
            </div>
        @endif
    </div>

    @if (session('menards-retry'))
        <flux:callout icon="clock">
            <flux:callout.text>{{ session('menards-retry') }}</flux:callout.text>
        </flux:callout>
    @endif

    @if($needsAttention)
        <flux:callout color="red" icon="exclamation-triangle">
            <flux:callout.heading>Menards needs a human</flux:callout.heading>
            <flux:callout.text>
                @if(($needsSignin['reason'] ?? null) === 'challenge' && $wallShowing === false && $autoResumed)
                    The security check is cleared — signing in again. The status below updates on its own.
                @elseif(($needsSignin['reason'] ?? null) === 'challenge' && $wallShowing === false)
                    The security check is no longer on screen. Hit “Retry sign-in” to finish signing in.
                @elseif(($needsSignin['reason'] ?? null) === 'challenge')
                    Imperva is showing its security challenge. Click the hCaptcha in the browser below — once it clears, the sign-in starts again by itself.
                @elseif(($syncStatus['challenge'] ?? false) && $wallShowing === false && $autoResumed)
                    The security check is cleared — fetching new receipts now. The status below updates on its own.
                @elseif(($syncStatus['challenge'] ?? false) && $wallShowing === true)
                    Imperva is showing its security check. Click “I am human” in the browser below — once it clears, new receipts are fetched by themselves.
                @elseif($syncStatus['challenge'] ?? false)
                    The last sync met Imperva’s security check. If it is on screen below, click “I am human”; otherwise hit “Sync now”.
                @elseif($needsSignin !== null)
                    The automated sign-in failed. Finish signing in below, or fix what the page shows and hit “Retry sign-in”.
                @else
                    The extension reported an expired session. Sign in below, or hit “Retry sign-in” to let the server try first.
                @endif
                @if(!empty($needsSignin['at']) || !empty($syncStatus['at']))
                    <span class="block mt-1 text-sm opacity-75">Since {{ \Carbon\Carbon::parse($needsSignin['at'] ?? $syncStatus['at'])->diffForHumans() }}.</span>
                @endif
            </flux:callout.text>
        </flux:callout>
    @elseif($syncStatus !== null)
        <flux:callout color="green" icon="check-circle">
            <flux:callout.text>
                Last sync reported OK{{ isset($syncStatus['receipts']) ? ' — ' . $syncStatus['receipts'] . ' receipt(s)' : '' }}
                @if(!empty($syncStatus['at'])) ({{ \Carbon\Carbon::parse($syncStatus['at'])->diffForHumans() }}) @endif
            </flux:callout.text>
        </flux:callout>
    @endif

    @if($hasBrowserStack)
        <div wire:ignore class="rounded-lg border border-zinc-200 dark:border-zinc-700 overflow-hidden bg-zinc-900">
            <iframe
                src="/menards-vnc/vnc.html?autoconnect=true&resize=scale&reconnect=true&path=menards-vnc/websockify"
                title="Menards remote browser"
                class="w-full block"
                style="height: calc(100vh - 20rem); min-height: 480px;"
            ></iframe>
        </div>

        <flux:text class="text-sm">
            If the viewer fails to connect, the browser stack may be down on the server — run
            <span class="font-mono">php artisan menards:browser ensure</span> there, or check that the noVNC proxy
            (<span class="font-mono">/menards-vnc/</span>) is configured in nginx.
        </flux:text>
    @else
        <flux:callout icon="computer-desktop">
            <flux:callout.heading>The browser runs on the production server</flux:callout.heading>
            <flux:callout.text>
                This environment has no Menards browser stack (no <span class="font-mono">MENARDS_CHROMIUM_BINARY</span>,
                extension or noVNC proxy), so there is nothing to embed here. The status above is production's,
                mirrored by <span class="font-mono">php artisan menards:mirror-prod-flags</span>.
            </flux:callout.text>
        </flux:callout>
    @endif

    {{-- Receipts can also arrive from a browser YOU sign into — Imperva sees
         an ordinary person, so there is no wall to click. The server browser
         above stands down automatically (menards:browser sync / ensure)
         while a batch from this browser is fresh. --}}
    <flux:card class="space-y-4">
        <div>
            <flux:heading size="lg">Run the receipt extension in your own browser</flux:heading>
            <flux:text class="mt-1">
                Install the same extension in your own Edge or Chrome, signed in as yourself. Imperva sees a
                person at menards.com instead of a server, so there is no security check to click. While your
                browser has delivered recently, the server browser above stands down and leaves the fetching
                to you.
            </flux:text>
        </div>

        <flux:text class="text-sm">
            Your browser last delivered:
            @if($personalStatus)
                <strong>{{ ($personalStatus['ok'] ?? false) ? 'OK' : 'an error' }}</strong>
                @if(!empty($personalStatus['receipts'])) — {{ $personalStatus['receipts'] }} receipt(s) @endif
                @if(!empty($personalStatus['browser'])) from {{ $personalStatus['browser'] }} @endif
                @if(!empty($personalStatus['at'])) ({{ \Carbon\Carbon::parse($personalStatus['at'])->diffForHumans() }}) @endif
            @else
                never yet
            @endif
        </flux:text>

        <div class="space-y-2">
            <flux:heading size="sm">Set it up (Edge or Chrome)</flux:heading>
            <ol class="list-decimal list-inside space-y-2 text-sm">
                <li>
                    Download the extension and extract the zip to a folder you keep, e.g.
                    <span class="font-mono">Documents\hive-menards-extension</span>.
                    <div class="mt-1">
                        <flux:button size="sm" icon="arrow-down-tray" href="{{ route('menards.extension-source') }}">Download the extension</flux:button>
                    </div>
                </li>
                <li>
                    Open <span class="font-mono">edge://extensions</span> (or <span class="font-mono">chrome://extensions</span>),
                    turn on <strong>Developer mode</strong> (bottom left), click <strong>Load unpacked</strong>, and choose that folder.
                </li>
                <li>
                    Click the extension's <strong>Details</strong> → <strong>Extension options</strong>. Paste the Hive URL and
                    the bridge token below, then Save.
                    <div class="mt-2 space-y-2 max-w-md">
                        <flux:field>
                            <flux:label>Hive URL</flux:label>
                            <flux:input readonly value="{{ $hiveUrl }}" />
                        </flux:field>
                        <flux:field>
                            <flux:label>Bridge token</flux:label>
                            @if($bridgeToken !== null)
                                <div class="flex items-center gap-2">
                                    <flux:input readonly value="{{ $bridgeToken }}" />
                                    <flux:button size="sm" icon="clipboard" onclick="navigator.clipboard.writeText(@js($bridgeToken))">Copy</flux:button>
                                </div>
                            @else
                                <flux:button size="sm" wire:click="revealBridgeToken">Reveal</flux:button>
                            @endif
                        </flux:field>
                    </div>
                </li>
                <li>Sign in to menards.com once in that browser.</li>
                <li>
                    Click <strong>Sync now</strong> in the options page (or the toolbar icon) — new receipts arrive in
                    Hive within a minute or two. From then on it fetches daily, and whenever the browser starts after
                    a day away.
                </li>
            </ol>
            <flux:text class="text-sm opacity-75">
                When the extension changes: download it again, replace the folder's contents, then press Reload on
                <span class="font-mono">edge://extensions</span>.
            </flux:text>
        </div>

        <flux:accordion>
            <flux:accordion.item heading="If your PC is in a Windows domain">
                <flux:text class="text-sm mb-2">
                    A domain policy can force-install the extension instead of Load unpacked — IT pushes it once and
                    it is done for everyone. It only takes effect on a PC joined to the domain; both Edge and Chrome
                    ignore this policy on a personal machine.
                </flux:text>

                @if($extensionId && $updateSecretConfigured)
                    <flux:button size="sm" icon="arrow-down-tray" href="{{ route('menards.extension-policy') }}">Download the policy file (.reg)</flux:button>
                @else
                    <flux:button size="sm" icon="arrow-down-tray" disabled>Download the policy file (.reg)</flux:button>
                    <flux:text class="text-sm mt-1 opacity-75">
                        @if(!$extensionId)
                            Not available yet — the extension is not installed in the server browser, so its id is unknown.
                        @else
                            Not available — no update secret is configured (<span class="font-mono">MENARDS_EXTENSION_SECRET</span>).
                        @endif
                    </flux:text>
                @endif

                <ol class="list-decimal list-inside space-y-1 text-sm mt-2">
                    <li>Double-click the downloaded file and accept the UAC prompt.</li>
                    <li>Restart Edge (or Chrome).</li>
                    <li>Open <span class="font-mono">edge://extensions</span> and confirm "Hive — Menards Receipt Sync" is there.</li>
                    <li>Sign in to menards.com once in that browser.</li>
                </ol>
            </flux:accordion.item>
        </flux:accordion>
    </flux:card>
</div>
