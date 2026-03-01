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
            setTimeout(() => this.showConversationSkeleton = false, 450);
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
                }
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

            window.addEventListener('sms-thread-loading', () => showSkeletonBriefly());
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
    <div class="w-full lg:w-80 shrink-0 min-w-0 max-w-md mx-auto lg:mx-0 lg:max-w-none {{ $threadId && $activeTab === 'messages' ? 'hidden lg:block' : '' }}">
        <x-island-card>
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

            <div class="{{ $activeTab !== 'messages' ? 'hidden' : '' }}">
                <div class="mb-2">
                    <flux:input wire:model.live.debounce.500ms="search" icon="magnifying-glass" placeholder="Search messages..." size="sm" />
                </div>

                <div id="sms-threads-live" class="lg:max-h-[calc(100vh-13rem)] lg:min-h-[calc(100vh-13rem)] lg:overflow-y-auto">
                    <livewire:sms.sms-thread-list :search="$search" :selected-thread-id="$threadId" :is-client-user="$isClientUser" lazy />
                </div>
                <script>
                (function() {
                    try {
                        var ts = parseInt(localStorage.getItem('hive-sms-cached-at') || '0');
                        if (Date.now() - ts > 30 * 86400 * 1000) return;
                        var cached = localStorage.getItem('hive-sms-threads');
                        if (cached && cached.length > 200) {
                            var el = document.getElementById('sms-threads-live');
                            if (el) el.innerHTML = cached;
                        }
                    } catch(e) {}
                })();
                </script>
            </div>

            <div class="{{ $activeTab !== 'calls' ? 'hidden' : '' }}">
                <div id="sms-calls-live" class="lg:max-h-[calc(100vh-13rem)] lg:min-h-[calc(100vh-13rem)] lg:overflow-y-auto">
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
                            if (el) el.innerHTML = cached;
                        }
                    } catch(e) {}
                })();
                </script>
            </div>
        </x-island-card>
    </div>

    {{-- Conversation - Hidden on mobile when no thread selected, fully hidden on calls tab --}}
    <div id="sms-convo-wrap" class="flex-1 min-w-0 flex flex-col min-h-0 max-w-md mx-auto lg:mx-0 lg:max-w-none {{ $activeTab === 'calls' ? 'hidden' : (!$threadId ? 'hidden lg:block' : '') }}">
        <div x-show="showConversationSkeleton" class="flex-1 min-h-0">
            @include('livewire.sms.conversation_placeholder')
        </div>

        <div
            wire:loading.flex
            wire:target="threadId,autoSelectLatestDesktopThread,autoSelectSingleThreadIfOnlyOne"
            class="flex-1 min-h-0"
            x-show="!showConversationSkeleton"
        >
            @include('livewire.sms.conversation_placeholder')
        </div>

        <div
            wire:loading.remove
            wire:target="threadId,autoSelectLatestDesktopThread,autoSelectSingleThreadIfOnlyOne"
            class="flex-1 min-h-0 flex flex-col"
            x-show="!showConversationSkeleton"
        >
            <livewire:sms.sms-conversation :thread-id="$threadId" :is-client-user="$isClientUser" :key="'conv-' . $threadId . '-' . ($isClientUser ? 'c' : 'a')" lazy />
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
