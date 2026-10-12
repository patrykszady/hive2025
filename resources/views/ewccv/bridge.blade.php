<x-layouts.app title="EWCCV session bridge">
    <div class="mx-auto max-w-3xl space-y-6 p-4 sm:p-6">
        <div>
            <flux:heading size="xl">EWCCV session bridge</flux:heading>
            <flux:subheading>
                EWCCV only lets a real person's browser search. Hand yours over once; Hive verifies every waiting policy while it lasts.
            </flux:subheading>
        </div>

        @unless($configured)
            <flux:callout variant="danger" icon="exclamation-triangle" heading="EWCCV_BRIDGE_TOKEN is not set on this server">
                The hand-off endpoint refuses every session until it is.
            </flux:callout>
        @endunless

        <flux:card class="space-y-4">
            <flux:heading size="lg">1. Keep this bookmark</flux:heading>
            <flux:text>Drag the button to your bookmarks bar (or right-click it, copy the link, and make a bookmark from it). It carries this server's bridge token, so it is yours alone.</flux:text>
            <a
                href="{{ $bookmarklet }}"
                onclick="alert('Drag this button to your bookmarks bar instead of clicking it here.'); return false;"
                class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow hover:bg-indigo-700"
                title="Send EWCCV session to Hive"
            >
                <flux:icon.bookmark class="size-4" />
                Send EWCCV session to Hive
            </a>
        </flux:card>

        <flux:card class="space-y-3">
            <flux:heading size="lg">2. Sign in and click it</flux:heading>
            <ol class="list-decimal space-y-1 pl-5 text-sm text-zinc-700 dark:text-zinc-300">
                <li>Open <a href="https://www.ewccv.com/cvs/search" target="_blank" rel="noopener" class="text-indigo-600 underline">ewccv.com/cvs/search</a> and sign in with the link it emails to patryk.szady@live.com.</li>
                <li>Click the bookmark on that tab. If EWCCV refuses to mint a token silently, do one search by hand on the page and click again.</li>
                <li>The alert says what Hive did, for example "verifying 12 vendor(s) now". Watch <a href="{{ url('/horizon') }}" target="_blank" class="text-indigo-600 underline">Horizon</a> and the Verified column on <a href="{{ url('/vendor_docs') }}" class="text-indigo-600 underline">vendor docs</a>.</li>
            </ol>
        </flux:card>

        <flux:card class="space-y-3">
            <flux:heading size="lg">Status</flux:heading>
            <flux:text>
                @if($session)
                    A browser session arrived {{ $session['age_minutes'] }} min ago and is still usable (up to {{ \App\Support\Ewccv\BridgedSession::MAX_AGE_MINUTES }} min).
                @else
                    No usable browser session on the server right now.
                @endif
            </flux:text>
            @if($pending->isEmpty())
                <flux:text>Every active workers comp policy is tracked by EWCCV.</flux:text>
            @else
                <flux:text>Waiting for a session: {{ $pending->count() }} vendor(s).</flux:text>
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>Vendor</flux:table.column>
                        <flux:table.column>Policy</flux:table.column>
                        <flux:table.column>Last state</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach($pending as $row)
                            <flux:table.row>
                                <flux:table.cell>{{ $row['vendor'] }}</flux:table.cell>
                                <flux:table.cell class="font-mono text-xs">{{ $row['policy'] }}</flux:table.cell>
                                <flux:table.cell>{{ $row['state'] }}</flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            @endif
        </flux:card>
    </div>
</x-layouts.app>
