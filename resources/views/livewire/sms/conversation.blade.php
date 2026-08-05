<div
    class="flex-1 min-h-0 flex flex-col"
    {{-- Safety-net poll: cheap fingerprint check server-side; only repaints
         when the thread's messages actually changed (missed broadcast,
         scheduled message sending, delivery status update). --}}
    wire:poll.30s="pollForUpdates"
    x-data="{
        switching: false,
        switchingTimer: null,
        pulling: false,
        pullY: 0,
        startY: 0,
        refreshing: false,
        onPullStart(e) {
            if (window.innerWidth >= 1024) return;
            this.startY = e.touches[0].clientY;
            this.pulling = true;
        },
        onPullMove(e) {
            if (!this.pulling || window.innerWidth >= 1024) return;
            const dy = e.touches[0].clientY - this.startY;
            if (dy > 0) {
                this.pullY = Math.min(dy * 0.4, 80);
                if (dy > 10) e.preventDefault();
            } else {
                this.pullY = 0;
            }
        },
        onPullEnd() {
            if (this.pullY >= 60 && !this.refreshing) {
                this.refreshing = true;
                this.pullY = 50;
                $wire.call('refreshMessages').then(() => {
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
    x-on:thread-switching.window="
        clearTimeout(switchingTimer);
        switching = true;
        /* Safety net: if thread-ready never arrives (request failed / offline),
           dissolve the skeleton instead of stranding it forever — whatever is
           beneath (previous thread or cached copy) is more useful. */
        switchingTimer = setTimeout(() => { switching = false }, 8000);
    "
    x-on:thread-ready.window="
        clearTimeout(switchingTimer);
        $nextTick(() => { switching = false });
    "
>
    @island(name: 'sms-conversation-stream', always: true)
    @if ($this->thread)
        {{-- Pull-to-refresh indicator (mobile only) --}}
        <div
            x-show="pullY > 0"
            x-cloak
            x-bind:style="'height: ' + pullY + 'px'"
            class="flex items-end justify-center overflow-hidden transition-none lg:hidden"
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

        {{-- Header — title logic lives in ConversationPresenter (shared with
             the offline fragment so both render the same header). --}}
        @php
            $participantPhones = $this->presenter->participantPhones();
            $headerTitle = $this->presenter->headerTitle();
            $headerParts = $this->presenter->headerParts();
        @endphp
        <div
            x-show="switching"
            x-cloak
            x-transition:leave="transition-opacity duration-150 ease-out"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            class="sms-mobile-header-offset border-b border-zinc-200 dark:border-zinc-700 py-2"
        >
            <div class="flex items-center gap-1.5">
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
                        $wire.$parent.clearThread();
                    "
                    aria-label="Back to conversations"
                ></flux:button>
                <div class="h-5 w-40 rounded animate-pulse bg-zinc-200/70 dark:bg-zinc-700/50"></div>
            </div>
        </div>

        <div
            x-show="!switching"
            class="sms-mobile-header-offset border-b border-zinc-200 dark:border-zinc-700 py-2"
            x-on:touchstart.passive="onPullStart($event)"
            x-on:touchmove="onPullMove($event)"
            x-on:touchend="onPullEnd()"
        >
            {{-- Title row --}}
            <div class="flex items-center gap-1.5">
                {{-- Mobile back button: optimistic — flip the panels instantly,
                     then tell the server to clear in the background. --}}
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
                        $wire.$parent.clearThread();
                    "
                    aria-label="Back to conversations"
                ></flux:button>
                @if ($this->thread->client)
                    <flux:heading size="lg" class="mb-0 truncate flex-1">
                        @if ($headerParts)
                            @foreach ($headerParts as $i => $part)
                                @if ($i > 0)<span class="text-zinc-400">,</span> @endif
                                @if ($part['linkToClient'])
                                    <a wire:navigate.hover href="{{ route('clients.show', $this->thread->client_id) }}" wire:navigate.hover class="hover:underline">{{ $part['label'] }}</a>
                                @else
                                    <span>{{ $part['label'] }}</span>
                                @endif
                            @endforeach
                        @else
                            <a wire:navigate.hover href="{{ route('clients.show', $this->thread->client_id) }}" wire:navigate.hover class="hover:underline">{{ $headerTitle }}</a>
                        @endif
                    </flux:heading>
                @elseif (! empty($this->thread->name_data))
                    <flux:heading size="lg" class="mb-0 flex-1">
                        @foreach ($this->thread->name_data as $i => $part)
                            @if ($i > 0)<span class="text-zinc-400">,</span> @endif
                            @if (! empty($part['client_id']))
                                <a href="{{ route('clients.show', $part['client_id']) }}" wire:navigate.hover class="hover:underline">{{ $part['label'] }}</a>
                            @else
                                {{ $part['label'] }}
                            @endif
                        @endforeach
                    </flux:heading>
                @elseif ($this->thread->subjectVendor)
                    <flux:heading size="lg" class="mb-0 truncate flex-1">
                        <a wire:navigate.hover href="{{ route('vendors.show', $this->thread->subject_vendor_id) }}" wire:navigate.hover class="hover:underline">{{ $headerTitle }}</a>
                    </flux:heading>
                @else
                    <flux:heading size="lg" class="mb-0 truncate flex-1">{{ $headerTitle }}</flux:heading>
                @endif
                @if ($this->thread->project)
                    <flux:button size="sm" variant="ghost" href="{{ route('projects.show', $this->thread->project_id) }}" wire:navigate.hover icon="arrow-top-right-on-square">
                        Project
                    </flux:button>
                @endif

                @if (! $isClientUser)
                    <flux:dropdown position="bottom" align="end">
                        <flux:button variant="ghost" size="sm" square icon="ellipsis-vertical" />
                        <flux:menu>
                            @if (! $this->thread->allParticipantsOptedIn())
                                <flux:menu.item icon="arrow-path" wire:click="resendOptInPrompt">
                                    Resend Opt-in
                                </flux:menu.item>
                                <flux:menu.item icon="shield-check" wire:click="openOptInModal">
                                    Manual Opt-in
                                </flux:menu.item>
                                <flux:separator />
                            @endif
                            <flux:menu.item icon="user" wire:click="openAssignClientModal">
                                Assign Client / Vendor
                            </flux:menu.item>
                            <flux:menu.item icon="arrow-right-circle" wire:click="toggleSelectionMode">
                                Forward messages
                            </flux:menu.item>
                            @if ($this->hasBlockedThreadSpamTargets())
                                <flux:menu.item icon="no-symbol" wire:click="unblockThreadSpam">
                                    Unblock Number
                                </flux:menu.item>
                            @else
                                <flux:menu.item icon="exclamation-triangle" wire:click="markThreadAsSpam">
                                    Mark as Spam
                                </flux:menu.item>
                            @endif
                            <flux:separator />
                            <flux:menu.item variant="danger" icon="trash" x-on:click="$wire.showDeleteConfirm = true">
                                Delete Thread
                            </flux:menu.item>
                        </flux:menu>
                    </flux:dropdown>
                @endif
            </div>

            @if (! $isClientUser)
                @php
                    $callableContacts = collect();
                    $seenE164 = [];

                    // Collect users from the thread's primary client
                    // Only show users whose phone is a thread participant
                    $threadParticipants = $participantPhones;
                    if ($this->thread->client) {
                        foreach ($this->threadClientUsersFor($this->thread->client) as $user) {
                            $raw = $user->getRawOriginal('cell_phone');
                            if (! $raw) continue;
                            $e164 = $user->routeNotificationForTelnyx();
                            if (\App\Services\GroupSmsService::isOurNumber($e164)) continue;
                            if (! $threadParticipants->contains($e164)) continue;
                            if (isset($seenE164[$e164])) continue;
                            $seenE164[$e164] = true;
                            $digits = preg_replace('/[^0-9]/', '', $raw);
                            if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
                                $digits = substr($digits, 1);
                            }
                            $display = strlen($digits) === 10
                                ? '(' . substr($digits, 0, 3) . ') ' . substr($digits, 3, 3) . '-' . substr($digits, 6)
                                : $raw;
                            $callableContacts->push([
                                'name' => $user->first_name,
                                'e164' => $e164,
                                'display' => $display,
                            ]);
                        }
                    }

                    // Also pull users from additional clients listed in name_data
                    if (! empty($this->thread->name_data)) {
                        $extraClientIds = collect($this->thread->name_data)
                            ->pluck('client_id')
                            ->filter()
                            ->reject(fn ($id) => $id == $this->thread->client_id)
                            ->unique();

                        foreach ($extraClientIds as $clientId) {
                            $extraClient = \App\Models\Client::with('users:id,first_name,last_name,cell_phone')->find($clientId);
                            if (! $extraClient) continue;
                            foreach ($this->threadClientUsersFor($extraClient) as $user) {
                                $raw = $user->getRawOriginal('cell_phone');
                                if (! $raw) continue;
                                $e164 = $user->routeNotificationForTelnyx();
                                if (\App\Services\GroupSmsService::isOurNumber($e164)) continue;
                                if (isset($seenE164[$e164])) continue;
                                $seenE164[$e164] = true;
                                $digits = preg_replace('/[^0-9]/', '', $raw);
                                if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
                                    $digits = substr($digits, 1);
                                }
                                $display = strlen($digits) === 10
                                    ? '(' . substr($digits, 0, 3) . ') ' . substr($digits, 3, 3) . '-' . substr($digits, 6)
                                    : $raw;
                                $callableContacts->push([
                                    'name' => $user->first_name,
                                    'e164' => $e164,
                                    'display' => $display,
                                ]);
                            }
                        }
                    }

                    // Always include any thread participant phones not yet covered
                    // by client users (e.g. an external number added to a client group).
                    foreach ($participantPhones as $phone) {
                        if (\App\Services\GroupSmsService::isOurNumber($phone)) continue;
                        if (isset($seenE164[$phone])) continue;
                        $seenE164[$phone] = true;
                        $name = $this->resolvePhoneDisplay($phone);
                        $digits = preg_replace('/[^0-9]/', '', $phone);
                        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
                            $d10 = substr($digits, 1);
                        } else {
                            $d10 = $digits;
                        }
                        $displayPhone = strlen($d10) === 10
                            ? '(' . substr($d10, 0, 3) . ') ' . substr($d10, 3, 3) . '-' . substr($d10, 6)
                            : $phone;
                        $callableContacts->push([
                            // Only label with a name when the phone is linked to a
                            // user/vendor — a CNAM-only name (no linked contact)
                            // shows the number so it's clear who this actually is.
                            'name' => ($name !== $displayPhone && $this->isKnownContact($phone)) ? $name : null,
                            'e164' => $phone,
                            'display' => $displayPhone,
                        ]);
                    }
                @endphp

                @if ($callableContacts->isNotEmpty())
                    <div class="flex flex-wrap items-center gap-x-4 gap-y-0.5 mt-1">
                        @foreach ($callableContacts as $contact)
                            <button
                                type="button"
                                wire:click="initiateCall('{{ $contact['e164'] }}')"
                                wire:loading.attr="disabled"
                                wire:target="initiateCall"
                                class="inline-flex items-center gap-1 text-sm lg:text-xs text-indigo-500 hover:text-indigo-600 dark:text-indigo-400 hover:underline cursor-pointer disabled:opacity-50"
                                title="Call {{ $contact['name'] ?? $contact['display'] }} via your phone"
                            >
                                <flux:icon name="phone" class="size-3" />
                                {{ $contact['name'] ?? $contact['display'] }}
                            </button>
                        @endforeach
                        @if ($callableContacts->count() > 1)
                            <flux:button
                                type="button"
                                size="xs"
                                variant="subtle"
                                icon="phone"
                                wire:click="initiateCallAll"
                                wire:loading.attr="disabled"
                                wire:target="initiateCallAll"
                                class="ml-auto"
                            >
                                Call All ({{ $callableContacts->count() }})
                            </flux:button>
                        @endif
                    </div>
                @endif
            @endif
        </div>

        {{-- Messages --}}

        <div
            class="contents"
            x-data="{
                selectionMode: @js((bool) $selectionMode),
                selected: @js(array_values($selectedMessageIds)),
                toggle(id) {
                    const i = this.selected.indexOf(id);
                    if (i === -1) { this.selected.push(id); } else { this.selected.splice(i, 1); }
                },
                has(id) { return this.selected.includes(id); },
                clear() { this.selected = []; this.selectionMode = false; },
            }"
            x-on:sms-selection-cleared.window="clear()"
            x-on:sms-selection-started.window="selectionMode = true; if ($event.detail?.ids) selected = $event.detail.ids"
        >
        <div class="relative flex-1 min-h-0">
            {{-- Loading skeleton during thread switching (transparent overlay, bubbles only) --}}
            <div
                x-show="switching"
                x-cloak
                x-transition:leave="transition-opacity duration-150 ease-out"
                x-transition:leave-start="opacity-100"
                x-transition:leave-end="opacity-0"
                class="absolute inset-0 z-10 pointer-events-none"
            >
                @include('livewire.sms.conversation_placeholder')
            </div>

            <div class="sms-fade-overlay top"></div>
        <div
            class="sms-messages h-full overflow-y-auto flex flex-col-reverse gap-3 px-2 pt-6 pb-6"
            x-show="!switching"
            x-on:message-sent.window="$nextTick(() => $el.scrollTop = 0)"
            x-on:sms-new-message-received.window="if ($el.scrollTop < 150) $nextTick(() => $el.scrollTop = 0)"
            x-on:thread-ready.window="$nextTick(() => $el.scrollTop = 0)"
            x-on:scroll.debounce.150ms="
                if ($el.__loadingMore) return;
                if ($el.scrollHeight + $el.scrollTop - $el.clientHeight < 200) {
                    const trigger = $el.querySelector('[data-load-more]');
                    if (trigger) {
                        $el.__loadingMore = true;
                        $wire.loadMoreMessages().then(() => $nextTick(() => $el.__loadingMore = false));
                    }
                }
            "
        >
            {{-- Nested island: an incoming message re-renders ONLY this bubble
                 list (skipRender + renderIsland('sms-bubbles')) — ~10KB instead
                 of the full conversation. Params live INSIDE the island because
                 island fragments render standalone and don't inherit the outer
                 template's locals. --}}
            @island(name: 'sms-bubbles', always: true)
        @php
            $phoneNameMap = $this->phoneNameMap;
            $processed = $this->processedMessages;
            $visibleMessages = $processed['visible'];
            $scheduledMessages = $processed['scheduled'];
            $reactionsMap = $processed['reactions'];

            $tz = browser_timezone();
            $now = now($tz);
            $todayDate = $now->toDateString();
            $yesterdayDate = $now->copy()->subDay()->toDateString();

            // Params for the shared message-bubbles partial (single source of
            // bubble markup — the offline fragment renders the same partial
            // with $interactive = false). Closures live in this top-level
            // @php block on purpose: never define them in @php(...) directly
            // after an @if (Blaze compile bug).
            $interactive = true;
            $threadHasMixedNumbers = $this->threadHasMixedNumbers;
            $hasMoreMessages = $this->smsMessages->count() >= $this->messageLimit;
            $resolveMediaUrl = fn (string $url): string => $this->mediaUrl($url);
            $allowsTaskCreation = fn ($m): bool => $this->messageAllowsTaskCreation($m);
        @endphp
            {{-- Bubble markup lives in ONE place — the shared partial (also rendered, non-interactive, by the offline fragment). --}}
            @include('livewire.sms.partials.message-bubbles')
            @endisland
        </div>
            <div class="sms-fade-overlay bottom"></div>

            @if (! $isClientUser)
                {{-- ONE shared message-actions menu for every bubble (the ⋮
                     trigger in the bubbles partial dispatches sms-message-menu
                     with the message context + anchor element). Lives OUTSIDE
                     the sms-bubbles island so island morphs never touch it. --}}
                <div
                    x-data="{ mmOpen: false, mm: { id: null, canTask: false, hasText: false, text: '', images: 0 }, mmAnchor: null }"
                    x-on:sms-message-menu.window="mm = $event.detail; mmAnchor = $event.detail.anchor; mmOpen = true"
                    x-on:sms-new-message-received.window="mmOpen = false"
                    x-on:thread-ready.window="mmOpen = false"
                    x-on:keydown.escape.window="mmOpen = false"
                >
                    <template x-if="mmOpen">
                        <div
                            x-anchor.bottom-end.offset.4="mmAnchor"
                            x-on:click.outside="mmOpen = false"
                            x-transition.opacity.duration.100ms
                            class="absolute z-30 min-w-40 rounded-lg border border-zinc-200 bg-white p-1 shadow-lg dark:border-zinc-600 dark:bg-zinc-700"
                        >
                            <button type="button" x-show="mm.canTask"
                                class="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-sm text-zinc-800 hover:bg-zinc-100 dark:text-zinc-100 dark:hover:bg-zinc-600"
                                x-on:click="mmOpen = false; $wire.createTaskFromMessage(mm.id)">
                                <flux:icon.calendar-date-range variant="mini" class="size-4 text-zinc-400 dark:text-zinc-300" /> Create Task
                            </button>
                            <button type="button"
                                class="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-sm text-zinc-800 hover:bg-zinc-100 dark:text-zinc-100 dark:hover:bg-zinc-600"
                                x-on:click="mmOpen = false; $wire.forwardSingleMessage(mm.id)">
                                <flux:icon.arrow-right-circle variant="mini" class="size-4 text-zinc-400 dark:text-zinc-300" /> Forward
                            </button>
                            <button type="button" x-show="mm.images > 0"
                                class="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-sm text-zinc-800 hover:bg-zinc-100 dark:text-zinc-100 dark:hover:bg-zinc-600"
                                x-on:click="mmOpen = false; $wire.openAddToProjectModal(mm.id)">
                                <flux:icon.folder-plus variant="mini" class="size-4 text-zinc-400 dark:text-zinc-300" /> Add to Project
                            </button>
                            <button type="button" x-show="mm.hasText"
                                class="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-sm text-zinc-800 hover:bg-zinc-100 dark:text-zinc-100 dark:hover:bg-zinc-600"
                                x-on:click="mmOpen = false; navigator.clipboard.writeText(mm.text); $flux.toast({ text: 'Message copied', variant: 'success' })">
                                <flux:icon.clipboard variant="mini" class="size-4 text-zinc-400 dark:text-zinc-300" /> Copy Text
                            </button>
                        </div>
                    </template>
                </div>
            @endif
        </div>

        <div class="sticky bottom-0 z-20 border-t border-zinc-200 bg-transparent dark:border-zinc-700 px-4 py-3 flex items-center justify-between gap-3"
            x-show="selectionMode" x-cloak>
            <div class="text-sm font-medium text-zinc-700 dark:text-zinc-200">
                <span x-text="selected.length"></span>
                <span x-text="selected.length === 1 ? 'message' : 'messages'"></span> selected
            </div>
            <div class="flex items-center gap-2">
                <flux:button size="sm" variant="ghost" x-on:click="clear(); $wire.exitSelectionMode()">Cancel</flux:button>
                <flux:button
                    size="sm"
                    variant="primary"
                    icon="arrow-right-circle"
                    x-on:click="$wire.openForwardModalWithSelection(selected)"
                    x-bind:disabled="selected.length === 0"
                >
                    Forward
                </flux:button>
            </div>
        </div>
        </div>{{-- /alpine selection wrapper --}}

        {{-- Forward Messages Modal --}}
        <flux:modal
            wire:key="forward-messages-modal"
            name="forward-messages"
            @close="$wire.set('showForwardModal', false, false)"
            class="max-w-lg w-full max-h-[85vh] flex flex-col overflow-hidden !p-0"
        >
            {{-- Every thread as a pickable option (~270KB): render only while
                 open, so thread switches don't re-download it each time. --}}
            @if($showForwardModal)
            <form wire:submit="forwardMessages" class="flex flex-col min-h-0 min-w-0 flex-1 w-full" x-data="{ q: '' }">
                <div class="px-6 pt-6 pb-4 space-y-4 border-b border-zinc-200 dark:border-zinc-700">
                    <div>
                        <flux:heading size="lg">Forward {{ count($selectedMessageIds) }} {{ \Illuminate\Support\Str::plural('message', count($selectedMessageIds)) }}</flux:heading>
                        <flux:text class="mt-1">Pick a conversation to forward the selected messages to.</flux:text>
                    </div>

                    <flux:field>
                        <flux:label>Search conversations</flux:label>
                        <flux:input x-model="q" placeholder="Search by name, client, vendor, address..." />
                    </flux:field>
                </div>

                <div class="flex-1 min-h-0 min-w-0 overflow-auto forward-modal-scroll px-6 py-4">
                    <flux:field>
                        <flux:label>Destination</flux:label>
                        @if ($this->forwardableThreads->isEmpty())
                            <div class="px-3 py-6 text-sm text-zinc-500 dark:text-zinc-400 text-center border border-zinc-200 dark:border-zinc-700 rounded-lg">
                                No matching conversations.
                            </div>
                        @else
                            <flux:radio.group wire:model="forwardTargetThreadId" variant="cards" class="flex-col gap-1" :indicator="true">
                                @foreach ($this->forwardableThreads as $candidate)
                                    @php
                                        $label = $this->forwardThreadLabel($candidate);
                                        $desc = $candidate->last_activity_at ? 'Last activity ' . $candidate->last_activity_at->diffForHumans() : '';
                                        $haystack = mb_strtolower($label . ' ' . $desc);
                                    @endphp
                                    <div
                                        wire:key="forward-thread-{{ $candidate->id }}"
                                        {{-- typeof guard: during a Livewire morph this row can be
                                             evaluated before its x-data scope re-attaches. --}}
                                        x-show="typeof q === 'undefined' || q === '' || @js($haystack).includes(q.toLowerCase())"
                                    >
                                        <flux:radio
                                            :value="$candidate->id"
                                            :label="$label"
                                            :description="$desc ?: null"
                                        >{{ $label }}</flux:radio>
                                    </div>
                                @endforeach
                            </flux:radio.group>
                        @endif
                        <flux:error name="forwardTargetThreadId" />
                        <flux:error name="selectedMessageIds" />
                    </flux:field>
                </div>

                <div class="flex justify-end gap-2 px-6 py-4 border-t border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-900/50">
                    <flux:button type="button" variant="ghost" x-on:click="$flux.modal('forward-messages').close()">Cancel</flux:button>
                    <flux:button type="submit" variant="primary" icon="arrow-right-circle">
                        Forward
                    </flux:button>
                </div>
            </form>
            @endif
        </flux:modal>

        {{-- Add photos to a project --}}
        <flux:modal
            wire:key="add-to-project-modal"
            name="add-to-project"
            @close="$wire.set('showAddToProjectModal', false, false)"
            class="max-w-lg w-full max-h-[85vh] flex flex-col overflow-hidden !p-0"
        >
            @if($showAddToProjectModal)
            <form wire:submit="addImagesToProject" class="flex flex-col min-h-0 min-w-0 flex-1 w-full" x-data="{ q: '' }">
                <div class="px-6 pt-6 pb-4 space-y-4 border-b border-zinc-200 dark:border-zinc-700">
                    <div>
                        <flux:heading size="lg">Add Photos to Project</flux:heading>
                        <flux:text class="mt-1">Copies this message's photos into the project's images.</flux:text>
                    </div>

                    <flux:field>
                        <flux:label>Search projects</flux:label>
                        <flux:input x-model="q" placeholder="Search by address or name..." />
                    </flux:field>
                </div>

                <div class="flex-1 min-h-0 min-w-0 overflow-auto forward-modal-scroll px-6 py-4">
                    <flux:field>
                        <flux:label>Project</flux:label>
                        @if ($this->addableProjects->isEmpty())
                            <div class="px-3 py-6 text-sm text-zinc-500 dark:text-zinc-400 text-center border border-zinc-200 dark:border-zinc-700 rounded-lg">
                                No matching projects.
                            </div>
                        @else
                            <flux:radio.group wire:model="addToProjectTargetId" variant="cards" class="flex-col gap-1" :indicator="true">
                                @foreach ($this->addableProjects as $candidate)
                                    @php
                                        $label = $candidate->short_address ?: ($candidate->address ?: ($candidate->project_name ?: 'Project #'.$candidate->id));
                                        $secondary = collect([$candidate->project_name, $candidate->client?->name])
                                            ->filter()->unique()->implode(' · ');
                                        $haystack = mb_strtolower($label.' '.($candidate->address ?? '').' '.$secondary);
                                    @endphp
                                    <div
                                        wire:key="add-project-{{ $candidate->id }}"
                                        x-show="typeof q === 'undefined' || q === '' || @js($haystack).includes(q.toLowerCase())"
                                    >
                                        {{-- Same shape as the shared project select: address with
                                             the status badge beside it, detail line underneath. --}}
                                        <flux:radio :value="$candidate->id">
                                            {{-- One block child: the card slot is a flex row, so
                                                 two siblings would sit side by side instead of
                                                 stacking like the shared project select. --}}
                                            <span class="block min-w-0 flex-1">
                                                <span class="flex items-center gap-2">
                                                    <span class="min-w-0 flex-1 truncate">{{ $label }}</span>
                                                    @if ($candidate->latestStatus)
                                                        <flux:badge size="sm" :color="$candidate->latestStatus->badge_color" inset="top bottom" class="shrink-0">
                                                            {{ $candidate->latestStatus->title }}
                                                        </flux:badge>
                                                    @endif
                                                </span>
                                                @if ($secondary !== '')
                                                    <span class="mt-0.5 block text-sm font-normal text-zinc-500 dark:text-zinc-400">{{ $secondary }}</span>
                                                @endif
                                            </span>
                                        </flux:radio>
                                    </div>
                                @endforeach
                            </flux:radio.group>
                        @endif
                        <flux:error name="addToProjectTargetId" />
                    </flux:field>
                </div>

                <div class="flex justify-end gap-2 px-6 py-4 border-t border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-900/50">
                    <flux:button type="button" variant="ghost" x-on:click="$flux.modal('add-to-project').close()">Cancel</flux:button>
                    <flux:button type="submit" variant="primary" icon="folder-plus">
                        Add Photos
                    </flux:button>
                </div>
            </form>
            @endif
        </flux:modal>

        {{-- Manual Opt-In Modal --}}
        <flux:modal wire:model="showOptInModal" name="manual-opt-in" class="max-w-md space-y-6">
            <div>
                <flux:heading size="lg">Manual Opt-In</flux:heading>
                <flux:text class="mt-1">Manually opt in a participant who confirmed consent outside of SMS (e.g. texted START to a different number, approved on a phone call, emailed with START).</flux:text>
            </div>

            <form wire:submit="manualOptIn" class="space-y-4">
                <flux:field>
                    <flux:label>Participant</flux:label>
                    @if ($this->pendingParticipants->count() > 0)
                        <flux:select wire:model.live="manualOptInParticipantId" variant="listbox" placeholder="Select participant...">
                            @foreach ($this->pendingParticipants as $participant)
                                <flux:select.option value="{{ $participant->id }}">
                                    {{ $this->resolvePhoneDisplay($participant->phone_number) }}
                                    ({{ preg_replace('/.*(\d{3})(\d{3})(\d{4})$/', '($1) $2-$3', preg_replace('/[^0-9]/', '', $participant->phone_number)) }})
                                </flux:select.option>
                            @endforeach
                        </flux:select>
                    @else
                        <flux:text class="text-sm text-zinc-500">All participants have already opted in.</flux:text>
                    @endif
                    <flux:error name="manualOptInParticipantId" />
                </flux:field>

                <flux:field>
                    <flux:label>Reason for manual opt-in</flux:label>
                    <flux:textarea
                        wire:model="manualOptInReason"
                        placeholder="e.g. Texted START to a different number, Approved on a phone call, Emailed with START..."
                        rows="3"
                    />
                    <flux:error name="manualOptInReason" />
                </flux:field>

                <div class="flex justify-end gap-2">
                    <flux:button type="button" variant="ghost" wire:click="$set('showOptInModal', false)">Cancel</flux:button>
                    <flux:button
                        type="submit"
                        variant="primary"
                        class="data-loading:opacity-50 data-loading:pointer-events-none"
                    >
                        Confirm Opt-In
                    </flux:button>
                </div>
            </form>
        </flux:modal>

        <style>
            .sms-mobile-header-offset {
                padding-left: 2.5rem;
                padding-right: 0.5rem;
            }

            @media (min-width: 1024px) {
                .sms-mobile-header-offset {
                    padding-left: 1rem;
                    padding-right: 1rem;
                }
            }

            [data-modal="sms-image-lightbox"]::backdrop {
                background-color: rgba(0, 0, 0, 0.50);
                backdrop-filter: blur(4px);
                -webkit-backdrop-filter: blur(4px);
            }
        </style>

        <flux:modal
            wire:model="showImageLightbox"
            name="sms-image-lightbox"
            :closable="false"
            variant="bare"
            class="!p-0"
            style="width:96vw;max-width:96vw;height:94vh;max-height:94vh;padding:0;margin:auto;"
        >
            {{-- wire:ignore — Livewire must never morph this subtree: doing so
                 re-evaluates child expressions against a torn Alpine scope
                 ("currentIsVideo is not defined" errors). The lightbox is fully
                 self-syncing at runtime: `images` refresh via the
                 lightbox-images-updated event on every open, and the active
                 item follows $wire.lightboxImageUrl via $watch. --}}
            <div
                wire:ignore
                x-data="{
                    images: @js($this->threadMedia),
                    currentIndex: 0,
                    activeUrl: '',
                    scale: 1,
                    translateX: 0,
                    translateY: 0,
                    isZoomed: false,
                    minScale: 1,
                    maxScale: 4,
                    isPanning: false,
                    lastX: 0,
                    lastY: 0,
                    initialPinchDistance: null,
                    initialScale: 1,
                    touchStartX: 0,
                    touchStartY: 0,
                    lastTouchX: 0,
                    lastTouchY: 0,
                    swipeStartX: null,

                    convertUrl(url) {
                        if (url.startsWith('http')) return url;
                        // Just pass relative paths directly - route() will handle them
                        const filename = url.startsWith('/storage/')
                            ? url.substring('/storage/'.length)
                            : url;
                        return '{{ route('sms.media', ['filename' => 'XXX']) }}'.replace('XXX', filename);
                    },

                    get currentUrl() { 
                        const url = this.activeUrl || this.images[this.currentIndex] || ''; 
                        return this.convertUrl(url);
                    },
                    get currentRawUrl() {
                        return this.activeUrl || this.images[this.currentIndex] || '';
                    },
                    get currentIsVideo() {
                        return this.isVideoUrl(this.currentRawUrl);
                    },
                    get hasMultiple() { return this.images.length > 1; },
                    get hasPrev() { return this.currentIndex > 0; },
                    get hasNext() { return this.currentIndex < this.images.length - 1; },

                    isVideoUrl(url) {
                        if (!url) return false;
                        const clean = String(url).split('?')[0].toLowerCase();
                        return /\.(mp4|mov|avi|mkv|3gp|3gpp|webm|m4v|wmv|flv|ogv)$/.test(clean);
                    },

                    goTo(idx) {
                        if (idx < 0 || idx >= this.images.length) return;
                        this.resetZoom();
                        this.currentIndex = idx;
                        this.activeUrl = this.images[idx] || '';
                    },
                    prev() { this.goTo(this.currentIndex - 1); },
                    next() { this.goTo(this.currentIndex + 1); },

                    syncFromWire(url) {
                        if (!url) return;
                        this.activeUrl = url;

                        let idx = this.images.indexOf(url);
                        if (idx === -1) {
                            const decoded = decodeURIComponent(url);
                            idx = this.images.findIndex(u => decodeURIComponent(u) === decoded);
                        }
                        if (idx === -1) {
                            this.images.push(url);
                            idx = this.images.length - 1;
                        }
                        this.currentIndex = idx;
                    },

                    resetZoom() {
                        this.scale = 1;
                        this.translateX = 0;
                        this.translateY = 0;
                        this.isZoomed = false;
                    },

                    constrainTranslation() {
                        if (this.scale <= 1) {
                            this.translateX = 0;
                            this.translateY = 0;
                            return;
                        }
                        const container = this.$refs.zoomContainer;
                        if (!container) return;
                        const img = container.querySelector('img');
                        if (!img) return;
                        const containerRect = container.getBoundingClientRect();
                        const scaledWidth = img.offsetWidth * this.scale;
                        const scaledHeight = img.offsetHeight * this.scale;
                        const maxX = Math.max(0, (scaledWidth - containerRect.width) / 2);
                        const maxY = Math.max(0, (scaledHeight - containerRect.height) / 2);
                        this.translateX = Math.max(-maxX, Math.min(maxX, this.translateX));
                        this.translateY = Math.max(-maxY, Math.min(maxY, this.translateY));
                    },

                    handleWheel(e) {
                        if (this.currentIsVideo) return;
                        e.preventDefault();
                        const delta = e.deltaY > 0 ? 0.9 : 1.1;
                        const newScale = Math.max(this.minScale, Math.min(this.maxScale, this.scale * delta));
                        if (newScale !== this.scale) {
                            const container = this.$refs.zoomContainer;
                            if (!container) return;
                            const rect = container.getBoundingClientRect();
                            const x = e.clientX - rect.left - rect.width / 2;
                            const y = e.clientY - rect.top - rect.height / 2;
                            const scaleFactor = newScale / this.scale;
                            this.translateX = x - (x - this.translateX) * scaleFactor;
                            this.translateY = y - (y - this.translateY) * scaleFactor;
                            this.scale = newScale;
                            this.isZoomed = this.scale > 1.05;
                            this.constrainTranslation();
                        }
                    },

                    handleDoubleTap(e) {
                        if (this.currentIsVideo) return;
                        if (this.isZoomed) {
                            this.resetZoom();
                        } else {
                            const container = this.$refs.zoomContainer;
                            if (!container) return;
                            const rect = container.getBoundingClientRect();
                            const x = e.clientX - rect.left - rect.width / 2;
                            const y = e.clientY - rect.top - rect.height / 2;
                            this.scale = 2;
                            this.translateX = -x;
                            this.translateY = -y;
                            this.isZoomed = true;
                            this.constrainTranslation();
                        }
                    },

                    handleMouseDown(e) {
                        if (this.currentIsVideo) return;
                        if (!this.isZoomed) return;
                        e.preventDefault();
                        this.isPanning = true;
                        this.lastX = e.clientX;
                        this.lastY = e.clientY;
                    },

                    handleMouseMove(e) {
                        if (!this.isPanning) return;
                        this.translateX += e.clientX - this.lastX;
                        this.translateY += e.clientY - this.lastY;
                        this.constrainTranslation();
                        this.lastX = e.clientX;
                        this.lastY = e.clientY;
                    },

                    handleMouseUp() { this.isPanning = false; },

                    handleTouchStart(e) {
                        if (this.currentIsVideo) {
                            this.swipeStartX = e.touches.length === 1 ? e.touches[0].clientX : null;
                            return;
                        }
                        if (e.touches.length === 2) {
                            this.initialPinchDistance = Math.hypot(
                                e.touches[0].clientX - e.touches[1].clientX,
                                e.touches[0].clientY - e.touches[1].clientY
                            );
                            this.initialScale = this.scale;
                        } else if (e.touches.length === 1) {
                            this.lastTouchX = e.touches[0].clientX;
                            this.lastTouchY = e.touches[0].clientY;
                            if (this.isZoomed) {
                                this.isPanning = true;
                            } else {
                                this.swipeStartX = e.touches[0].clientX;
                            }
                        }
                    },

                    handleTouchMove(e) {
                        if (e.touches.length === 2 && this.initialPinchDistance) {
                            const distance = Math.hypot(
                                e.touches[0].clientX - e.touches[1].clientX,
                                e.touches[0].clientY - e.touches[1].clientY
                            );
                            this.scale = Math.max(this.minScale, Math.min(this.maxScale,
                                this.initialScale * (distance / this.initialPinchDistance)
                            ));
                            this.isZoomed = this.scale > 1.05;
                            this.constrainTranslation();
                            e.preventDefault();
                        } else if (e.touches.length === 1 && this.isZoomed && this.isPanning) {
                            this.translateX += e.touches[0].clientX - this.lastTouchX;
                            this.translateY += e.touches[0].clientY - this.lastTouchY;
                            this.constrainTranslation();
                            this.lastTouchX = e.touches[0].clientX;
                            this.lastTouchY = e.touches[0].clientY;
                            e.preventDefault();
                        }
                    },

                    handleTouchEnd(e) {
                        if (this.swipeStartX !== null && e.changedTouches.length) {
                            const dx = e.changedTouches[0].clientX - this.swipeStartX;
                            if (Math.abs(dx) > 50) {
                                dx > 0 ? this.prev() : this.next();
                            }
                            this.swipeStartX = null;
                        }
                        this.initialPinchDistance = null;
                        this.isPanning = false;
                        if (this.scale < 1.05) this.resetZoom();
                    },

                    stopLightboxVideo() {
                        const candidates = [];
                        if (this.$refs.lightboxVideo) candidates.push(this.$refs.lightboxVideo);
                        if (this.$root) candidates.push(...this.$root.querySelectorAll('video'));
                        for (const v of candidates) {
                            try { v.pause(); } catch (e) {}
                            try { v.currentTime = 0; } catch (e) {}
                            try { v.removeAttribute('src'); v.load(); } catch (e) {}
                        }
                    },

                    closeLightbox() {
                        this.stopLightboxVideo();
                        this.activeUrl = '';
                        this.resetZoom();
                        $wire.showImageLightbox = false;
                    },

                    toggleVideoPlayback() {
                        if (!this.currentIsVideo) return;
                        const video = this.$refs.lightboxVideo;
                        if (!video) return;

                        if (video.paused) {
                            video.play();
                        } else {
                            video.pause();
                        }
                    }
                }"
                x-ref="zoomContainer"
                x-init="
                    $watch('$wire.showImageLightbox', v => {
                        if (v) {
                            syncFromWire($wire.lightboxImageUrl);
                        } else {
                            stopLightboxVideo();
                            activeUrl = '';
                            resetZoom();
                        }
                    });
                    $watch('$wire.lightboxImageUrl', v => { if (v && $wire.showImageLightbox) syncFromWire(v); });
                "
                @lightbox-images-updated.window="if ($event.detail.images) { images = $event.detail.images; syncFromWire(activeUrl); }"
                :class="(!currentIsVideo && isZoomed) ? 'cursor-grab active:cursor-grabbing' : ''"
                @wheel.prevent="handleWheel($event)"
                @dblclick="handleDoubleTap($event)"
                @mousedown="handleMouseDown($event)"
                @mousemove="handleMouseMove($event)"
                @mouseup="handleMouseUp()"
                @mouseleave="handleMouseUp()"
                @touchstart="handleTouchStart($event)"
                @touchmove="handleTouchMove($event)"
                @touchend="handleTouchEnd($event)"
                @keydown.left.window="$wire.showImageLightbox && !isZoomed && prev()"
                @keydown.right.window="$wire.showImageLightbox && !isZoomed && next()"
                @keydown.escape.window="$wire.showImageLightbox && closeLightbox()"
                @keydown.space.window="if ($wire.showImageLightbox && currentIsVideo) { $event.preventDefault(); toggleVideoPlayback(); }"
                class="relative flex items-center justify-center"
                style="width:96vw;max-width:96vw;height:94vh;max-height:94vh;"
            >
                {{-- Image counter --}}
                <div
                    x-show="hasMultiple && !isZoomed"
                    x-transition.opacity
                    class="absolute bottom-3 left-1/2 -translate-x-1/2 z-20"
                >
                    <div class="bg-black/60 text-white px-2.5 py-1 rounded-full text-xs">
                        <span x-text="(currentIndex + 1) + ' / ' + images.length"></span>
                    </div>
                </div>

                {{-- Zoom indicator --}}
                <div
                    x-show="!currentIsVideo && isZoomed"
                    x-transition.opacity
                    class="absolute top-2 left-1/2 -translate-x-1/2 z-20"
                >
                    <div class="bg-black/60 text-white px-2.5 py-1 rounded-full text-xs flex items-center gap-1.5">
                        <span x-text="Math.round(scale * 100) + '%'"></span>
                        <button @click.stop="resetZoom()" class="hover:text-zinc-300 transition" title="Reset zoom">
                            <svg class="size-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                        </button>
                    </div>
                </div>

                {{-- Media (image or video) --}}
                <div
                    class="relative overflow-hidden rounded-xl select-none flex items-center justify-center"
                    :class="[((!currentIsVideo && isZoomed) ? 'touch-none' : ''), (currentIsVideo ? 'bg-black' : 'bg-transparent')]"
                    :style="currentIsVideo
                        ? 'width:auto;height:auto;max-width:96vw;max-height:94vh;'
                        : 'width:fit-content;height:fit-content;max-width:96vw;max-height:94vh;'"
                >
                    {{-- Close button (on media frame) --}}
                    <button
                        type="button"
                        @click.stop="closeLightbox()"
                        class="absolute right-2 top-2 z-20 rounded-full bg-black/50 p-1.5 text-white/80 hover:bg-black/70 hover:text-white transition"
                        aria-label="Close media preview"
                    >
                        <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                    </button>

                    {{-- Previous button (on media frame) --}}
                    <button
                        x-show="hasMultiple && hasPrev && !(isZoomed && !currentIsVideo)"
                        x-transition.opacity
                        @click.stop="prev()"
                        type="button"
                        class="absolute left-2 top-1/2 -translate-y-1/2 z-20 rounded-full bg-black/50 p-2 text-white/80 hover:bg-black/70 hover:text-white transition"
                        aria-label="Previous media"
                    >
                        <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" /></svg>
                    </button>

                    {{-- Next button (on media frame) --}}
                    <button
                        x-show="hasMultiple && hasNext && !(isZoomed && !currentIsVideo)"
                        x-transition.opacity
                        @click.stop="next()"
                        type="button"
                        class="absolute right-2 top-1/2 -translate-y-1/2 z-20 rounded-full bg-black/50 p-2 text-white/80 hover:bg-black/70 hover:text-white transition"
                        aria-label="Next media"
                    >
                        <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" /></svg>
                    </button>

                    <template x-if="$wire.showImageLightbox && currentIsVideo">
                        <video
                            :key="currentUrl"
                            :src="currentUrl"
                            x-ref="lightboxVideo"
                            controls
                            autoplay
                            preload="metadata"
                            class="block object-contain bg-black"
                            style="display:block;height:90vh;max-height:90vh;width:auto;max-width:96vw;"
                            playsinline
                        ></video>
                    </template>

                    <template x-if="!currentIsVideo">
                        <img
                            :src="currentUrl"
                            alt="MMS attachment"
                            class="block rounded-xl max-h-[80vh] w-auto max-w-full object-contain select-none transition-transform duration-100 ease-out"
                            :style="`max-height:90vh;max-width:96vw;width:auto;transform: scale(${scale}) translate(${translateX / scale}px, ${translateY / scale}px); transform-origin: center center;`"
                            draggable="false"
                        />
                    </template>
                </div>
            </div>
        </flux:modal>

        {{-- Assign Client --}}
        <flux:modal wire:model="showAssignClientModal" class="min-w-[22rem]">
            {{-- Every client/vendor as options (~270KB): render only while open
                 so thread switches don't re-download the lists each time. --}}
            @if($showAssignClientModal)
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">Assign client / vendor</flux:heading>
                    <flux:text class="mt-2">Link this thread to a client or vendor so it appears as a named conversation.</flux:text>
                </div>
                <flux:radio.group wire:model.live="assignSubjectType" variant="segmented" size="sm">
                    <flux:radio value="client" label="Client" icon="user" />
                    <flux:radio value="vendor" label="Vendor" icon="briefcase" />
                </flux:radio.group>
                @if ($assignSubjectType === 'client')
                    <flux:field>
                        <flux:label>Client</flux:label>
                        <flux:select wire:model="assignClientId" variant="listbox" searchable clearable placeholder="No client">
                            @foreach ($this->allClients as $client)
                                <flux:select.option value="{{ $client->id }}">{{ $client->name ?: 'Client #'.$client->id }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </flux:field>
                @else
                    <flux:field>
                        <flux:label>Vendor</flux:label>
                        <flux:select wire:model="assignVendorId" variant="listbox" searchable clearable placeholder="No vendor">
                            @foreach ($this->allVendors as $vendor)
                                <flux:select.option value="{{ $vendor->id }}">{{ $vendor->short_name ?: $vendor->name }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </flux:field>
                @endif
                <div class="flex gap-2">
                    <flux:spacer />
                    <flux:modal.close>
                        <flux:button variant="ghost">Cancel</flux:button>
                    </flux:modal.close>
                    <flux:button variant="primary" wire:click="assignClient">Save</flux:button>
                </div>
            </div>
        @endif
        </flux:modal>

        {{-- Delete Thread Confirmation --}}
        <flux:modal wire:model.self="showDeleteConfirm" class="min-w-[22rem]">
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">Delete thread?</flux:heading>
                    <flux:text class="mt-2">
                        This will permanently delete this conversation and all its messages. This action cannot be undone.
                    </flux:text>
                </div>
                <div class="flex gap-2">
                    <flux:spacer />
                    <flux:modal.close>
                        <flux:button variant="ghost">Cancel</flux:button>
                    </flux:modal.close>
                    <flux:button variant="danger" wire:click="deleteThread">Delete</flux:button>
                </div>
            </div>
        </flux:modal>

        <flux:modal wire:model="showCancelModal" class="min-w-[22rem]">
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">Cancel scheduled message?</flux:heading>
                    <flux:text class="mt-2">
                        This message will not be sent. This action cannot be undone.
                    </flux:text>
                </div>
                <div class="flex gap-2">
                    <flux:spacer />
                    <flux:button variant="ghost" wire:click="$set('showCancelModal', false)">Keep</flux:button>
                    <flux:button variant="danger" wire:click="cancelScheduledMessage">Cancel message</flux:button>
                </div>
            </div>
        </flux:modal>

    @else
        {{-- No thread selected on the server. Use template x-if so only one branch
             ever exists in the DOM — avoids any flash of both states together.
             Note: composer is rendered OUTSIDE this @if/@else (below) so it
             persists across thread switches and is visible immediately. --}}
        <template x-if="switching || $store.sms.threadId">
            <div class="flex-1 min-h-0 flex flex-col">
                <div class="sms-mobile-header-offset border-b border-zinc-200 dark:border-zinc-700 py-2">
                    <div class="flex items-center gap-1.5">
                        <flux:button
                            type="button"
                            variant="subtle"
                            size="sm"
                            square
                            icon="arrow-left"
                            class="lg:hidden shrink-0"
                            x-on:click="
                                $store.sms.threadId = null;
                                switching = false;
                                window.dispatchEvent(new CustomEvent('thread-selected', { detail: { threadId: null } }));
                                $wire.$parent.clearThread();
                            "
                            aria-label="Back to conversations"
                        ></flux:button>
                        <div class="h-5 w-40 rounded animate-pulse bg-zinc-200/70 dark:bg-zinc-700/50"></div>
                    </div>
                </div>
                <div class="relative flex-1 min-h-0">
                    @include('livewire.sms.conversation_placeholder')
                </div>
            </div>
        </template>
        <template x-if="!switching && !$store.sms.threadId">
            <div class="flex flex-1 items-center justify-center">
                <div class="text-center">
                    <flux:icon name="chat-bubble-left-right" class="mx-auto h-12 w-12 text-zinc-300 dark:text-zinc-600" />
                    <h3 class="mt-3 text-base lg:text-sm font-medium text-zinc-500 dark:text-zinc-400">No conversation selected</h3>
                    <p class="mt-1 text-sm lg:text-xs text-zinc-400 dark:text-zinc-500">Select a conversation or start a new one.</p>
                </div>
            </div>
        </template>
    @endif
    @endisland

    {{-- Composer (always rendered, persists across thread switches).
         The textarea DOM node is created once and survives every loadThread
         event because SmsConversation is #[Isolate]. --}}
    @island(name: 'sms-conversation-composer', always: true)
    @if ($isClientUser)
        <div class="shrink-0 px-1 pb-1">
            <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800/50 px-4 py-3 text-base lg:text-sm text-zinc-500 dark:text-zinc-400 text-center">
                Homeowners are not able to message here yet. Please message us on your phone messaging app.
            </div>
        </div>
    @else
        @php
            $pendingOptIn = $this->thread ? $this->thread->hasPendingOptIn() : false;
            $composerDisabled = ! $this->thread || $pendingOptIn;
        @endphp
        <div class="shrink-0 px-1 pb-1" wire:key="sms-composer-{{ $threadId ?? 'none' }}"
            x-data="{
                async submitComposer(form) {
                    // Optimistic clear — the box empties the instant you hit
                    // send, like iMessage. Only the VISIBLE box is cleared
                    // (via ui-composer, which owns the inner textarea); the
                    // draft stays in the Livewire store, so:
                    //  - success → server empties newMessage → box stays clear
                    //  - failure → Livewire re-syncs the box from the store,
                    //    restoring the draft automatically.
                    // The text also rides along as an argument so the send is
                    // immune to any store race.
                    const text = $wire.newMessage ?? '';
                    const uc = form.querySelector('ui-composer');
                    if (uc) uc.value = '';

                    try {
                        await $wire.sendMessage(text);
                    } catch (e) {
                        // Belt-and-braces: if the round trip died and the sync
                        // didn't happen, put the draft back by hand.
                        if (uc && ! uc.value && $wire.newMessage) uc.value = $wire.newMessage;
                    }
                }
            }">
            <form x-on:submit.prevent="submitComposer($el)">
                @if ($attachment && method_exists($attachment, 'temporaryUrl') && $attachment->getRealPath())
                    <div class="mb-2 px-1">
                        @php
                            $attachmentMimeType = (string) $attachment->getMimeType();
                            $isVideoAttachment = str_starts_with($attachmentMimeType, 'video/');
                        @endphp
                        <div class="relative inline-block border border-zinc-200 dark:border-zinc-700 rounded-lg overflow-hidden">
                            @if ($isVideoAttachment)
                                <video src="{{ $attachment->temporaryUrl() }}" class="size-16 object-cover bg-black" muted playsinline preload="metadata"></video>
                            @else
                                <img src="{{ $attachment->temporaryUrl() }}" alt="Attachment preview" class="size-16 object-cover" />
                            @endif
                            <div class="absolute top-0 right-0 p-0.5">
                                <button type="button" wire:click="removeAttachment" class="p-0.5 rounded-full bg-zinc-900/50 hover:bg-zinc-900/70 flex justify-center items-center">
                                    <flux:icon icon="x-mark" variant="micro" class="text-white" />
                                </button>
                            </div>
                        </div>
                    </div>
                @endif

                <flux:composer
                    wire:model="newMessage"
                    placeholder="Type a message..."
                    label="Message"
                    label:sr-only
                    rows="2"
                    max-rows="6"
                >
                    <x-slot name="actionsLeading">
                        <flux:button type="button" size="sm" variant="subtle" square icon="paper-clip" x-on:click="$refs.fileInput.click()" aria-label="Attach media" x-bind:disabled="!! $wire.editScheduledId || @js($composerDisabled)"></flux:button>
                        <input x-ref="fileInput" type="file" wire:model="attachment" accept="image/*,video/*,.mp4,.mov,.webm,.m4v,.3gp,.avi" class="hidden" />
                        <div x-show="! $wire.editScheduledId" x-cloak>
                            <flux:button type="button" size="sm" variant="subtle" square icon="calendar-days" wire:click="$dispatchTo('sms.send-schedule-modal', 'openScheduleModal', { threadId: {{ $threadId ?? 'null' }} })" tooltip="Send schedule" aria-label="Send schedule" :disabled="$composerDisabled"></flux:button>
                        </div>
                    </x-slot>

                    <x-slot name="actionsTrailing">
                        <div x-show="! $wire.editScheduledId" x-cloak>
                            <flux:dropdown position="top end">
                                <flux:button size="sm" variant="subtle" square icon="clock" aria-label="Schedule send" :disabled="$composerDisabled"></flux:button>

                                <flux:menu>
                                    <flux:heading size="xs" class="px-2 pb-1">Schedule send</flux:heading>
                                    <flux:menu.item wire:click="scheduleMessage('1hr')" icon="clock">In 1 hour</flux:menu.item>
                                    <flux:menu.item wire:click="scheduleMessage('2hr')" icon="clock">In 2 hours</flux:menu.item>
                                    <flux:menu.item wire:click="scheduleMessage('schedule_only')" icon="bookmark">Schedule Only (manual send)</flux:menu.item>
                                    <flux:separator />
                                    <flux:menu.item wire:click="scheduleMessage('tomorrow_8am')" icon="sun">Tomorrow 8:00 AM</flux:menu.item>
                                    <flux:menu.item wire:click="scheduleMessage('tomorrow_12pm')" icon="sun">Tomorrow 12:00 PM</flux:menu.item>
                                </flux:menu>
                            </flux:dropdown>
                        </div>

                        <flux:button type="submit" x-show="! $wire.editScheduledId" x-cloak size="sm" variant="primary" square icon="paper-airplane" class="data-loading:opacity-50" :disabled="$composerDisabled" aria-label="Send message" tooltip="Send message"></flux:button>
                        <flux:button type="submit" x-show="!! $wire.editScheduledId" x-cloak size="sm" variant="primary" square icon="check" class="data-loading:opacity-50" :disabled="$composerDisabled" aria-label="Save Draft" tooltip="Save Draft"></flux:button>
                    </x-slot>
                </flux:composer>
            </form>

            @error('newMessage')
                <p class="text-xs text-red-500 mt-1 px-1">{{ $message }}</p>
            @enderror
            @error('attachment')
                <p class="text-xs text-red-500 mt-1 px-1">{{ $message }}</p>
            @enderror

            @if ($pendingOptIn)
                <div class="flex items-center justify-end gap-2 px-2 pt-1">
                    <flux:icon name="exclamation-triangle" class="size-4 text-amber-500" />
                    <flux:text class="text-xs text-amber-600 dark:text-amber-400">Awaiting START reply</flux:text>
                </div>
            @endif
        </div>
    @endif
    @endisland
</div>

@script
<script>
    const container = $wire.$el.querySelector('.sms-messages');
    if (container) {
        // flex-col-reverse: scrollTop 0 = bottom (newest). Ensure we start there.
        container.scrollTop = 0;

        let lastCount = container.children.length;
        new MutationObserver(() => {
            const newCount = container.children.length;
            if (newCount !== lastCount) {
                lastCount = newCount;
                // With flex-col-reverse, scrollTop 0 = newest messages (bottom)
                container.scrollTop = 0;
            }
        }).observe(container, { childList: true });
    }
</script>
@endscript
