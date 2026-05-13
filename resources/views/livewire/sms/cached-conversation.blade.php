{{-- Read-only cached conversation viewer used when offline.
     Hydrates entirely from $store.sms.smsCache (threads + messages map). --}}
<div
    x-data="{
        get currentThread() {
            const id = $store.sms.threadId;
            if (!id || !$store.sms.smsCache) return null;
            return ($store.sms.smsCache.threads || []).find(t => t.id === id) || null;
        },
        get cachedMessages() {
            const id = $store.sms.threadId;
            if (!id) return [];
            return $store.sms.cachedMessagesForThread(id) || [];
        },
        threadTitle(t) {
            if (!t) return '';
            return t.client?.name || t.subject_vendor?.name || t.project?.address || (t.participants || []).join(', ') || 'Conversation';
        },
        fmt(iso) {
            if (!iso) return '';
            try { return new Date(iso).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }); }
            catch (e) { return ''; }
        },
    }"
    class="flex-1 min-h-0 flex flex-col"
>
    <template x-if="currentThread">
        <div class="flex-1 min-h-0 flex flex-col">
            {{-- Header --}}
            <div class="border-b border-zinc-200 dark:border-zinc-700 px-4 py-2">
                <div class="flex items-center gap-1.5" style="padding-left: 2rem" x-bind:style="window.innerWidth >= 1024 ? 'padding-left: 0' : 'padding-left: 2rem'">
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
                        "
                        aria-label="Back to conversations"
                    ></flux:button>
                    <flux:heading size="lg" class="mb-0 truncate flex-1" x-text="threadTitle(currentThread)"></flux:heading>
                </div>
                <p class="text-xs text-zinc-400 mt-0.5">Offline — showing cached messages</p>
            </div>

            {{-- Messages --}}
            <div class="flex-1 min-h-0 overflow-y-auto flex flex-col-reverse gap-3 px-2 pt-6 pb-6">
                <template x-for="msg in [...cachedMessages].reverse()" :key="'cached-msg-' + msg.id">
                    <div x-bind:class="msg.direction === 'outbound' ? 'flex justify-end' : 'flex justify-start'">
                        <div class="max-w-[85%] lg:max-w-[75%]">
                            <div x-bind:class="msg.direction === 'outbound'
                                    ? 'bg-indigo-500 text-white rounded-2xl rounded-br-sm'
                                    : 'bg-zinc-200 dark:bg-zinc-700 text-zinc-900 dark:text-zinc-100 rounded-2xl rounded-bl-sm'"
                                 class="px-3 py-2 text-sm whitespace-pre-wrap break-words" x-text="msg.text"></div>
                            <p class="text-[10px] text-zinc-400 mt-0.5"
                               x-bind:class="msg.direction === 'outbound' ? 'text-right pr-1' : 'pl-1'"
                               x-text="fmt(msg.created_at || msg.sent_at)"></p>
                        </div>
                    </div>
                </template>
                <template x-if="!cachedMessages.length">
                    <p class="text-center text-sm text-zinc-400 py-8">No cached messages for this conversation</p>
                </template>
            </div>

            {{-- Footer notice (read-only) --}}
            <div class="border-t border-zinc-200 dark:border-zinc-700 px-4 py-3 text-center text-xs text-zinc-400">
                You're offline. Sending is disabled until you reconnect.
            </div>
        </div>
    </template>
    <template x-if="!currentThread">
        <div class="flex flex-1 items-center justify-center">
            <div class="text-center">
                <flux:icon name="chat-bubble-left-right" class="mx-auto h-12 w-12 text-zinc-300 dark:text-zinc-600" />
                <h3 class="mt-3 text-base lg:text-sm font-medium text-zinc-500 dark:text-zinc-400">No conversation selected</h3>
                <p class="mt-1 text-sm lg:text-xs text-zinc-400 dark:text-zinc-500">Select a cached conversation to view messages.</p>
            </div>
        </div>
    </template>
</div>
