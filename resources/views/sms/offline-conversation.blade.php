{{--
    Offline conversation fragment — served by SmsOfflineController::thread(),
    cached by resources/js/sms-offline.js, and injected into the
    #sms-offline-pane overlay on /messages when the network is unavailable.

    Rules for this file:
    - NO $wire / wire:* references: it renders outside any Livewire component.
    - Only local Alpine. The x-data below provides inert stubs for the
      selection-mode scope the shared bubble partial binds against, so the
      exact same markup evaluates cleanly offline.
    - Bubble markup itself lives ONLY in livewire/sms/partials/message-bubbles
      (rendered here with $interactive = false).
--}}
<div
    class="flex-1 min-h-0 flex flex-col h-full"
    x-data="{ selectionMode: false, selected: [], toggle(id) {}, has(id) { return false } }"
>
    {{-- Header (same title the live conversation shows, links degraded to text) --}}
    <div class="sms-mobile-header-offset border-b border-zinc-200 dark:border-zinc-700 py-2">
        <div class="flex items-center gap-1.5">
            {{-- Mobile back: flip panels via the shared store; never a server call. --}}
            <flux:button
                type="button"
                variant="subtle"
                size="sm"
                square
                icon="arrow-left"
                class="lg:hidden shrink-0"
                x-on:click="
                    $store.sms.threadId = null;
                    window.dispatchEvent(new CustomEvent('thread-selected', { detail: { threadId: null } }));
                    if (window.HiveSmsOffline) window.HiveSmsOffline.closeThread();
                "
                aria-label="Back to conversations"
            ></flux:button>
            <flux:heading size="lg" class="mb-0 truncate flex-1">{{ $headerTitle }}</flux:heading>
        </div>
    </div>

    {{-- Messages (exact live scroll-container class string) --}}
    <div class="relative flex-1 min-h-0">
        <div class="sms-fade-overlay top"></div>
        <div class="sms-messages h-full overflow-y-auto flex flex-col-reverse gap-3 px-2 pt-6 pb-6">
            @include('livewire.sms.partials.message-bubbles')
        </div>
        <div class="sms-fade-overlay bottom"></div>
    </div>

    {{-- Composer strip: sending is impossible offline, say so plainly. --}}
    <div class="shrink-0 px-1 pb-1">
        <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800/50 px-4 py-3 text-base lg:text-sm text-zinc-500 dark:text-zinc-400 text-center">
            Offline — sending unavailable
        </div>
    </div>
</div>
