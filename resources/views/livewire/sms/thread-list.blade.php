<div
    x-data="{
        selected: @js($selectedThreadId),
        pulling: false,
        pullY: 0,
        startY: 0,
        refreshing: false,
        onTouchStart(e) {
            if (this.$el.scrollTop === 0) {
                this.startY = e.touches[0].clientY;
                this.pulling = true;
            }
        },
        onTouchMove(e) {
            if (!this.pulling) return;
            const dy = e.touches[0].clientY - this.startY;
            if (dy > 0 && this.$el.scrollTop === 0) {
                this.pullY = Math.min(dy * 0.4, 80);
                if (dy > 10) e.preventDefault();
            } else {
                this.pullY = 0;
            }
        },
        onTouchEnd() {
            if (this.pullY >= 60 && !this.refreshing) {
                this.refreshing = true;
                this.pullY = 50;
                $wire.$refresh().then(() => {
                    Livewire.dispatch('refreshMessages');
                    this.refreshing = false;
                    this.pullY = 0;
                    this.pulling = false;
                });
            } else {
                this.pullY = 0;
                this.pulling = false;
            }
        },
    }"
    x-on:thread-selected.window="selected = $event.detail.threadId"
    x-on:sms-thread-filter-changed.window="$nextTick(() => $el.scrollTop = 0)"
    x-on:touchstart.passive="onTouchStart($event)"
    x-on:touchmove="onTouchMove($event)"
    x-on:touchend="onTouchEnd()"
    class="space-y-1 h-full scrollbar-gutter overflow-y-auto overscroll-contain"
    style="-webkit-overflow-scrolling: touch"
>
    {{-- Pull-to-refresh indicator --}}
    <div
        x-show="pullY > 0"
        x-cloak
        x-bind:style="'height: ' + pullY + 'px'"
        class="flex items-end justify-center overflow-hidden transition-none"
    >
        <div class="pb-2">
            <svg x-show="!refreshing" x-bind:style="'transform: rotate(' + (pullY * 3) + 'deg)'" class="size-5 text-zinc-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M15.312 11.424a5.5 5.5 0 0 1-9.201 2.466l-.312-.311h2.433a.75.75 0 0 0 0-1.5H4.598a.75.75 0 0 0-.75.75v3.634a.75.75 0 0 0 1.5 0v-2.033l.31.31A7 7 0 0 0 17.25 10a.75.75 0 0 0-1.5 0 5.5 5.5 0 0 1-.438 1.424ZM4.688 8.576a5.5 5.5 0 0 1 9.201-2.466l.312.311h-2.433a.75.75 0 0 0 0 1.5h3.634a.75.75 0 0 0 .75-.75V3.537a.75.75 0 0 0-1.5 0v2.033l-.31-.31A7 7 0 0 0 2.75 10a.75.75 0 0 0 1.5 0 5.5 5.5 0 0 1 .438-1.424Z" clip-rule="evenodd" />
            </svg>
            <svg x-show="refreshing" class="size-5 text-indigo-500 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
            </svg>
        </div>
    </div>
    @forelse ($this->threads as $thread)
        <button
            wire:key="thread-{{ $thread->id }}"
            x-on:click="selected = {{ $thread->id }}; Livewire.dispatch('threadSelected', { threadId: {{ $thread->id }} }); $dispatch('thread-switching', { threadId: {{ $thread->id }} })"
            class="w-full text-left px-3 py-2.5 rounded-lg"
            x-bind:class="selected === {{ $thread->id }}
                ? 'bg-zinc-100 dark:bg-zinc-700'
                : 'hover:bg-zinc-50 dark:hover:bg-zinc-800'"
        >
            <div class="flex items-center justify-between gap-2">
                <div class="min-w-0 flex-1">
                    {{-- Client name, project address, or phone numbers --}}
                    <p class="text-base lg:text-sm font-medium truncate text-zinc-900 dark:text-zinc-100 flex items-center gap-1">
                        <span class="truncate">
                        @if ($isClientUser)
                            GS Construciton
                        @elseif ($thread->name)
                            {{ $thread->name }}
                        @elseif ($thread->client && $thread->threadParticipants->count() < $thread->client->users->count())
                            {{ $thread->threadParticipants->pluck('phone_number')->map(fn ($p) => $this->resolvePhoneDisplay($p))->implode(', ') }}
                        @elseif ($thread->client)
                            {{ $thread->client->name }}
                        @elseif ($thread->project)
                            {{ $thread->project->address }}
                        @else
                            {{ $thread->threadParticipants->pluck('phone_number')->map(fn ($p) => $this->resolvePhoneDisplay($p))->implode(', ') }}
                        @endif
                        </span>
                        @if ($thread->scheduled_messages_count > 0)
                            <span class="shrink-0 inline-flex items-center justify-center size-5 rounded bg-amber-100 dark:bg-amber-900/40">
                                <flux:icon name="clock" class="size-3.5 text-amber-600 dark:text-amber-400" />
                            </span>
                        @endif
                    </p>

                    @if ($thread->subject_vendor_id)
                        <p class="mt-0.5 text-xs text-zinc-400 dark:text-zinc-500 truncate">
                            {{ $thread->subjectVendor->business_name }}
                        </p>
                    @endif

                    {{-- Latest message preview --}}
                    @if ($thread->latestMessage)
                        @php
                            $tapback = $thread->latestMessage->parseTapback();
                            $previewText = preg_replace('/\s*-(PS|GS|GSC)\s*$/', '', trim((string) $thread->latestMessage->text));

                            $previewPrefix = null;
                            if ($thread->latestMessage->isOutbound()) {
                                $previewPrefix = $isClientUser
                                    ? 'GS Construciton:'
                                    : ($thread->latestMessage->sent_by_user_id === auth()->id() ? 'You:' : ($thread->latestMessage->sentByUser?->first_name ?? 'GS Crew') . ':');
                            } elseif ($thread->latestMessage->isInbound()) {
                                $sender = $this->resolvePreviewSender($thread->latestMessage->from_number, $thread);
                                $previewPrefix = $sender ? $sender . ':' : null;
                            }

                            // For tapback reactions, show clean emoji only
                            if ($tapback) {
                                $previewText = $tapback['emoji'] ?? $tapback['reaction'];
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

                {{-- Timestamp & line indicator --}}
                <div class="shrink-0 text-right">
                    <div class="flex items-center justify-end gap-1">
                        <p class="text-xs lg:text-[10px] text-zinc-400 dark:text-zinc-500">
                            @php
                                $activityAt = $thread->last_activity_at ?? $thread->created_at;
                            @endphp
                            {{ $activityAt->year !== now()->year ? $activityAt->format('M j, Y') : $activityAt->diffForHumans(short: true) }}
                        </p>
                    </div>
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

    @if ($this->threads->count() >= $limit)
        <div wire:intersect="loadMore" class="text-center py-2">
            <span wire:loading wire:target="loadMore" class="text-xs text-zinc-400">Loading...</span>
        </div>
    @endif
</div>

@script
<script>
    const scrollEl = $wire.$el;

    Livewire.hook('commit', ({ component, succeed }) => {
        if (component.id !== $wire.$id) return;
        const top = scrollEl.scrollTop;
        succeed(() => {
            scrollEl.scrollTop = top;
        });
    });
</script>
@endscript


