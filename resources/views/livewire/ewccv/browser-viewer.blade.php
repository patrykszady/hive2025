{{-- The iframe sits behind wire:ignore so polling never remounts the VNC session. --}}
<div class="space-y-4" wire:poll.15s>
    <div class="flex items-center justify-between gap-4">
        <div>
            <flux:heading size="xl">EWCCV Browser</flux:heading>
            <flux:text class="mt-1">The Chrome on the server that runs workers comp lookups on EWCCV, with nobody's own browser open.</flux:text>
        </div>
        @if($running)
            <flux:button wire:click="stop" icon="stop">Stop</flux:button>
        @else
            <flux:button wire:click="start" icon="play" variant="primary">Start</flux:button>
        @endif
    </div>

    @if(session('ewccv-browser'))
        <flux:callout icon="information-circle"><flux:callout.text>{{ session('ewccv-browser') }}</flux:callout.text></flux:callout>
    @endif

    @if($running)
        <div wire:ignore class="rounded-lg border border-zinc-200 dark:border-zinc-700 overflow-hidden bg-zinc-900">
            <iframe
                src="/ewccv-vnc/vnc.html?autoconnect=true&resize=scale&reconnect=true&path=ewccv-vnc/websockify"
                title="EWCCV server browser"
                class="w-full block"
                style="height: calc(100vh - 20rem); min-height: 480px;"
            ></iframe>
        </div>
        <flux:text class="text-sm">
            If the viewer does not connect, check that the noVNC proxy (<span class="font-mono">/ewccv-vnc/</span>) is in nginx, or run
            <span class="font-mono">php artisan ewccv:browser status</span> on the server.
        </flux:text>
    @else
        <flux:callout icon="computer-desktop">
            <flux:callout.heading>The browser is not running</flux:callout.heading>
            <flux:callout.text>Start it to run EWCCV lookups on the server. It signs itself in and checks Hive every minute for waiting lookups.</flux:callout.text>
        </flux:callout>
    @endif
</div>
