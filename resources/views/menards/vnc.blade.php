<x-layouts.app :title="'Menards browser'">
    <div class="space-y-4">
        <div class="flex items-start justify-between gap-4 flex-wrap">
            <div>
                <flux:heading size="xl">Menards browser</flux:heading>
                <flux:subheading>
                    The signed-in browser running on the server. This is its actual screen —
                    what you click here, it clicks there.
                </flux:subheading>
            </div>

            <div class="flex items-center gap-2">
                @if ($status['signed_in'])
                    <flux:badge color="green" icon="check-circle">Signed in</flux:badge>
                @else
                    <flux:badge color="amber" icon="exclamation-triangle">Not signed in</flux:badge>
                @endif

                @if (! $status['running'] || ! $status['chrome'])
                    <flux:badge color="red" icon="x-circle">Browser down</flux:badge>
                @elseif (! $vncReachable)
                    <flux:badge color="red" icon="x-circle">Gateway down</flux:badge>
                @endif
            </div>
        </div>

        @php
            // Every real Menards page titles itself "… at Menards®". The Imperva
            // interstitial sets no title, so Chrome falls back to the bare URL —
            // the same test the service and the controller use.
            $page = $status['page'] ?? '';
            $walled = str_contains($page, 'menards.com/') && ! str_contains($page, 'at Menards');
        @endphp

        @if (! $status['running'] || ! $status['chrome'])
            <flux:callout variant="danger" icon="x-circle" heading="The browser is not running">
                Nothing will render below. On the server:
                <code class="font-mono text-sm">php artisan menards:browser ensure</code>
            </flux:callout>
        @elseif (! $vncReachable)
            <flux:callout variant="danger" icon="x-circle" heading="The screen gateway is not responding">
                Chrome is running but websockify is not listening, so there is nothing to show.
                Restart with <code class="font-mono text-sm">php artisan menards:browser start</code>.
            </flux:callout>
        @elseif ($walled)
            <flux:callout variant="warning" icon="shield-exclamation" heading="Imperva is showing a security check">
                Click <strong>&ldquo;I am human&rdquo;</strong> in the frame below. Move the pointer into the
                box rather than clicking dead centre instantly &mdash; that usually avoids being escalated to an
                image puzzle. Once it clears, the stored credentials take over; no typing needed.
            </flux:callout>
        @endif

        @if (! $status['extension'])
            <flux:callout variant="danger" icon="puzzle-piece" heading="The receipt extension is not installed">
                The browser will work, but nothing will ever sync. Re-run
                <code class="font-mono text-sm">bash scripts/provision-menards-browser.sh</code> on the server.
            </flux:callout>
        @elseif (! $status['configured'])
            <flux:callout variant="danger" icon="key" heading="The extension has no Hive URL or token">
                It cannot deliver receipts. Check <code class="font-mono text-sm">MENARDS_BRIDGE_TOKEN</code>,
                then <code class="font-mono text-sm">php artisan menards:browser start</code>.
            </flux:callout>
        @endif

        <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 overflow-hidden bg-zinc-100 dark:bg-zinc-900">
            {{-- The display is 1280x900; resize=scale fits it to the frame. --}}
            <iframe
                id="menards-vnc-frame"
                src="/menards-vnc/vnc.html?autoconnect=1&resize=scale&reconnect=1"
                class="w-full block"
                style="height: min(78vh, 900px); border: 0;"
                title="Menards browser screen"
            ></iframe>
        </div>

        <div class="flex flex-wrap gap-x-6 gap-y-1 text-sm text-zinc-500 dark:text-zinc-400">
            <div><span class="font-medium">Page:</span> {{ $page ?: '—' }}</div>
            <div><span class="font-medium">Posts to:</span> {{ $status['posts_to'] ?: '—' }}</div>
            <div>
                <span class="font-medium">Extension:</span> {{ $status['extension'] ? 'installed' : 'MISSING' }},
                config {{ $status['configured'] ? 'present' : 'MISSING' }}
            </div>
        </div>
    </div>

    <script>
        // Report failures that only exist in this browser. The iframe is
        // same-origin, but a 401/500 from the proxy still renders as an empty
        // rectangle with no event we can catch — so probe the same URL directly
        // and report what it actually returns. Without this, "the screen is
        // blank" leaves nothing behind in any log.
        (function () {
            const report = (kind, detail, code) => {
                fetch('{{ route('menards.vnc.report') }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                    },
                    body: JSON.stringify({ kind, detail, code }),
                }).catch(() => {});
            };

            fetch('/menards-vnc/vnc.html', { method: 'GET', credentials: 'same-origin' })
                .then((res) => {
                    if (!res.ok) {
                        report('proxy_error', 'noVNC proxy returned a non-OK status', res.status);
                    }
                })
                .catch((err) => report('proxy_unreachable', String(err?.message ?? err).slice(0, 400)));

            window.addEventListener('error', (e) => {
                if (e?.target?.id === 'menards-vnc-frame') {
                    report('iframe_error', 'the viewer iframe failed to load');
                }
            }, true);
        })();
    </script>
</x-layouts.app>
