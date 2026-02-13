<div
    class="flex gap-4 flex-1 min-h-0 p-6 lg:p-8"
    x-data="{
        initialized: false,
        showConversationSkeleton: false,
        showSkeletonBriefly() {
            this.showConversationSkeleton = true;
            setTimeout(() => this.showConversationSkeleton = false, 450);
        }
    }"
    x-init="
        if (!initialized) {
            initialized = true;
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
    <div class="w-full lg:w-80 shrink-0 {{ $threadId ? 'hidden lg:block' : '' }}">
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
    <div class="flex-1 min-w-0 flex flex-col min-h-0 {{ !$threadId ? 'hidden lg:block' : '' }}">
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
