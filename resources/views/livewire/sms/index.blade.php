<div
    class="flex lg:gap-4 flex-1 min-h-0 lg:p-8 px-5 pt-4 pb-3 lg:!px-8 lg:!pt-8"
    x-bind:class="$store.sms.threadId ? '!px-2 !pt-2 !pb-3 lg:!px-8 lg:!pt-8' : ''"
    x-data="{
        initialized: false,
        originalTitle: document.title,
        flashInterval: null,
        isFlashing: false,
        lastNotifyTime: 0,
        callsMounted: @js($activeTab === 'calls'),
        callsLoading: false,
        threadFilter: $wire.entangle('subjectFilter').live,
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
        switchTab(name) {
            // Strip the off-tab query param from the URL immediately so it
            // never lingers while we wait for Livewire to round-trip.
            const url = new URL(window.location.href);
            if (name === 'calls') {
                url.searchParams.delete('threadId');
                this.callsMounted = true;
                this.callsLoading = true;
            } else {
                url.searchParams.delete('callId');
            }
            if (name === 'messages') {
                url.searchParams.delete('activeTab');
            } else {
                url.searchParams.set('activeTab', name);
            }
            window.history.replaceState({}, '', url);
            Alpine.store('sms').setTab(name);
            $wire.$set('activeTab', name);
        },
    }"
    x-on:click.window.once="ensureAudioContext()"
    x-on:keydown.window.once="ensureAudioContext()"
    x-on:touchstart.window.once="ensureAudioContext()"
    x-on:call-list-rendered.window="callsLoading = false"
    x-init="
        if (!initialized) {
            initialized = true;
            originalTitle = document.title;
            requestNotificationPermission();

            // Strip query params that don't belong to the current tab so the
            // URL stays clean on initial load and after refresh.
            (() => {
                const url = new URL(window.location.href);
                const tab = @js($activeTab);
                let changed = false;
                if (tab === 'calls' && url.searchParams.has('threadId')) {
                    url.searchParams.delete('threadId'); changed = true;
                }
                if (tab !== 'calls' && url.searchParams.has('callId')) {
                    url.searchParams.delete('callId'); changed = true;
                }
                if (changed) window.history.replaceState({}, '', url);
            })();

            // Seed the global SMS store from server values (only on first mount).
            const store = Alpine.store('sms');
            store.threadId = @js($threadId);
            store.setTab(@js($activeTab));
            // Don't set callsLoading on initial render — the CallList is
            // rendered synchronously, so there is no async load to wait for.

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
    "
>

    {{-- Thread List - Hidden on mobile when thread is selected --}}
    <div
        x-on:sms-set-tab.window="switchTab($event.detail)"
        x-on:thread-selected.window="$store.sms.threadId = $event.detail.threadId"
        x-on:sms-subject-filter-changed.window="
            if (window.matchMedia('(min-width: 1024px)').matches) {
                $wire.autoSelectLatestForFilter();
            }
        "
        class="w-full lg:w-80 shrink-0 min-w-0 min-h-0 max-w-md mx-auto lg:mx-0 lg:max-w-none lg:flex lg:flex-col {{ $threadId && $activeTab === 'messages' ? 'hidden lg:flex' : '' }}"
        x-bind:class="(($store.sms.threadId && $store.sms.tab === 'messages') || ($store.sms.callId && $store.sms.tab === 'calls')) ? 'hidden lg:flex' : ''"
    >
        <x-island-card class="relative flex flex-col h-full min-h-0 overflow-hidden">
            {{-- Tabs --}}
            <div class="flex items-center justify-between">
                {{-- Alpine-driven tab switching: instant, no server roundtrip --}}
                <div class="inline-flex rounded-lg bg-zinc-100 dark:bg-zinc-800 p-0.5">
                    <button type="button"
                        x-on:click="switchTab('messages')"
                        x-bind:class="$store.sms.tab === 'messages' ? 'bg-white dark:bg-zinc-700 shadow text-zinc-900 dark:text-zinc-100 hover:text-zinc-900 dark:hover:text-zinc-100' : 'text-zinc-500 dark:text-zinc-400 hover:text-zinc-500 dark:hover:text-zinc-400'"
                        class="px-3 py-1 text-sm font-medium rounded-md">
                        Messages
                    </button>
                    <button type="button"
                        x-on:click="switchTab('calls')"
                        x-bind:class="$store.sms.tab === 'calls' ? 'bg-white dark:bg-zinc-700 shadow text-zinc-900 dark:text-zinc-100 hover:text-zinc-900 dark:hover:text-zinc-100' : 'text-zinc-500 dark:text-zinc-400 hover:text-zinc-500 dark:hover:text-zinc-400'"
                        class="px-3 py-1 text-sm font-medium rounded-md">
                        Calls
                    </button>
                </div>

                @if (! $isClientUser)
                    <flux:button x-show="$store.sms.tab === 'messages'" size="sm" wire:click="$dispatchTo('sms.sms-new-thread', 'openNewThread')" icon="plus">
                        New
                    </flux:button>
                    <flux:button x-show="$store.sms.tab === 'calls'" x-cloak size="sm" wire:click="$dispatchTo('sms.call-list', 'openNewCall')" icon="phone">
                        Call
                    </flux:button>
                @endif
            </div>

            <div x-show="$store.sms.tab === 'messages'" x-cloak class="flex flex-col flex-1 min-h-0">
                @if (! $isClientUser)
                    <div class="mb-2">
                        <div class="inline-flex w-full rounded-lg bg-zinc-100 dark:bg-zinc-800 p-0.5">
                            <button
                                type="button"
                                class="flex-1 px-2.5 py-1 text-sm font-medium rounded-md"
                                x-bind:class="threadFilter === 'all' ? 'bg-white dark:bg-zinc-700 shadow text-zinc-900 dark:text-zinc-100 hover:text-zinc-900 dark:hover:text-zinc-100' : 'text-zinc-500 dark:text-zinc-400 hover:text-zinc-500 dark:hover:text-zinc-400'"
                                x-on:click="threadFilter = 'all'"
                            >
                                All
                            </button>
                            <button
                                type="button"
                                class="flex-1 px-2.5 py-1 text-sm font-medium rounded-md"
                                x-bind:class="threadFilter === 'client' ? 'bg-white dark:bg-zinc-700 shadow text-zinc-900 dark:text-zinc-100 hover:text-zinc-900 dark:hover:text-zinc-100' : 'text-zinc-500 dark:text-zinc-400 hover:text-zinc-500 dark:hover:text-zinc-400'"
                                x-on:click="threadFilter = 'client'"
                            >
                                Clients
                            </button>
                            <button
                                type="button"
                                class="flex-1 px-2.5 py-1 text-sm font-medium rounded-md"
                                x-bind:class="threadFilter === 'vendor' ? 'bg-white dark:bg-zinc-700 shadow text-zinc-900 dark:text-zinc-100 hover:text-zinc-900 dark:hover:text-zinc-100' : 'text-zinc-500 dark:text-zinc-400 hover:text-zinc-500 dark:hover:text-zinc-400'"
                                x-on:click="threadFilter = 'vendor'"
                            >
                                Vendors
                            </button>
                        </div>
                    </div>
                @endif

                <div class="mb-2">
                    <flux:input wire:model.live.debounce.500ms="search" icon="magnifying-glass" placeholder="Search messages..." size="sm" />
                </div>

                <div class="relative flex-1 min-h-0">
                    <div
                        wire:loading.delay
                        wire:target="subjectFilter,search"
                        class="absolute inset-0 z-20 pointer-events-none"
                    >
                        @include('livewire.sms.sidebar-card-skeleton')
                    </div>

                    <div class="h-full">
                        @if ($isClientUser)
                            <livewire:sms.sms-thread-list :search="$search" subject-filter="all" :selected-thread-id="$threadId" :is-client-user="$isClientUser" wire:key="sms-thread-list-client-user" />
                        @else
                            <div class="h-full">
                                <livewire:sms.sms-thread-list
                                    :search="$search"
                                    :subject-filter="$subjectFilter"
                                    :selected-thread-id="$threadId"
                                    :is-client-user="$isClientUser"
                                    :key="'sms-thread-list-' . $subjectFilter"
                                />
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            <template x-if="callsMounted">
                <div x-show="$store.sms.tab === 'calls'" x-cloak class="flex flex-col flex-1 min-h-0">
                    <div class="relative flex-1 min-h-0 h-full">
                        <div
                            x-show="callsLoading"
                            x-cloak
                            class="absolute inset-0 z-20 pointer-events-none"
                        >
                            @include('livewire.sms.call-list-skeleton')
                        </div>
                        <div
                            wire:loading
                            wire:target="activeTab"
                            class="absolute inset-0 z-20 pointer-events-none"
                        >
                            @include('livewire.sms.call-list-skeleton')
                        </div>

                        <livewire:sms.call-list />
                    </div>
                </div>
            </template>
        </x-island-card>
    </div>

    {{-- Conversation - Hidden on mobile when no thread selected, fully hidden on calls tab --}}
    <div id="sms-convo-wrap"
        x-data="{ initialThreadId: @js($threadId) }"
        x-on:thread-selected.window="$store.sms.threadId = $event.detail.threadId; initialThreadId = $event.detail.threadId"
        x-show="$store.sms.tab !== 'calls' && (initialThreadId || $store.sms.threadId || window.matchMedia('(min-width: 1024px)').matches)"
        x-cloak
        class="relative flex-1 min-w-0 flex flex-col min-h-0 max-w-md mx-auto lg:mx-0 lg:max-w-none">
        <div class="flex-1 min-h-0 flex flex-col">
            <livewire:sms.sms-conversation :thread-id="$threadId" :is-client-user="$isClientUser" />
        </div>
    </div>

    {{-- Call detail pane (mirrors conversation pane on the calls tab) --}}
    <div id="sms-call-detail-wrap"
        x-show="$store.sms.tab === 'calls' && ($store.sms.callId || window.matchMedia('(min-width: 1024px)').matches)"
        x-cloak
        class="relative flex-1 min-w-0 flex flex-col min-h-0 max-w-md mx-auto lg:mx-0 lg:max-w-none">
        <div class="flex-1 min-h-0 flex flex-col">
            <livewire:sms.call-detail />
        </div>
    </div>

    @if (! $isClientUser)
        {{-- New Thread Modal --}}
        <livewire:sms.sms-new-thread />

        {{-- Send Schedule Modal --}}
        <livewire:sms.send-schedule-modal />
    @endif
</div>
