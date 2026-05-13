<div
    class="flex lg:gap-4 flex-1 min-h-0 lg:p-8 px-5 pt-4 pb-3 lg:!px-8 lg:!pt-8"
    x-bind:class="$store.sms.threadId ? '!px-2 !pt-2 !pb-3 lg:!px-8 lg:!pt-8' : ''"
    x-data="{
        initialized: false,
        originalTitle: document.title,
        flashInterval: null,
        isFlashing: false,
        lastNotifyTime: 0,
        audioCtx: null,
        ensureAudioContext() {
            if (!this.audioCtx) {
                try {
                    this.audioCtx = new (window.AudioContext || window.webkitAudioContext)();
                } catch (e) {}
            }
            if (this.audioCtx && this.audioCtx.state === 'suspended') {
                this.audioCtx.resume().catch(() => {});
            }
        },
        notifyIncoming() {
            const now = Date.now();
            if (now - this.lastNotifyTime < 3000) return;
            this.lastNotifyTime = now;

            // Immediately change the tab title
            document.title = '\uD83D\uDCAC New Message!';

            // Start flashing between original and alert
            if (!this.isFlashing) {
                this.isFlashing = true;
                let toggle = true;
                this.flashInterval = setInterval(() => {
                    document.title = toggle ? this.originalTitle : '\uD83D\uDCAC New Message!';
                    toggle = !toggle;
                }, 1000);
            }

            // Play notification beep
            this.ensureAudioContext();
            if (this.audioCtx && this.audioCtx.state === 'running') {
                try {
                    const osc = this.audioCtx.createOscillator();
                    const gain = this.audioCtx.createGain();
                    osc.connect(gain);
                    gain.connect(this.audioCtx.destination);
                    osc.frequency.value = 880;
                    osc.type = 'sine';
                    gain.gain.value = 0.15;
                    osc.start();
                    gain.gain.exponentialRampToValueAtTime(0.001, this.audioCtx.currentTime + 0.3);
                    osc.stop(this.audioCtx.currentTime + 0.3);
                } catch (e) {}
            }
        },
        stopFlashing() {
            if (this.isFlashing) {
                clearInterval(this.flashInterval);
                document.title = this.originalTitle;
                this.isFlashing = false;
            }
        },
        requestNotificationPermission() {
            if ('Notification' in window && Notification.permission === 'default') {
                Notification.requestPermission();
            }
        },
        initOfflineHandler() {
            const store = Alpine.store('sms');

            // Suppress Livewire error dialogs when offline
            if (window.Livewire) {
                Livewire.hook('request', ({ fail }) => {
                    fail(({ status, preventDefault }) => {
                        if (!navigator.onLine || status === 0) preventDefault();
                    });
                });
            }

            // Prime cache on mount + refresh every 30s while page is visible.
            store.loadCache();
            setInterval(() => {
                if (!document.hidden && navigator.onLine) store.loadCache();
            }, 30000);
        },
    }"
    x-on:click.window.once="ensureAudioContext()"
    x-on:keydown.window.once="ensureAudioContext()"
    x-on:touchstart.window.once="ensureAudioContext()"
    x-init="
        if (!initialized) {
            initialized = true;
            originalTitle = document.title;
            requestNotificationPermission();

            // Seed the global SMS store from server values (only on first mount).
            const store = Alpine.store('sms');
            store.threadId = @js($threadId);
            // Server-side preference wins only if no local preference exists.
            const lsTab = (() => { try { return localStorage.getItem('hive-sms-tab'); } catch (e) { return null; } })();
            if (! lsTab) store.setTab(@js($activeTab));

            window.addEventListener('sms-incoming', () => notifyIncoming());
            window.addEventListener('focus', () => stopFlashing());
            document.addEventListener('visibilitychange', () => {
                if (!document.hidden) stopFlashing();
            });

            // Handle notification clicks from the service worker
            if (navigator.serviceWorker) {
                navigator.serviceWorker.addEventListener('message', (event) => {
                    if (event.data?.type === 'navigate-thread' && event.data?.threadId) {
                        $wire.selectThread(event.data.threadId);
                        stopFlashing();
                    } else if (event.data?.type === 'navigate-url' && event.data?.url) {
                        window.location.href = event.data.url;
                    }
                });
            }

            if (! $wire.threadId) {
                if (window.matchMedia('(min-width: 1024px)').matches) {
                    $wire.autoSelectLatestDesktopThread();
                } else {
                    $wire.autoSelectSingleThreadIfOnlyOne();
                }
            }
        }
        initOfflineHandler();
    "
