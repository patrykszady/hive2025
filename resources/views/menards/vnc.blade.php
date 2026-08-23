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
                @endif
            </div>
        </div>

        @php
            // Every real Menards page titles itself "… at Menards®". The Imperva
            // interstitial sets no title at all, so Chrome falls back to the bare
            // URL — that is the wall's signature, same test the service uses.
            $page = $status['page'] ?? '';
            $walled = str_contains($page, 'menards.com/') && ! str_contains($page, 'at Menards');
        @endphp

        @if (! $status['running'] || ! $status['chrome'])
            <flux:callout variant="danger" icon="x-circle" heading="The browser is not running">
                Nothing will render below. On the server:
                <code class="font-mono text-sm">php artisan menards:browser ensure</code>
            </flux:callout>
        @elseif ($walled)
            <flux:callout variant="warning" icon="shield-exclamation" heading="Imperva is showing a security check">
                Click <strong>&ldquo;I am human&rdquo;</strong> in the frame below. Move the pointer into the
                box rather than clicking dead centre instantly &mdash; that usually avoids being escalated to an
                image puzzle. Once it clears, the stored credentials take it from there; no typing needed.
            </flux:callout>
        @endif

        <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 overflow-hidden bg-zinc-100 dark:bg-zinc-900">
            {{-- The display is 1280x900; resize=scale fits it to the frame. --}}
            <iframe
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
</x-layouts.app>
