<div
    class="flex-1 min-h-0 flex flex-col"
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
    "
    x-on:thread-ready.window="
        clearTimeout(switchingTimer);
        $nextTick(() => { switching = false });
    "
>
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

        {{-- Header --}}
        @php
            $participantPhones = $this->thread->threadParticipants->pluck('phone_number');
            $headerTitle = 'Group Message';
            if ($this->thread->name) {
                $headerTitle = $this->thread->name;
            } elseif ($this->thread->client && $participantPhones->count() < $this->thread->client->users->count()) {
                $headerTitle = $participantPhones
                    ->map(fn ($p) => $this->resolvePhoneDisplay($p))
                    ->implode(', ');
            } elseif ($this->thread->client) {
                $headerTitle = $this->thread->client->name;
            } elseif ($this->thread->subjectVendor) {
                $headerTitle = $this->thread->subjectVendor->short_name ?: $this->thread->subjectVendor->name;
            } elseif ($this->thread->project) {
                $headerTitle = $this->thread->project->address;
            } else {
                if ($participantPhones->isNotEmpty()) {
                    $headerTitle = $participantPhones
                        ->map(fn ($p) => $this->resolvePhoneDisplay($p))
                        ->implode(', ');
                }
            }
        @endphp
        <div
            class="border-b border-zinc-200 dark:border-zinc-700 px-4 py-2"
            x-on:touchstart.passive="onPullStart($event)"
            x-on:touchmove="onPullMove($event)"
            x-on:touchend="onPullEnd()"
        >
            {{-- Title row --}}
            <div class="flex items-center gap-1.5" style="padding-left: 2rem" x-bind:style="window.innerWidth >= 1024 ? 'padding-left: 0' : 'padding-left: 2rem'">
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
                @if (! empty($this->thread->name_data))
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
                @elseif ($this->thread->client)
                    <flux:heading size="lg" class="mb-0 truncate flex-1">
                        <a href="{{ route('clients.show', $this->thread->client_id) }}" wire:navigate.hover class="hover:underline">{{ $headerTitle }}</a>
                    </flux:heading>
                @elseif ($this->thread->subjectVendor)
                    <flux:heading size="lg" class="mb-0 truncate flex-1">
                        <a href="{{ route('vendors.show', $this->thread->subject_vendor_id) }}" wire:navigate.hover class="hover:underline">{{ $headerTitle }}</a>
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

                    // Fallback: if still no contacts, use raw participants
                    if ($callableContacts->isEmpty()) {
                        foreach ($participantPhones as $phone) {
                            if (\App\Services\GroupSmsService::isOurNumber($phone)) continue;
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
                                'name' => $name !== $displayPhone ? $name : null,
                                'e164' => $phone,
                                'display' => $displayPhone,
                            ]);
                        }
                    }
                @endphp

                @if ($callableContacts->isNotEmpty())
                    @if ($callableContacts->count() > 1)
                        <div class="mt-1 flex justify-end">
                            <flux:button
                                type="button"
                                size="xs"
                                variant="subtle"
                                icon="phone"
                                wire:click="initiateCallAll"
                                wire:loading.attr="disabled"
                                wire:target="initiateCallAll"
                            >
                                Call All ({{ $callableContacts->count() }})
                            </flux:button>
                        </div>
                    @endif

                    <div class="flex flex-wrap gap-x-4 gap-y-0.5 mt-1">
                        @foreach ($callableContacts as $contact)
                            <div class="flex items-center gap-1.5">
                                @if ($contact['name'] && $contact['name'] !== $headerTitle)
                                    <span class="text-sm lg:text-xs font-medium text-zinc-600 dark:text-zinc-300">{{ $contact['name'] }}</span>
                                @endif
                                <button
                                    type="button"
                                    wire:click="initiateCall('{{ $contact['e164'] }}')"
                                    wire:loading.attr="disabled"
                                    wire:target="initiateCall"
                                    class="inline-flex items-center gap-1 text-sm lg:text-xs text-indigo-500 hover:text-indigo-600 dark:text-indigo-400 hover:underline cursor-pointer disabled:opacity-50"
                                    title="Call {{ $contact['name'] ?? $contact['display'] }} via your phone"
                                >
                                    <flux:icon name="phone" class="size-3" />
                                    {{ $contact['display'] }}
                                </button>
                            </div>
                        @endforeach
                    </div>
                @endif
            @endif
        </div>

        {{-- Messages --}}
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
        @endphp

        <div class="relative flex-1 min-h-0">
            {{-- Instant-paint overlay during thread switching: cached snapshot first,
                 placeholder skeleton if no cache yet. --}}
            <div
                x-show="switching"
                x-cloak
                x-transition:enter="transition-opacity ease-out duration-150"
                x-transition:enter-start="opacity-0"
                x-transition:enter-end="opacity-100"
                x-transition:leave="transition-opacity ease-in duration-200"
                x-transition:leave-start="opacity-100"
                x-transition:leave-end="opacity-0"
                x-data="{
                    cachedMessages: [],
                    loadCachedSnapshot(id) {
                        if (!id) { this.cachedMessages = []; return; }
                        try {
                            const raw = localStorage.getItem('hive-sms-thread-' + id);
                            this.cachedMessages = raw ? JSON.parse(raw) : [];
                        } catch (e) { this.cachedMessages = []; }
                    },
                    fmt(iso) {
                        if (!iso) return '';
                        try { return new Date(iso).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }); }
                        catch(e) { return ''; }
                    }
                }"
                x-init="loadCachedSnapshot({{ $this->threadId }})"
                x-on:thread-switching.window="loadCachedSnapshot($event.detail?.threadId || $wire.threadId)"
                class="absolute inset-0 z-10"
            >
                <template x-if="cachedMessages.filter(m => (m.text || '').trim().length > 0).length >= 2">
                    <div class="h-full overflow-hidden flex flex-col-reverse gap-3 px-2 pt-6 pb-6">
                        <template x-for="msg in [...cachedMessages].filter(m => (m.text || '').trim().length > 0).reverse()" :key="'cache-' + msg.id">
                            <div x-bind:class="msg.direction === 'outbound' ? 'flex justify-end' : 'flex justify-start'">
                                <div class="max-w-[85%] lg:max-w-[75%]">
                                    <div x-bind:class="msg.direction === 'outbound'
                                            ? 'bg-indigo-500 text-white rounded-2xl rounded-br-sm'
                                            : 'bg-zinc-200 dark:bg-zinc-700 text-zinc-900 dark:text-zinc-100 rounded-2xl rounded-bl-sm'"
                                         class="px-3 py-2 text-sm whitespace-pre-wrap break-words" x-text="msg.text"></div>
                                    <p class="text-[10px] text-zinc-400 mt-0.5"
                                       x-bind:class="msg.direction === 'outbound' ? 'text-right pr-1' : 'pl-1'"
                                       x-text="fmt(msg.created_at)"></p>
                                </div>
                            </div>
                        </template>
                    </div>
                </template>
                <template x-if="cachedMessages.filter(m => (m.text || '').trim().length > 0).length < 2">
                    <div class="h-full flex flex-col">@include('livewire.sms.conversation_placeholder')</div>
                </template>
            </div>

            <div class="sms-fade-overlay top"></div>
        <div
            class="sms-messages h-full overflow-y-auto flex flex-col-reverse gap-3 px-2 pt-6 pb-6"
            x-show="!switching"
            x-transition:enter="transition-opacity ease-out duration-200 delay-75"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
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
            {{-- Scheduled messages always at bottom (first in DOM due to flex-col-reverse) --}}
            @foreach ($scheduledMessages as $msg)
                <div wire:key="msg-{{ $msg->id }}" class="flex justify-end">
                    <div class="max-w-[85%] lg:max-w-[75%] order-last">
                        <p class="text-xs lg:text-[10px] text-zinc-400 dark:text-zinc-500 mb-0.5 px-1 text-right">
                            {{ $msg->sentByUser?->first_name ?? 'GS Crew' }}
                        </p>

                        <div class="relative">
                            <div class="mb-1.5 flex items-center justify-end gap-1">
                                <flux:badge color="amber" size="sm" icon="clock">Scheduled &middot; {{ $msg->scheduled_at->timezone('America/Chicago')->format('M j, g:i A') }}</flux:badge>
                                <flux:button size="xs" variant="primary" square icon="paper-airplane" wire:click="sendScheduledNow({{ $msg->id }})" tooltip="Send now" aria-label="Send now"></flux:button>
                                <flux:button size="xs" variant="danger" square icon="x-mark" wire:click="$set('cancelScheduledId', {{ $msg->id }}); $set('showCancelModal', true)" tooltip="Cancel" aria-label="Cancel scheduled message"></flux:button>
                            </div>

                            <div class="rounded-2xl px-3.5 py-2 text-base lg:text-sm break-words bg-indigo-600/50 text-white/80 rounded-br-md">
                                @if ($msg->hasMedia())
                                    <div class="space-y-2 {{ $msg->text ? 'mb-1.5' : '' }}">
                                        @foreach ($msg->media_urls as $url)
                                            @php $mediaUrl = $this->mediaUrl($url); @endphp
                                            @if (\App\Models\SmsMessage::isVideoUrl($url))
                                                <button type="button" class="block relative" wire:click="openVideoLightbox('{{ $url }}')">
                                                    <video preload="metadata" class="max-w-full rounded-lg max-h-64 bg-black pointer-events-none" playsinline>
                                                        <source src="{{ $mediaUrl }}" @if ($mime = \App\Models\SmsMessage::mimeForUrl($url)) type="{{ $mime }}" @endif />
                                                        Your browser does not support the video tag.
                                                    </video>
                                                    <span class="absolute inset-0 flex items-center justify-center">
                                                        <span class="inline-flex items-center justify-center size-12 rounded-full bg-black/50 text-white">
                                                            <svg class="size-6" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                                                <path d="M8 5v14l11-7-11-7z" />
                                                            </svg>
                                                        </span>
                                                    </span>
                                                </button>
                                            @elseif (\App\Models\SmsMessage::isAudioUrl($url))
                                                <audio controls preload="metadata" class="max-w-full">
                                                    <source src="{{ $mediaUrl }}" @if ($mime = \App\Models\SmsMessage::mimeForUrl($url)) type="{{ $mime }}" @endif />
                                                </audio>
                                            @else
                                                <button type="button" class="block" wire:click="openImageLightbox('{{ $url }}')">
                                                    <img src="{{ $mediaUrl }}" alt="MMS attachment" class="max-w-full rounded-lg max-h-64 object-cover" loading="lazy" />
                                                </button>
                                            @endif
                                        @endforeach
                                    </div>
                                @endif
                                @if ($msg->display_text)
                                    {!! preg_replace(
                                        '/(https?:\/\/[^\s<]+)/',
                                        '<a href="$1" target="_blank" class="underline text-indigo-100 hover:text-white">$1</a>',
                                        nl2br(e($msg->display_text))
                                    ) !!}
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach

            @forelse ($visibleMessages->reverse() as $msg)
                @if ($loop->last && $this->smsMessages->count() >= $messageLimit)
                    <div data-load-more class="text-center py-2">
                        <span wire:loading wire:target="loadMoreMessages" class="text-xs text-zinc-400">Loading...</span>
                    </div>
                @endif
                @php
                    $msgLocal = $msg->created_at->copy()->setTimezone($tz);
                    $msgDate = $msgLocal->toDateString();
                    if ($msgDate === $todayDate) {
                        $timeLabel = $msgLocal->format('g:i A');
                    } elseif ($msgDate === $yesterdayDate) {
                        $timeLabel = 'Yesterday ' . $msgLocal->format('g:i A');
                    } elseif ($msgLocal->year !== $now->year) {
                        $timeLabel = $msgLocal->format('M j, Y, g:i A');
                    } else {
                        $timeLabel = $msgLocal->format('M j, g:i A');
                    }
                    $msgReactions = $reactionsMap[$msg->id] ?? [];
                @endphp
                <div wire:key="msg-{{ $msg->id }}" class="flex {{ $msg->isOutbound() ? 'justify-end' : 'justify-start' }}">
                    <div class="max-w-[85%] lg:max-w-[75%] {{ $msg->isOutbound() ? 'order-last' : '' }}">
                        @if ($msg->isInbound())
                            <p class="text-xs lg:text-[10px] text-zinc-400 dark:text-zinc-500 mb-0.5 px-1">
                                {{ $phoneNameMap[$msg->from_number] ?? $msg->from_number }}
                            </p>
                        @elseif ($msg->isOutbound())
                            <p class="text-xs lg:text-[10px] text-zinc-400 dark:text-zinc-500 mb-0.5 px-1 text-right">
                                {{ $msg->sentByUser?->first_name ?? 'GS Crew' }}
                            </p>
                        @endif

                        <div class="relative">
                            <div class="rounded-2xl px-3.5 py-2 text-base lg:text-sm break-words {{ $msg->isOutbound()
                                ? 'bg-indigo-600 text-white rounded-br-md'
                                : 'bg-zinc-200 dark:bg-zinc-700 text-zinc-900 dark:text-zinc-100 rounded-bl-md' }}">
                                @if ($msg->hasMedia())
                                    <div class="space-y-2 {{ $msg->text ? 'mb-1.5' : '' }}">
                                        @foreach ($msg->media_urls as $url)
                                            @php $mediaUrl = $this->mediaUrl($url); @endphp
                                            @if (\App\Models\SmsMessage::isVideoUrl($url))
                                                <button type="button" class="block relative" wire:click="openVideoLightbox('{{ $url }}')">
                                                    <video preload="metadata" class="max-w-full rounded-lg max-h-64 bg-black pointer-events-none" playsinline>
                                                        <source src="{{ $mediaUrl }}" @if ($mime = \App\Models\SmsMessage::mimeForUrl($url)) type="{{ $mime }}" @endif />
                                                        Your browser does not support the video tag.
                                                    </video>
                                                    <span class="absolute inset-0 flex items-center justify-center">
                                                        <span class="inline-flex items-center justify-center size-12 rounded-full bg-black/50 text-white">
                                                            <svg class="size-6" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                                                <path d="M8 5v14l11-7-11-7z" />
                                                            </svg>
                                                        </span>
                                                    </span>
                                                </button>
                                            @elseif (\App\Models\SmsMessage::isAudioUrl($url))
                                                <audio controls preload="metadata" class="max-w-full">
                                                    <source src="{{ $mediaUrl }}" @if ($mime = \App\Models\SmsMessage::mimeForUrl($url)) type="{{ $mime }}" @endif />
                                                </audio>
                                            @else
                                                <button
                                                    type="button"
                                                    class="block"
                                                    wire:click="openImageLightbox('{{ $url }}')"
                                                >
                                                    <img
                                                        src="{{ $mediaUrl }}"
                                                        alt="MMS attachment"
                                                        class="max-w-full rounded-lg max-h-64 object-cover"
                                                        loading="lazy"
                                                        onerror="this.parentElement.innerHTML='<div class=\'flex items-center gap-1.5 py-2 text-sm opacity-75\'><svg xmlns=\'http://www.w3.org/2000/svg\' class=\'size-4\' viewBox=\'0 0 20 20\' fill=\'currentColor\'><path fill-rule=\'evenodd\' d=\'M1 5.25A2.25 2.25 0 0 1 3.25 3h13.5A2.25 2.25 0 0 1 19 5.25v9.5A2.25 2.25 0 0 1 16.75 17H3.25A2.25 2.25 0 0 1 1 14.75v-9.5Zm1.5 5.81v3.69c0 .414.336.75.75.75h13.5a.75.75 0 0 0 .75-.75v-2.69l-2.22-2.219a.75.75 0 0 0-1.06 0l-1.91 1.909-4.97-4.969a.75.75 0 0 0-1.06 0L2.5 11.06Zm12.5-2.56a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Z\' clip-rule=\'evenodd\'/></svg> Image unavailable</div>'"
                                                    />
                                                </button>
                                            @endif
                                        @endforeach
                                    </div>
                                @endif
                                @if ($msg->display_text)
                                    {!! preg_replace(
                                        '/(https?:\/\/[^\s<]+)/',
                                        '<a href="$1" target="_blank" class="underline ' . ($msg->isOutbound() ? 'text-indigo-100 hover:text-white' : 'text-indigo-600 hover:text-indigo-700 dark:text-indigo-400 dark:hover:text-indigo-300') . '">$1</a>',
                                        nl2br(e($msg->display_text))
                                    ) !!}
                                @endif
                            </div>

                            {{-- Tapback reactions --}}
                            @if (! empty($msgReactions))
                                <div class="flex gap-0.5 {{ $msg->isOutbound() ? 'justify-end -mr-1' : '-ml-1' }} -mt-2 relative z-10">
                                    @foreach ($msgReactions as $emoji => $senders)
                                        <span
                                            class="inline-flex items-center gap-0.5 rounded-full bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-600 px-1 py-0.5 text-xs shadow-sm"
                                            title="{{ implode(', ', $senders) }}"
                                        >
                                            {{ $emoji }}@if (count($senders) > 1)<span class="text-[10px] text-zinc-500">{{ count($senders) }}</span>@endif
                                        </span>
                                    @endforeach
                                </div>
                            @endif
                        </div>

                        <p class="text-xs lg:text-[10px] text-zinc-400 dark:text-zinc-500 mt-0.5 {{ $msg->isOutbound() ? 'text-right' : '' }} px-1">
                            {{ $timeLabel }}
                            @if ($this->threadHasMixedNumbers)
                                @php
                                    $numbers = config('services.telnyx.numbers', []);
                                    $primaryNumber = config('services.telnyx.from');
                                    $msgNumber = $msg->isOutbound()
                                        ? $msg->from_number
                                        : collect($msg->to_numbers)->first(fn ($n) => in_array($n, $numbers));
                                @endphp
                                @if ($msgNumber && $msgNumber !== $primaryNumber)
                                    <span class="text-zinc-400/70 dark:text-zinc-500/70">&middot; {{ substr($msgNumber, -4) }}</span>
                                @endif
                            @endif
                        </p>
                    </div>
                </div>
            @empty
                <div class="text-center py-12">
                    <p class="text-sm text-zinc-400 dark:text-zinc-500">No messages yet. Send the first one!</p>
                </div>
            @endforelse
        </div>
            <div class="sms-fade-overlay bottom"></div>
        </div>

        {{-- Compose --}}
        <div class="shrink-0 px-1 pb-1">
            @if ($isClientUser)
                <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800/50 px-4 py-3 text-base lg:text-sm text-zinc-500 dark:text-zinc-400 text-center">
                    Homeowners are not able to message here yet. Please message us on your phone messaging app.
                </div>
            @else
            @php
                $pendingOptIn = $this->thread->hasPendingOptIn();
            @endphp
            <form wire:submit="sendMessage"
                x-data="{
                    draftKey: 'sms-draft-' + {{ $threadId }},
                    init() {
                        const saved = localStorage.getItem(this.draftKey);
                        if (saved) {
                            $wire.set('newMessage', saved);
                            this.$nextTick(() => {
                                const ta = this.$el.querySelector('textarea');
                                if (ta) ta.dispatchEvent(new Event('input', { bubbles: true }));
                            });
                        }
                    },
                    saveDraft(e) {
                        localStorage.setItem(this.draftKey, e.target.value);
                    },
                }"
            >
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
                    x-on:input="saveDraft($event)"
                >
                    <x-slot name="actionsLeading">
                        <flux:button type="button" size="sm" variant="subtle" square icon="paper-clip" x-on:click="$refs.fileInput.click()" aria-label="Attach media"></flux:button>
                        <input x-ref="fileInput" type="file" wire:model="attachment" accept="image/*,video/*,.mp4,.mov,.webm,.m4v,.3gp,.avi" class="hidden" />
                        @if ($this->thread)
                            <flux:button type="button" size="sm" variant="subtle" square icon="calendar-days" wire:click="$dispatchTo('sms.send-schedule-modal', 'openScheduleModal', { threadId: {{ $threadId }} })" tooltip="Send schedule" aria-label="Send schedule"></flux:button>
                        @endif
                    </x-slot>

                    <x-slot name="actionsTrailing">
                        <flux:dropdown position="top end">
                            <flux:button size="sm" variant="subtle" square icon="clock" aria-label="Schedule send" :disabled="$pendingOptIn"></flux:button>

                            <flux:menu>
                                <flux:heading size="xs" class="px-2 pb-1">Schedule send</flux:heading>
                                <flux:menu.item wire:click="scheduleMessage('1hr')" icon="clock">In 1 hour</flux:menu.item>
                                <flux:menu.item wire:click="scheduleMessage('2hr')" icon="clock">In 2 hours</flux:menu.item>
                                <flux:separator />
                                <flux:menu.item wire:click="scheduleMessage('tomorrow_8am')" icon="sun">Tomorrow 8:00 AM</flux:menu.item>
                                <flux:menu.item wire:click="scheduleMessage('tomorrow_12pm')" icon="sun">Tomorrow 12:00 PM</flux:menu.item>
                            </flux:menu>
                        </flux:dropdown>

                        <flux:button type="submit" size="sm" variant="primary" square icon="paper-airplane" class="data-loading:opacity-50" :disabled="$pendingOptIn" aria-label="Send message"></flux:button>
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
            @endif
        </div>

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
            style="width:auto;max-width:96vw;height:auto;max-height:94vh;padding:0;"
        >
            <div
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
                class="relative"
                style="width:auto;max-width:96vw;height:auto;max-height:94vh;"
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
        {{-- No thread selected — but if we're mid-switch (just tapped a thread on mobile)
             show a skeleton header + bubbles to mirror the loaded layout, preventing
             a visual height shift when the server response swaps in the real thread. --}}
        <template x-if="switching">
            <div class="flex-1 min-h-0 flex flex-col">
                {{-- Skeleton header --}}
                <div class="border-b border-zinc-200 dark:border-zinc-700 px-4 py-2">
                    <div class="flex items-center gap-1.5" style="padding-left: 2rem" x-bind:style="window.innerWidth >= 1024 ? 'padding-left: 0' : 'padding-left: 2rem'">
                        <div class="lg:hidden size-8 rounded-md bg-zinc-200/60 dark:bg-zinc-700/40 animate-pulse"></div>
                        <div class="h-5 w-40 rounded bg-zinc-200/70 dark:bg-zinc-700/50 animate-pulse"></div>
                    </div>
                    <div class="h-3 w-24 mt-2 rounded bg-zinc-200/50 dark:bg-zinc-700/30 animate-pulse"></div>
                </div>
                {{-- Skeleton bubbles, full height --}}
                <div class="flex-1 min-h-0 flex flex-col">
                    @include('livewire.sms.conversation_placeholder')
                </div>
            </div>
        </template>
        <div x-show="!switching" class="flex flex-1 items-center justify-center">
            <div class="text-center">
                <flux:icon name="chat-bubble-left-right" class="mx-auto h-12 w-12 text-zinc-300 dark:text-zinc-600" />
                <h3 class="mt-3 text-base lg:text-sm font-medium text-zinc-500 dark:text-zinc-400">No conversation selected</h3>
                <p class="mt-1 text-sm lg:text-xs text-zinc-400 dark:text-zinc-500">Select a conversation or start a new one.</p>
            </div>
        </div>
    @endif
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
