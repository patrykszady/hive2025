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
        <flux:button wire:click="retrySignin" icon="arrow-path">Retry sign-in</flux:button>
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
                @if(($needsSignin['reason'] ?? null) === 'challenge')
                    Imperva is showing its security challenge. Click the hCaptcha in the browser below, then hit “Retry sign-in”.
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
</div>
