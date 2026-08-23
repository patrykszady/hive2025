@props([
    'href',
    'label' => 'Download',
    'busyLabel' => 'Preparing…',
    'icon' => 'arrow-down-tray',
    'size' => 'xs',
    'variant' => 'ghost',
    'menuItem' => false,
])

{{--
    A download control that disables itself while the file is being produced.

    A plain <a href> download fires no completion event — the browser never
    navigates, so there is nothing to listen for. The server therefore echoes a
    one-shot token back as a cookie (see DownloadCookie), and this polls for it:
    the button re-enables the moment the response actually lands, not on a guessed
    timer. The timeout is only a backstop for a request that dies outright.

    It matters most on "Download all", which re-renders every waiver in the draw
    and can take a while — long enough to invite a second click that starts the
    whole job again.
--}}
<span
    x-data="{
        busy: false,
        token: null,
        start() {
            if (this.busy) return false;
            this.busy = true;
            this.token = 'dl' + Date.now() + Math.random().toString(36).slice(2, 8);
            this.poll();
            return true;
        },
        poll() {
            const started = Date.now();
            const tick = () => {
                if (document.cookie.includes(this.token + '=')) {
                    document.cookie = this.token + '=; Max-Age=0; path=/';
                    this.busy = false;
                    return;
                }
                // Backstop: a failed request never sets the cookie, and the
                // control must not stay dead.
                if (Date.now() - started > 120000) { this.busy = false; return; }
                setTimeout(tick, 400);
            };
            setTimeout(tick, 400);
        },
        go(e) {
            if (!this.start()) { e.preventDefault(); return; }
            e.currentTarget.setAttribute('href', @js($href) + (@js(str_contains($href, '?')) ? '&' : '?') + 'dl=' + this.token);
        },
    }"
    x-bind:class="busy ? 'pointer-events-none opacity-60' : ''"
    x-bind:aria-busy="busy"
>
    @if($menuItem)
        <flux:menu.item :icon="$icon" href="{{ $href }}" x-on:click="go($event)">
            <span x-text="busy ? @js($busyLabel) : @js($label)">{{ $label }}</span>
        </flux:menu.item>
    @else
        <flux:button :size="$size" :variant="$variant" :icon="$icon" href="{{ $href }}" x-on:click="go($event)">
            <span x-text="busy ? @js($busyLabel) : @js($label)">{{ $label }}</span>
        </flux:button>
    @endif
</span>
