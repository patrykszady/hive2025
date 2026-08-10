<div
    x-data="{
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
                }).finally(() => {
                    /* finally: never leave the indicator stuck if the request fails */
                    this.refreshing = false;
                    this.pullY = 0;
                    this.pulling = false;
                });
            } else {
                this.pullY = 0;
                this.pulling = false;
            }
        },
        /* Mouse-drag pull-to-refresh (desktop parity with the touch handlers).
           Pointer events + capture so we keep receiving moves and the release
           even when the cursor leaves the card or the browser window. */
        mouseActive: false,
        justPulled: false,
        onPointerDown(e) {
            if (e.pointerType !== 'mouse' || e.button !== 0 || this.refreshing) return;
            if (this.$el.scrollTop === 0) {
                this.startY = e.clientY;
                this.pulling = true;
                this.mouseActive = true;
            }
        },
        onPointerMove(e) {
            if (e.pointerType !== 'mouse' || !this.mouseActive || !this.pulling) return;
            const dy = e.clientY - this.startY;
            if (dy > 0 && this.$el.scrollTop === 0) {
                /* Capture only once a real pull starts, so plain clicks on
                   thread rows are never intercepted. */
                if (dy > 5 && !this.$el.hasPointerCapture(e.pointerId)) {
                    try { this.$el.setPointerCapture(e.pointerId); } catch (err) {}
                }
                this.pullY = Math.min(dy * 0.4, 80);
            } else {
                this.pullY = 0;
            }
        },
        onPointerUp(e) {
            if (e.pointerType !== 'mouse' || !this.mouseActive) return;
            this.mouseActive = false;
            this.justPulled = this.pullY > 10;
            try { this.$el.releasePointerCapture(e.pointerId); } catch (err) {}
            this.onTouchEnd();
        },
    }"
    x-on:sms-thread-filter-changed.window="$nextTick(() => $el.scrollTop = 0)"
    x-on:touchstart.passive="onTouchStart($event)"
    x-on:touchmove="onTouchMove($event)"
    x-on:touchend="onTouchEnd()"
    {{-- typeof guards keep tabs opened before a deploy from erroring: Livewire
         morphs new bindings into the DOM but Alpine never re-runs x-data. --}}
    x-on:pointerdown="typeof onPointerDown === 'function' && onPointerDown($event)"
    x-on:pointermove="typeof onPointerMove === 'function' && onPointerMove($event)"
    x-on:pointerup="typeof onPointerUp === 'function' && onPointerUp($event)"
    x-on:pointercancel="typeof onPointerUp === 'function' && onPointerUp($event)"
    {{-- Swallow the click that follows a mouse pull so it doesn't select a thread. --}}
    x-on:click.capture="if (typeof justPulled !== 'undefined' && justPulled) { $event.preventDefault(); $event.stopPropagation(); justPulled = false }"
    x-bind:class="typeof mouseActive !== 'undefined' && mouseActive && pullY > 0 ? 'select-none cursor-grabbing' : ''"
    {{-- Safety-net poll: Echo is the primary realtime path; this catches
         missed broadcasts (dropped websocket, failed dispatch). --}}
    wire:poll.60s
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
        @php
            $isSpamThread = $this->threadIsSpam($thread);
        @endphp
        <button
            wire:key="thread-{{ $thread->id }}"
            {{-- NOTE: this handler must START with the `if` statement — Alpine
                 only wraps expressions beginning with `if` as statement blocks
                 (a leading comment would make it an rvalue → syntax error). --}}
            x-on:click="
                if ($store.sms.offline) {
                    /* Offline gate: both Livewire dispatches below are POSTs that
                       are doomed without a network (stuck skeleton). Paint the
                       cached copy from sms-offline.js and skip the server. */
                    $store.sms.threadId = {{ $thread->id }};
                    if (window.HiveSmsOffline) window.HiveSmsOffline.openThread({{ $thread->id }});
                    return;
                }
                /* Optimistic UI: flip the mobile panels immediately so the conversation
                   becomes visible with a skeleton while the server processes selectThread. */
                $store.sms.threadId = {{ $thread->id }};
                window.dispatchEvent(new CustomEvent('thread-switching', { detail: { threadId: {{ $thread->id }} } }));
                /* Load the conversation directly (isolated component) IN PARALLEL with the
                   index URL update, instead of waiting for selectThread to round-trip and
                   then re-dispatch loadThread. `skipConversationLoad` prevents a duplicate. */
                Livewire.dispatch('loadThread', { threadId: {{ $thread->id }} });
                Livewire.dispatch('threadSelected', { threadId: {{ $thread->id }}, skipConversationLoad: true });
            "
            class="w-full text-left px-3 py-2.5 rounded-lg {{ $isSpamThread ? 'opacity-65 grayscale' : '' }}"
            x-bind:class="$store.sms.threadId === {{ $thread->id }}
                ? 'bg-zinc-100 dark:bg-zinc-700'
                : 'hover:bg-zinc-50 dark:hover:bg-zinc-800'"
        >
            <div class="flex items-center justify-between gap-2">
                <div class="min-w-0 flex-1">
                    {{-- Client name, project address, or phone numbers --}}
                    <p class="text-base lg:text-sm font-medium truncate text-zinc-900 dark:text-zinc-100 flex items-center gap-1">
                        <span class="truncate">
                        @if ($isClientUser)
                            @php
                                $participantLabels = collect();

                                $vendorLabel = trim((string) (
                                    $thread->ownerVendor?->short_name
                                    ?: $thread->ownerVendor?->business_name
                                    ?: $thread->subjectVendor?->short_name
                                    ?: $thread->subjectVendor?->business_name
                                    ?: ''
                                ));

                                if ($vendorLabel !== '') {
                                    $participantLabels->push($vendorLabel);
                                }

                                if ($thread->client) {
                                    // Participant-aware: Emily's 1:1 thread says
                                    // "Emily Jordan", not the whole household.
                                    $clientLabel = trim((string) $this->clientDisplayNameForThread($thread));
                                    if ($clientLabel !== '' && ! $participantLabels->contains($clientLabel)) {
                                        $participantLabels->push($clientLabel);
                                    }

                                    $clientUserPhones = $thread->client->users
                                        ->map(fn ($u) => $u->routeNotificationForTelnyx())
                                        ->filter()
                                        ->values();

                                    $extraParticipantLabels = $thread->threadParticipants
                                        ->pluck('phone_number')
                                        ->filter()
                                        ->diff($clientUserPhones)
                                        ->map(fn ($phone) => $this->resolvePhoneDisplay($phone))
                                        ->filter()
                                        ->values();

                                    foreach ($extraParticipantLabels as $label) {
                                        if (! $participantLabels->contains($label)) {
                                            $participantLabels->push($label);
                                        }
                                    }
                                } else {
                                    $fallbackParticipants = $thread->threadParticipants
                                        ->pluck('phone_number')
                                        ->filter()
                                        ->map(fn ($phone) => $this->resolvePhoneDisplay($phone))
                                        ->filter()
                                        ->values();

                                    foreach ($fallbackParticipants as $label) {
                                        if (! $participantLabels->contains($label)) {
                                            $participantLabels->push($label);
                                        }
                                    }
                                }
                            @endphp
                            {{ $participantLabels->implode(', ') }}
                        @elseif ($thread->client_id)
                            @php
                                $threadClientUsers = $this->threadClientUsersForDisplay($thread);
                                $clientUserPhones = $threadClientUsers
                                    ->map(fn ($u) => $u->routeNotificationForTelnyx())
                                    ->filter()
                                    ->values();
                                $partPhones = $thread->threadParticipants->pluck('phone_number')->filter()->values();
                                $extraPhones = $partPhones->diff($clientUserPhones)->values();
                                $missingClientUsers = $clientUserPhones->diff($partPhones)->isNotEmpty();
                                $clientLabel = $this->clientDisplayNameForThread($thread);
                            @endphp
                            @if ($missingClientUsers && $extraPhones->isEmpty())
                                {{-- Subset of client users only — list participants --}}
                                {{ $partPhones->map(fn ($p) => $this->resolvePhoneDisplay($p))->implode(', ') }}
                            @else
                                {{ $clientLabel }}@if ($extraPhones->isNotEmpty())<span class="text-zinc-400 dark:text-zinc-500">, {{ $extraPhones->map(fn ($p) => $this->resolvePhoneDisplay($p))->implode(', ') }}</span>@endif
                            @endif
                        @elseif ($thread->name)
                            {{ $thread->name }}
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
                        @if ($isSpamThread)
                            <span class="shrink-0 inline-flex items-center justify-center size-4 rounded bg-amber-100 dark:bg-amber-900/40" title="Spam">
                                <flux:icon name="shield-exclamation" class="size-3 text-amber-600 dark:text-amber-400" />
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
                            $previewText = trim((string) ($thread->latestMessage->display_text ?? $thread->latestMessage->text));

                            $previewPrefix = null;
                            if ($thread->latestMessage->isOutbound()) {
                                $previewPrefix = $isClientUser
                                    ? 'GS Construciton:'
                                    : ($thread->latestMessage->sent_by_user_id === auth()->id() ? 'You:' : (($thread->latestMessage->sentByUser?->nickname ?: $thread->latestMessage->sentByUser?->first_name) ?? 'GS Crew') . ':');
                            } elseif ($thread->latestMessage->isInbound()) {
                                $sender = $this->resolvePreviewSender($thread->latestMessage->from_number, $thread);
                                $previewPrefix = $sender ? $sender . ':' : null;
                            }

                            // For tapback reactions, show clean emoji only
                            if ($tapback) {
                                $previewText = $tapback['emoji'] ?? $tapback['reaction'];
                            }

                            // For remote edits ("Editado como: …"), preview the edited text
                            // like iMessage would — not the carrier notification wrapper.
                            $remoteEdit = $thread->latestMessage->parseRemoteEdit();
                            if ($remoteEdit !== null) {
                                $previewText = $remoteEdit;
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


