<div
    class="flex lg:gap-4 flex-1 min-h-0 lg:p-8 {{ $threadId ? 'px-2 pt-2 pb-3' : 'px-5 pt-4 pb-3' }} lg:!px-8 lg:!pt-8"
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
        smsOffline: !navigator.onLine,
        initOfflineHandler() {
            window.addEventListener('online', () => { this.smsOffline = false; });
            window.addEventListener('offline', () => { this.smsOffline = true; });

            // Suppress Livewire error dialogs when offline
            if (window.Livewire) {
                Livewire.hook('request', ({ fail }) => {
                    fail(({ status, preventDefault }) => {
                        if (!navigator.onLine || status === 0) preventDefault();
                    });
                });
            }
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
                    <livewire:sms.sms-thread-list :search="$search" :subject-filter="$subjectFilter" :selected-thread-id="$threadId" :is-client-user="$isClientUser" />
                </div>
            </div>

            <div class="{{ $activeTab === 'calls' ? 'flex flex-col flex-1 min-h-0' : 'hidden' }}">
                <div class="flex-1 min-h-0 h-full">
                    <livewire:sms.call-list />
                </div>
            </div>
        </x-island-card>
    </div>

    {{-- Conversation - Hidden on mobile when no thread selected, fully hidden on calls tab --}}
    <div id="sms-convo-wrap"
        class="relative flex-1 min-w-0 flex flex-col min-h-0 max-w-md mx-auto lg:mx-0 lg:max-w-none {{ $activeTab === 'calls' ? 'hidden' : (!$threadId ? 'hidden lg:block' : '') }}">
        <div class="flex-1 min-h-0 flex flex-col">
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