>
    {{-- Clear stale hive-sms localStorage on deploy --}}
    <script>
    (function() {
        try {
            var v = '{{ filemtime(public_path('sw.js')) }}';
            if (localStorage.getItem('hive-deploy-v') !== v) {
                Object.keys(localStorage).forEach(function(k) {
                    if (k.startsWith('hive-sms-')) localStorage.removeItem(k);
                });
                localStorage.setItem('hive-deploy-v', v);
                location.reload();
                return;
            }
        } catch(e) {}
    })();
    </script>

    {{-- Offline indicator: pinned to the top on mobile (parent is flex-row, so a normal
         child would render as a left-side column). On desktop it becomes inline. --}}
    <div
        x-show="$store.sms.smsOffline"
        x-cloak
        class="fixed inset-x-0 top-0 z-50 px-2 pt-2 lg:static lg:px-0 lg:pt-0 lg:mb-2 lg:col-span-full"
    >
        <flux:callout icon="signal-slash" variant="warning" class="shadow-lg lg:shadow-none">
            <flux:callout.heading>Offline</flux:callout.heading>
            <flux:callout.text>Showing cached messages.</flux:callout.text>
        </flux:callout>
    </div>

    {{-- Thread List - Hidden on mobile when thread is selected --}}
    <div
        x-on:sms-set-tab.window="$store.sms.setTab($event.detail); $wire.$set('activeTab', $event.detail, false)"
        x-on:thread-selected.window="$store.sms.threadId = $event.detail.threadId"
        x-on:sms-subject-filter-changed.window="
            if (window.matchMedia('(min-width: 1024px)').matches) {
                $wire.autoSelectLatestForFilter();
            }
        "
        class="w-full lg:w-80 shrink-0 min-w-0 min-h-0 max-w-md mx-auto lg:mx-0 lg:max-w-none lg:flex lg:flex-col"
        x-bind:class="($store.sms.threadId && $store.sms.tab === 'messages') ? 'hidden lg:flex' : ''"
    >
        <x-island-card class="flex flex-col h-full min-h-0 overflow-hidden">
            {{-- Tabs --}}
            @if (! $isClientUser)
                <div class="flex items-center justify-between pl-8 lg:pl-0">
                    {{-- Alpine-driven tab switching: instant, no server roundtrip --}}
                    <div class="inline-flex rounded-lg bg-zinc-100 dark:bg-zinc-800 p-0.5">
                        <button type="button"
                            x-on:click="$store.sms.setTab('messages'); $wire.$set('activeTab', 'messages', false)"
                            x-bind:class="$store.sms.tab === 'messages' ? 'bg-white dark:bg-zinc-700 shadow text-zinc-900 dark:text-zinc-100' : 'text-zinc-500 dark:text-zinc-400'"
                            class="px-3 py-1 text-sm font-medium rounded-md transition-colors">
                            Messages
                        </button>
                        <button type="button"
                            x-on:click="$store.sms.setTab('calls'); $wire.$set('activeTab', 'calls', false)"
                            x-bind:class="$store.sms.tab === 'calls' ? 'bg-white dark:bg-zinc-700 shadow text-zinc-900 dark:text-zinc-100' : 'text-zinc-500 dark:text-zinc-400'"
                            class="px-3 py-1 text-sm font-medium rounded-md transition-colors">
                            Calls
                        </button>
                    </div>

                    <flux:button x-show="$store.sms.tab === 'messages'" size="sm" wire:click="$dispatchTo('sms.sms-new-thread', 'openNewThread')" icon="plus">
                        New
                    </flux:button>
                    <flux:button x-show="$store.sms.tab === 'calls'" x-cloak size="sm" wire:click="$dispatchTo('sms.call-list', 'openNewCall')" icon="phone">
                        Call
                    </flux:button>
                </div>
            @else
                <div class="flex items-center justify-between mb-3 pl-8 lg:pl-0">
                    <flux:heading size="lg">Conversations</flux:heading>
                </div>
            @endif

            <div x-show="$store.sms.tab === 'messages'" class="flex flex-col flex-1 min-h-0">
                @if (! $isClientUser)
                    <div class="mb-2">
                        <flux:tabs wire:model.live="subjectFilter" variant="segmented" size="sm">
                            <flux:tab name="all">All</flux:tab>
                            <flux:tab name="client">Clients</flux:tab>
                            <flux:tab name="vendor">Vendors</flux:tab>
                        </flux:tabs>
                    </div>
                @endif

                <div class="mb-2">
                    <flux:input wire:model.live.debounce.500ms="search" icon="magnifying-glass" placeholder="Search messages..." size="sm" />
                </div>

                <div class="flex-1 min-h-0">
                    {{-- Offline cached read-only thread list (rendered when network is down) --}}
                    <div x-show="$store.sms.smsOffline && $store.sms.smsCache" x-cloak class="space-y-1 h-full overflow-y-auto">
                        <template x-for="t in ($store.sms.smsCache?.threads || [])" :key="t.id">
                            <button type="button"
                               x-on:click="
                                   $store.sms.threadId = t.id;
                                   window.dispatchEvent(new CustomEvent('thread-switching', { detail: { threadId: t.id } }));
                                   window.dispatchEvent(new CustomEvent('thread-selected', { detail: { threadId: t.id } }));
                               "
                               x-bind:class="$store.sms.threadId === t.id ? 'bg-zinc-100 dark:bg-zinc-700' : 'hover:bg-zinc-50 dark:hover:bg-zinc-800'"
                               class="block w-full text-left px-3 py-2.5 rounded-lg">
                                <p class="text-base lg:text-sm font-medium truncate text-zinc-900 dark:text-zinc-100"
                                   x-text="t.client?.name || t.subject_vendor?.name || t.project?.address || (t.participants || []).join(', ') || 'Group Message'"></p>
                                <p x-show="t.latest_message" class="text-sm lg:text-xs text-zinc-400 truncate mt-0.5">
                                    <span x-show="t.latest_message?.sent_by" x-text="(t.latest_message?.sent_by || '') + ': '"></span><span x-text="(t.latest_message?.text || '').slice(0, 60)"></span>
                                </p>
                                <p class="text-xs text-zinc-400 mt-0.5" x-text="$store.sms.formatCachedTime(t.last_activity_at)"></p>
                            </button>
                        </template>
                        <p x-show="!($store.sms.smsCache?.threads || []).length" class="text-center text-sm text-zinc-400 py-8">No cached conversations</p>
                    </div>

                    {{-- Live Livewire thread list (hidden when offline so cache isn't covered) --}}
                    <div x-show="!$store.sms.smsOffline" class="h-full">
                        <livewire:sms.sms-thread-list :search="$search" :subject-filter="$subjectFilter" :selected-thread-id="$threadId" :is-client-user="$isClientUser" />
                    </div>
                </div>
            </div>

            <div x-show="$store.sms.tab === 'calls'" x-cloak class="flex flex-col flex-1 min-h-0">
                <div class="flex-1 min-h-0 h-full">
                    <livewire:sms.call-list />
                </div>
            </div>
        </x-island-card>
    </div>

    {{-- Conversation - Hidden on mobile when no thread selected, fully hidden on calls tab --}}
    <div id="sms-convo-wrap"
        x-on:thread-selected.window="$store.sms.threadId = $event.detail.threadId"
        x-show="$store.sms.tab !== 'calls' && ($store.sms.threadId || window.matchMedia('(min-width: 1024px)').matches)"
        class="relative flex-1 min-w-0 flex flex-col min-h-0 max-w-md mx-auto lg:mx-0 lg:max-w-none">
        {{-- Offline cached read-only conversation --}}
        <div x-show="$store.sms.smsOffline" x-cloak class="flex-1 min-h-0 flex flex-col">
            @include('livewire.sms.cached-conversation')
        </div>
        {{-- Live Livewire conversation (hidden when offline) --}}
        <div x-show="!$store.sms.smsOffline" class="flex-1 min-h-0 flex flex-col">
            <livewire:sms.sms-conversation :thread-id="$threadId" :is-client-user="$isClientUser" />
        </div>
    </div>

    @if (! $isClientUser)
        {{-- New Thread Modal --}}
        <livewire:sms.sms-new-thread />

        {{-- Send Schedule Modal --}}
        <livewire:sms.send-schedule-modal />
    @endif
</div>
