<div class="flex gap-4 flex-1 min-h-0 p-6 lg:p-8">
    {{-- Thread List - Hidden on mobile when thread is selected --}}
    <div class="w-full lg:w-80 shrink-0 {{ $threadId ? 'hidden lg:block' : '' }}">
        <x-island-card heading="Conversations">
            <x-slot:actions>
                <flux:button size="sm" wire:click="$dispatchTo('sms.sms-new-thread', 'openNewThread')" icon="plus">
                    New
                </flux:button>
            </x-slot:actions>

            <div class="mb-2">
                <flux:input wire:model.live.debounce.500ms="search" icon="magnifying-glass" placeholder="Search messages..." size="sm" />
            </div>

            <livewire:sms.sms-thread-list :search="$search" :selected-thread-id="$threadId" />
        </x-island-card>
    </div>

    {{-- Conversation - Hidden on mobile when no thread selected --}}
    <div class="flex-1 min-w-0 flex flex-col min-h-0 {{ !$threadId ? 'hidden lg:block' : '' }}">
        <livewire:sms.sms-conversation :thread-id="$threadId" :key="$threadId" />
    </div>

    {{-- New Thread Modal --}}
    <livewire:sms.sms-new-thread />
</div>
