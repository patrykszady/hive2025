<div class="space-y-1 overflow-y-auto max-h-[calc(100vh-16rem)]">
    @forelse ($this->threads as $thread)
        <button
            wire:key="thread-{{ $thread->id }}"
            wire:click="select({{ $thread->id }})"
            x-on:click="window.dispatchEvent(new CustomEvent('sms-thread-loading'))"
            class="w-full text-left px-3 py-2.5 rounded-lg transition-colors {{ $selectedThreadId === $thread->id ? 'bg-zinc-200 dark:bg-zinc-700' : 'hover:bg-zinc-100 dark:hover:bg-zinc-800' }}"
        >
            <div class="flex items-center justify-between gap-2">
                <div class="min-w-0 flex-1">
                    {{-- Client name, project address, or phone numbers --}}
                    <p class="text-base lg:text-sm font-medium truncate text-zinc-900 dark:text-zinc-100">
                        @if ($isClientUser)
                            GS Construciton
                        @elseif ($thread->client)
                            {{ $thread->client->name }}
                        @elseif ($thread->project)
                            {{ $thread->project->address }}
                        @else
                            {{ collect($thread->participants)->map(fn ($p) => $this->resolvePhoneDisplay($p))->implode(', ') }}
                        @endif
                    </p>

                    {{-- Latest message preview --}}
                    @if ($thread->latestMessage)
                        @php
                            $previewText = preg_replace('/\s*-(PS|GS|GSC)\s*$/', '', trim((string) $thread->latestMessage->text));

                            $previewPrefix = null;
                            if ($thread->latestMessage->isOutbound()) {
                                $previewPrefix = $isClientUser
                                    ? 'GS Construciton:'
                                    : ($thread->latestMessage->sent_by_user_id ? 'You:' : 'GS Crew:');
                            } elseif ($thread->latestMessage->isInbound()) {
                                $sender = $this->resolvePreviewSender($thread->latestMessage->from_number, $thread);
                                $previewPrefix = $sender ? $sender . ':' : null;
                            }
                        @endphp
                        <p class="text-sm lg:text-xs text-zinc-400 dark:text-zinc-500 truncate mt-0.5">
                            @if ($previewPrefix)
                                <span class="text-zinc-500 dark:text-zinc-400">{{ $previewPrefix }}</span>
                            @endif
                            {{ Str::limit($previewText, 50) }}
                        </p>
                    @endif
                </div>

                {{-- Timestamp --}}
                <div class="shrink-0 text-right">
                    <p class="text-xs lg:text-[10px] text-zinc-400 dark:text-zinc-500">
                        {{ $thread->last_activity_at?->diffForHumans(short: true) ?? $thread->created_at->diffForHumans(short: true) }}
                    </p>
                    @if (in_array($thread->id, $this->unreadThreadIds, true))
                        <div class="mt-1 flex justify-end">
                            <span class="inline-block w-2 h-2 bg-indigo-500 rounded-full"></span>
                        </div>
                    @endif
                </div>
            </div>
        </button>
    @empty
        <div class="text-center py-8">
            <flux:icon name="chat-bubble-left-right" class="mx-auto h-8 w-8 text-zinc-300 dark:text-zinc-600" />
            <p class="mt-2 text-sm text-zinc-400 dark:text-zinc-500">No conversations yet</p>
        </div>
    @endforelse

    @if ($this->threads->hasPages())
        <div class="pt-2">
            {{ $this->threads->links() }}
        </div>
    @endif
</div>


