<div
    class="flex lg:gap-4 flex-1 min-h-0 lg:p-8 {{ $threadId ? 'px-2 pt-2 pb-3' : 'px-5 pt-4 pb-3' }} lg:!px-8 lg:!pt-8"
    x-data="{
        initialized: false,
        showConversationSkeleton: false,
        originalTitle: document.title,
        flashInterval: null,
        isFlashing: false,
        lastNotifyTime: 0,
        audioCtx: null,
        showSkeletonBriefly() {
            this.showConversationSkeleton = true;
            setTimeout(() => this.showConversationSkeleton = false, 300);
        },
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
        smsOffline: !navigator.onLine,
        smsCacheTimer: null,
        initMessageCache() {
            window.addEventListener('online', () => { this.smsOffline = false; });
            window.addEventListener('offline', () => {
                this.smsOffline = true;
            });

            const scheduleCache = () => {
                clearTimeout(this.smsCacheTimer);
                this.smsCacheTimer = setTimeout(() => {
                    if (!this.smsOffline) this.saveSmsCache();
                }, 2000);
            };

            const watchEl = (id) => {
                const el = document.getElementById(id);
                if (el) new MutationObserver(scheduleCache).observe(el, { childList: true, subtree: true, characterData: true });
            };
            watchEl('sms-threads-live');
            watchEl('sms-convo-wrap');
            watchEl('sms-calls-live');

            // Suppress Livewire error dialogs when offline
            if (window.Livewire) {
                Livewire.hook('request', ({ fail }) => {
                    fail(({ status, preventDefault }) => {
                        if (!navigator.onLine || status === 0) preventDefault();
                    });
                });
            }
        },
        saveSmsCache() {
            try {
                const threadsEl = document.getElementById('sms-threads-live');
                if (threadsEl && !threadsEl.querySelector('.animate-pulse') && threadsEl.innerHTML.length > 200) {
                    localStorage.setItem('hive-sms-threads', threadsEl.innerHTML);
                    const scrollable = threadsEl.querySelector('.scrollbar-gutter') || threadsEl;
                    localStorage.setItem('hive-sms-threads-scroll', scrollable.scrollTop);
                }
                const convoWrap = document.getElementById('sms-convo-wrap');
                if (convoWrap) {
                    const live = convoWrap.querySelector('[wire\\:id]');
                    if (live && !live.querySelector('.animate-pulse') && live.innerHTML.length > 200) {
                        localStorage.setItem('hive-sms-convo', live.outerHTML);
                    }
                }
                const callsEl = document.getElementById('sms-calls-live');
                if (callsEl && !callsEl.querySelector('.animate-pulse') && callsEl.innerHTML.length > 200) {
                    localStorage.setItem('hive-sms-calls', callsEl.innerHTML);
                    const scrollable = callsEl.querySelector('.scrollbar-gutter') || callsEl;
                    localStorage.setItem('hive-sms-calls-scroll', scrollable.scrollTop);
                }
                localStorage.setItem('hive-sms-active-tab', $wire.activeTab || 'messages');
                localStorage.setItem('hive-sms-cached-at', Date.now().toString());
            } catch (e) { /* storage full */ }
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

            window.addEventListener('sms-incoming', () => notifyIncoming());
            window.addEventListener('focus', () => stopFlashing());
            document.addEventListener('visibilitychange', () => {
                if (!document.hidden) stopFlashing();
            });

            if (! $wire.threadId) {
                showSkeletonBriefly();
                if (window.matchMedia('(min-width: 1024px)').matches) {
                    $wire.autoSelectLatestDesktopThread();
                } else {
                    $wire.autoSelectSingleThreadIfOnlyOne();
                }
            }
        }
        initMessageCache();
    "
