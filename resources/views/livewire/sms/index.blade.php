<div
    class="flex lg:gap-4 flex-1 min-h-0 py-3 lg:p-8 {{ $threadId ? 'px-0' : 'px-2' }} lg:!px-8"
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
        }
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
    "
>
    {{-- Thread List - Hidden on mobile when thread is selected --}}
    <div class="w-full lg:w-80 shrink-0 min-w-0 max-w-lg mx-auto lg:mx-0 lg:max-w-none {{ $threadId ? 'hidden lg:block' : '' }}">
        <x-island-card heading="Conversations">
            @if (! $isClientUser)
                <x-slot:actions>
                    <flux:button size="sm" wire:click="$dispatchTo('sms.sms-new-thread', 'openNewThread')" icon="plus">
                        New
                    </flux:button>
                </x-slot:actions>
            @endif

            <div class="mb-2">
                <flux:input wire:model.live.debounce.500ms="search" icon="magnifying-glass" placeholder="Search messages..." size="sm" />
            </div>

            <livewire:sms.sms-thread-list :search="$search" :selected-thread-id="$threadId" :is-client-user="$isClientUser" lazy />
        </x-island-card>
    </div>

    {{-- Conversation - Hidden on mobile when no thread selected --}}
    <div class="flex-1 min-w-0 flex flex-col min-h-0 max-w-full {{ !$threadId ? 'hidden lg:block' : '' }}">
        {{-- Mobile back button - fixed next to sidebar toggle --}}
        @if ($threadId)
            <div class="lg:hidden fixed top-0 left-12 z-[60] pointer-events-auto py-1.5">
                <flux:button
                    type="button"
                    variant="subtle"
                    size="sm"
                    square
                    icon="arrow-left"
                    class="bg-white/60 dark:bg-zinc-900/50 backdrop-blur-[2px] border border-zinc-200/60 dark:border-zinc-700/60 shadow-sm rounded-lg"
                    wire:click="$set('threadId', null)"
                    aria-label="Back to conversations"
                />
            </div>
        @endif
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

    @if (! $isClientUser)
        {{-- New Thread Modal --}}
        <livewire:sms.sms-new-thread />
    @endif
</div>