>
    {{-- Offline indicator --}}
    <div x-show="smsOffline" x-cloak class="w-full bg-amber-50 dark:bg-amber-900/50 text-amber-700 dark:text-amber-300 text-xs text-center py-1.5 px-3 rounded-lg mb-2 lg:col-span-full">
        <span class="font-medium">Offline</span> — showing cached messages
    </div>

    {{-- Thread List - Hidden on mobile when thread is selected --}}
    <div class="w-full lg:w-80 shrink-0 min-w-0 min-h-0 max-w-md mx-auto lg:mx-0 lg:max-w-none lg:flex lg:flex-col {{ $threadId && $activeTab === 'messages' ? 'hidden lg:flex' : '' }}">
        <x-island-card class="flex flex-col h-full min-h-0 overflow-hidden">
            {{-- Tabs --}}
            @if (! $isClientUser)
                <div class="flex items-center justify-between">
                    <flux:tabs wire:model.live="activeTab" variant="segmented" size="sm">
                        <flux:tab name="messages">Messages</flux:tab>
                        <flux:tab name="calls">Calls</flux:tab>
                    </flux:tabs>

                    @if ($activeTab === 'messages')
                        <flux:button size="sm" wire:click="$dispatchTo('sms.sms-new-thread', 'openNewThread')" icon="plus">
                            New
                        </flux:button>
                    @else
                        <flux:button size="sm" wire:click="$dispatchTo('sms.call-list', 'openNewCall')" icon="phone">
                            Call
                        </flux:button>
                    @endif
                </div>
            @else
                <div class="flex items-center justify-between mb-3">
                    <flux:heading size="lg">Conversations</flux:heading>
                </div>
            @endif

            <div class="{{ $activeTab === 'messages' ? 'flex flex-col flex-1 min-h-0' : 'hidden' }}">
                <div class="mb-2">
                    <flux:input wire:model.live.debounce.500ms="search" icon="magnifying-glass" placeholder="Search messages..." size="sm" />
                </div>

                <div class="flex-1 min-h-0 relative">
                    {{-- Cached overlay: shown instantly, auto-hides when the real Livewire component renders --}}
                    <div id="sms-threads-cache" class="absolute inset-0 z-10 bg-white dark:bg-zinc-900" style="display:none"></div>

                    <div id="sms-threads-live" class="h-full">
                        <livewire:sms.sms-thread-list :search="$search" :selected-thread-id="$threadId" :is-client-user="$isClientUser" lazy />
                    </div>
                </div>
                <script>
                (function() {
                    try {
                        var ts = parseInt(localStorage.getItem('hive-sms-cached-at') || '0');
                        if (Date.now() - ts > 30 * 86400 * 1000) return;
                        var cached = localStorage.getItem('hive-sms-threads');
                        if (cached && cached.length > 200) {
                            var overlay = document.getElementById('sms-threads-cache');
                            if (overlay) {
                                overlay.innerHTML = cached;
                                overlay.style.display = '';
                                var scroll = parseInt(localStorage.getItem('hive-sms-threads-scroll') || '0');
                                if (scroll > 0) {
                                    var scrollable = overlay.querySelector('.scrollbar-gutter') || overlay;
                                    scrollable.scrollTop = scroll;
                                }
                            }
                            // Hide overlay once real component renders
                            var liveEl = document.getElementById('sms-threads-live');
                            if (liveEl) {
                                var obs = new MutationObserver(function() {
                                    if (!liveEl.querySelector('.animate-pulse')) {
                                        var o = document.getElementById('sms-threads-cache');
                                        if (o) o.style.display = 'none';
                                        obs.disconnect();
                                    }
                                });
                                obs.observe(liveEl, { childList: true, subtree: true });
                            }
                        }
                    } catch(e) {}
                })();
                </script>
            </div>

            <div class="{{ $activeTab === 'calls' ? 'flex flex-col flex-1 min-h-0' : 'hidden' }}">
                <div id="sms-calls-live" class="flex-1 min-h-0 h-full">
                    <livewire:sms.call-list lazy />
                </div>
                <script>
                (function() {
                    try {
                        var ts = parseInt(localStorage.getItem('hive-sms-cached-at') || '0');
                        if (Date.now() - ts > 30 * 86400 * 1000) return;
                        var cached = localStorage.getItem('hive-sms-calls');
                        if (cached && cached.length > 200) {
                            var el = document.getElementById('sms-calls-live');
                            if (el) {
                                el.innerHTML = cached;
                                var scroll = parseInt(localStorage.getItem('hive-sms-calls-scroll') || '0');
                                if (scroll > 0) {
                                    var scrollable = el.querySelector('.scrollbar-gutter') || el;
                                    scrollable.scrollTop = scroll;
                                }
                            }
                        }
                    } catch(e) {}
                })();
                </script>
            </div>
        </x-island-card>
    </div>

    {{-- Conversation - Hidden on mobile when no thread selected, fully hidden on calls tab --}}
    <div id="sms-convo-wrap" class="flex-1 min-w-0 flex flex-col min-h-0 max-w-md mx-auto lg:mx-0 lg:max-w-none {{ $activeTab === 'calls' ? 'hidden' : (!$threadId ? 'hidden lg:block' : '') }}">
        {{-- Initial load skeleton (only while auto-selecting first thread) --}}
        <div x-show="showConversationSkeleton" x-cloak class="flex-1 min-h-0">
            @include('livewire.sms.conversation_placeholder')
        </div>

        <div
            class="flex-1 min-h-0 flex flex-col"
            x-show="!showConversationSkeleton"
        >
            <livewire:sms.sms-conversation :thread-id="$threadId" :is-client-user="$isClientUser" />
        </div>
    </div>
    <script>
    (function() {
        try {
            var ts = parseInt(localStorage.getItem('hive-sms-cached-at') || '0');
            if (Date.now() - ts > 30 * 86400 * 1000) return;
            var cached = localStorage.getItem('hive-sms-convo');
            if (cached && cached.length > 200) {
                var wrap = document.getElementById('sms-convo-wrap');
                if (wrap) wrap.innerHTML = cached;
            }
        } catch(e) {}
    })();
    </script>

    @if (! $isClientUser)
        {{-- New Thread Modal --}}
        <livewire:sms.sms-new-thread />

        {{-- Send Schedule Modal --}}
        <livewire:sms.send-schedule-modal />
    @endif
</div>
