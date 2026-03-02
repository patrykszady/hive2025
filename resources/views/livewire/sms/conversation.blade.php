<div class="flex-1 min-h-0 flex flex-col">
    @if ($this->thread)
        {{-- Header --}}
        @php
            $headerTitle = 'Group Message';
            if ($this->thread->client) {
                $headerTitle = $this->thread->client->name;
            } elseif ($this->thread->project) {
                $headerTitle = $this->thread->project->address;
            } else {
                $participants = $this->thread->participants ?? [];
                if (count($participants) > 0) {
                    $headerTitle = collect($participants)
                        ->map(fn ($p) => $this->resolvePhoneDisplay($p))
                        ->implode(', ');
                }
            }
        @endphp
        <div class="border-b border-zinc-200 dark:border-zinc-700 px-4 py-2">
            {{-- Title row --}}
            <div class="flex items-center gap-1.5" style="padding-left: 2rem" x-bind:style="window.innerWidth >= 1024 ? 'padding-left: 0' : 'padding-left: 2rem'">
                {{-- Mobile back button --}}
                <flux:button
                    type="button"
                    variant="subtle"
                    size="sm"
                    square
                    icon="arrow-left"
                    class="lg:hidden shrink-0"
                    wire:click="$parent.set('threadId', null)"
                    aria-label="Back to conversations"
                ></flux:button>
                @if ($this->thread->client)
                    <flux:heading size="lg" class="mb-0 truncate flex-1">
                        <a href="{{ route('clients.show', $this->thread->client_id) }}" wire:navigate.hover class="hover:underline">{{ $headerTitle }}</a>
                    </flux:heading>
                @else
                    <flux:heading size="lg" class="mb-0 truncate flex-1">{{ $headerTitle }}</flux:heading>
                @endif
                @if ($this->thread->project)
                    <flux:button size="sm" variant="ghost" href="{{ route('projects.show', $this->thread->project_id) }}" wire:navigate.hover icon="arrow-top-right-on-square">
                        Project
                    </flux:button>
                @endif
            </div>

            @if (! $isClientUser)
                @php
                    $callableContacts = collect();

                    if ($this->thread->client && $this->thread->client->users->isNotEmpty()) {
                        foreach ($this->thread->client->users as $user) {
                            $raw = $user->getRawOriginal('cell_phone');
                            if (! $raw) continue;
                            $digits = preg_replace('/[^0-9]/', '', $raw);
                            if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
                                $digits = substr($digits, 1);
                            }
                            $display = strlen($digits) === 10
                                ? '(' . substr($digits, 0, 3) . ') ' . substr($digits, 3, 3) . '-' . substr($digits, 6)
                                : $raw;
                            $callableContacts->push([
                                'name' => $user->first_name,
                                'e164' => $user->routeNotificationForTelnyx(),
                                'display' => $display,
                            ]);
                        }
                    } else {
                        $participants = $this->thread->participants ?? [];
                        foreach ($participants as $phone) {
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

                {{-- Active call bar with "Add to Call" --}}
                @if ($activeCallLogId)
                    <div class="relative flex items-center gap-2 mt-1.5 px-2 py-1.5 rounded-lg bg-green-50 dark:bg-green-900/30 border border-green-200 dark:border-green-800" x-data="{ showInvite: false }">
                        <div class="flex items-center gap-1.5 text-green-700 dark:text-green-400">
                            <span class="relative flex size-2">
                                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-green-400 opacity-75"></span>
                                <span class="relative inline-flex rounded-full size-2 bg-green-500"></span>
                            </span>
                            <span class="text-xs font-medium">On Call</span>
                        </div>

                        <div class="relative ml-auto flex items-center gap-1">
                            <button
                                type="button"
                                @click="showInvite = !showInvite"
                                class="inline-flex items-center gap-1 text-xs font-medium text-green-700 dark:text-green-400 hover:text-green-800 dark:hover:text-green-300 cursor-pointer"
                            >
                                <flux:icon name="user-plus" class="size-3.5" />
                                Add to Call
                            </button>

                            <button
                                type="button"
                                wire:click="clearActiveCall"
                                class="inline-flex items-center text-xs text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300 cursor-pointer ml-2"
                                title="Dismiss"
                            >
                                <flux:icon name="x-mark" class="size-3.5" />
                            </button>
                        </div>

                        {{-- Invite dropdown --}}
                        <div
                            x-show="showInvite"
                            x-transition
                            @click.away="showInvite = false"
                            class="absolute right-0 top-full mt-1 z-50 w-64 rounded-lg bg-white dark:bg-zinc-800 shadow-lg ring-1 ring-zinc-200 dark:ring-zinc-700 py-1"
                        >
                            <div class="px-3 py-1.5 text-xs font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">Add to Call</div>
                            @forelse ($this->conferenceInvitableContacts as $invite)
                                <button
                                    type="button"
                                    wire:click="inviteToConference('{{ $invite['e164'] }}')"
                                    wire:loading.attr="disabled"
                                    wire:target="inviteToConference"
                                    @click="showInvite = false"
                                    class="w-full flex items-center gap-2 px-3 py-1.5 text-sm text-left hover:bg-zinc-100 dark:hover:bg-zinc-700 cursor-pointer disabled:opacity-50"
                                >
                                    <flux:icon name="{{ $invite['type'] === 'team' ? 'briefcase' : 'user' }}" class="size-4 text-zinc-400" />
                                    <div class="flex-1 min-w-0">
                                        <div class="truncate font-medium text-zinc-800 dark:text-zinc-200">{{ $invite['name'] }}</div>
                                        <div class="text-xs text-zinc-500">{{ $invite['display'] }}</div>
                                    </div>
                                    <span class="text-xs text-zinc-400">{{ $invite['type'] === 'team' ? 'Team' : 'Client' }}</span>
                                </button>
                            @empty
                                <div class="px-3 py-2 text-xs text-zinc-400">No additional contacts available</div>
                            @endforelse
                        </div>
                    </div>
                @endif
            @endif
        </div>

        {{-- Messages --}}
        @php
            // Build phone-to-name lookup — start with client user first names,
            // then merge the component's resolvePhoneDisplay map as fallback.
            $clientPhoneNames = collect();
            if ($this->thread->client) {
                foreach ($this->thread->client->users as $user) {
                    $telnyx = $user->routeNotificationForTelnyx();
                    if ($telnyx) {
                        $clientPhoneNames[$telnyx] = $user->first_name;
                    }
                }
                $rawHome = $this->thread->client->getRawOriginal('home_phone');
                if ($rawHome) {
                    $formatted = \App\Services\GroupSmsService::formatE164($rawHome);
                    if (! $clientPhoneNames->has($formatted)) {
                        $clientPhoneNames[$formatted] = $this->thread->client->name;
                    }
                }
            }
            // $phoneNameMap comes from render() via resolvePhoneDisplay — covers vendors/users.
            // Client first-name entries take precedence.
            $phoneNameMap = array_merge($phoneNameMap, $clientPhoneNames->all());

            $tz = browser_timezone();
            $now = now($tz);
            $todayDate = $now->toDateString();
            $yesterdayDate = $now->copy()->subDay()->toDateString();

            // ── Tapback reactions ──
            // Build a map of message ID → [emoji => [sender_name, ...]]
            // by matching the quoted text in each tapback to a real message.
            $allMessages = $this->smsMessages;
            $tapbackIds = collect();
            $reactionsMap = []; // keyed by message ID

            foreach ($allMessages as $msg) {
                $tapback = $msg->parseTapback();
                if (! $tapback || ! $tapback['emoji']) {
                    continue;
                }
                $tapbackIds->push($msg->id);

                // Find the original message by matching the quoted text snippet
                // against display_text (strip signature) of other messages in the thread.
                $quotedNormalized = mb_strtolower(trim($tapback['quoted']));
                $matched = $allMessages->first(function ($candidate) use ($quotedNormalized, $msg) {
                    if ($candidate->id === $msg->id) return false;
                    $candidateText = $candidate->display_text;
                    if (! $candidateText) return false;
                    $candidateNormalized = mb_strtolower(trim($candidateText));
                    // The tapback quote may be truncated, so check if either contains the other
                    return str_contains($candidateNormalized, $quotedNormalized)
                        || str_contains($quotedNormalized, $candidateNormalized);
                });

                if ($matched) {
                    $senderName = $phoneNameMap[$msg->from_number] ?? substr($msg->from_number, -4);
                    $reactionsMap[$matched->id][$tapback['emoji']][] = $senderName;
                }
            }

            // Filter out tapback messages from the visible list
            $visibleMessages = $allMessages->reject(fn ($m) => $tapbackIds->contains($m->id));
        @endphp

        <div class="relative flex-1 min-h-0">
            <div class="sms-fade-overlay top"></div>
        <div
            class="sms-messages h-full overflow-y-auto flex flex-col-reverse gap-3 px-2 pt-6 pb-6"
        >
            @forelse ($visibleMessages->reverse() as $msg)
                @php
                    $msgLocal = $msg->created_at->copy()->setTimezone($tz);
                    $msgDate = $msgLocal->toDateString();
                    if ($msgDate === $todayDate) {
                        $timeLabel = $msgLocal->format('g:i A');
                    } elseif ($msgDate === $yesterdayDate) {
                        $timeLabel = 'Yesterday ' . $msgLocal->format('g:i A');
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
                                            <button
                                                type="button"
                                                class="block"
                                                wire:click="openImageLightbox('{{ $url }}')"
                                            >
                                                <img
                                                    src="{{ $url }}"
                                                    alt="MMS attachment"
                                                    class="max-w-full rounded-lg max-h-64 object-cover"
                                                    loading="lazy"
                                                    onerror="this.parentElement.innerHTML='<div class=\'flex items-center gap-1.5 py-2 text-sm opacity-75\'><svg xmlns=\'http://www.w3.org/2000/svg\' class=\'size-4\' viewBox=\'0 0 20 20\' fill=\'currentColor\'><path fill-rule=\'evenodd\' d=\'M1 5.25A2.25 2.25 0 0 1 3.25 3h13.5A2.25 2.25 0 0 1 19 5.25v9.5A2.25 2.25 0 0 1 16.75 17H3.25A2.25 2.25 0 0 1 1 14.75v-9.5Zm1.5 5.81v3.69c0 .414.336.75.75.75h13.5a.75.75 0 0 0 .75-.75v-2.69l-2.22-2.219a.75.75 0 0 0-1.06 0l-1.91 1.909-4.97-4.969a.75.75 0 0 0-1.06 0L2.5 11.06Zm12.5-2.56a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Z\' clip-rule=\'evenodd\'/></svg> Image unavailable</div>'"
                                                />
                                            </button>
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

                        <p class="text-xs lg:text-[10px] text-zinc-400 dark:text-zinc-500 mt-0.5 {{ $msg->isOutbound() ? 'text-right' : '' }} px-1">{{ $timeLabel }}</p>
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
                        }
                    },
                    saveDraft(e) {
                        localStorage.setItem(this.draftKey, e.target.value);
                    },
                    clearDraft() {
                        localStorage.removeItem(this.draftKey);
                    }
                }"
                x-on:submit="clearDraft()"
            >
                @if ($attachment && method_exists($attachment, 'temporaryUrl') && $attachment->getRealPath())
                    <div class="mb-2 px-1">
                        <div class="relative inline-block border border-zinc-200 dark:border-zinc-700 rounded-lg overflow-hidden">
                            <img src="{{ $attachment->temporaryUrl() }}" alt="Attachment preview" class="size-16 object-cover" />
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
                    submit="enter"
                    x-on:input="saveDraft($event)"
                >
                    <x-slot name="actionsLeading">
                        <flux:button type="button" size="sm" variant="subtle" square icon="paper-clip" x-on:click="$refs.fileInput.click()" aria-label="Attach image"></flux:button>
                        <input x-ref="fileInput" type="file" wire:model="attachment" accept="image/*" class="hidden" />
                        @if ($this->thread?->client_id)
                            <flux:button type="button" size="sm" variant="subtle" square icon="calendar-days" wire:click="$dispatchTo('sms.send-schedule-modal', 'openScheduleModal', { threadId: {{ $threadId }} })" tooltip="Send schedule" aria-label="Send schedule"></flux:button>
                        @endif
                    </x-slot>

                    <x-slot name="actionsTrailing">
                        <flux:button type="submit" size="sm" variant="primary" square icon="paper-airplane" wire:loading.attr="disabled" :disabled="$pendingOptIn" aria-label="Send message"></flux:button>
                    </x-slot>
                </flux:composer>
            </form>

            @error('newMessage')
                <p class="text-xs text-red-500 mt-1 px-1">{{ $message }}</p>
            @enderror
            @error('attachment')
                <p class="text-xs text-red-500 mt-1 px-1">{{ $message }}</p>
            @enderror

            <div class="flex items-center justify-between gap-2 px-2 pt-1">
                <div class="flex items-center gap-2 min-w-0">
                    <flux:avatar size="xs" color="indigo" name="{{ auth()->user()->full_name }}" circle />
                    <span class="text-xs text-zinc-500 dark:text-zinc-400 truncate">{{ auth()->user()->full_name }}</span>
                </div>

                @if ($pendingOptIn)
                    <div class="ml-auto flex items-center gap-2 whitespace-nowrap">
                        <flux:icon name="exclamation-triangle" class="size-4 text-amber-500" />
                        <flux:text class="text-xs text-amber-600 dark:text-amber-400">Awaiting START reply</flux:text>

                        <flux:button
                            type="button"
                            size="xs"
                            variant="primary"
                            color="amber"
                            wire:click="resendOptInPrompt"
                            wire:loading.attr="disabled"
                            wire:target="resendOptInPrompt"
                        >
                            Resend
                        </flux:button>

                        <flux:button
                            type="button"
                            size="xs"
                            variant="primary"
                            wire:click="openOptInModal"
                        >
                            Manual Opt-in
                        </flux:button>
                    </div>
                @endif
            </div>
            @endif
        </div>

        {{-- Manual Opt-In Modal --}}
        <flux:modal wire:model="showOptInModal" class="max-w-md space-y-6">
            <div>
                <flux:heading size="lg">Manual Opt-In</flux:heading>
                <flux:text class="mt-1">Manually opt in a participant who confirmed consent outside of SMS (e.g. texted START to a different number, approved on a phone call, emailed with START).</flux:text>
            </div>

            <form wire:submit="manualOptIn" class="space-y-4">
                <flux:field>
                    <flux:label>Participant</flux:label>
                    @if ($this->pendingParticipants->count() > 0)
                        <flux:select wire:model="manualOptInParticipantId" placeholder="Select participant...">
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
                        wire:loading.attr="disabled"
                        wire:target="manualOptIn"
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

        <flux:modal wire:model="showImageLightbox" name="sms-image-lightbox" class="!p-0 !rounded-xl max-w-lg sm:max-w-xl md:max-w-2xl">
            <div
                x-data="{
                    images: @js($this->threadImages),
                    currentIndex: 0,
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

                    get currentUrl() { return this.images[this.currentIndex] ?? ''; },
                    get hasMultiple() { return this.images.length > 1; },
                    get hasPrev() { return this.currentIndex > 0; },
                    get hasNext() { return this.currentIndex < this.images.length - 1; },

                    goTo(idx) {
                        if (idx < 0 || idx >= this.images.length) return;
                        this.resetZoom();
                        this.currentIndex = idx;
                    },
                    prev() { this.goTo(this.currentIndex - 1); },
                    next() { this.goTo(this.currentIndex + 1); },

                    syncFromWire(url) {
                        const idx = this.images.indexOf(url);
                        if (idx !== -1) this.currentIndex = idx;
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
                    }
                }"
                x-ref="zoomContainer"
                x-init="
                    $watch('$wire.showImageLightbox', v => { if (!v) { resetZoom(); } });
                    $watch('$wire.lightboxImageUrl', v => { if (v) syncFromWire(v); });
                "
                :class="isZoomed ? 'cursor-grab active:cursor-grabbing' : ''"
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
                class="relative"
            >
                {{-- Close button --}}
                <flux:modal.close>
                    <button
                        type="button"
                        class="absolute right-2 top-2 z-20 rounded-full bg-black/50 p-1.5 text-white/80 hover:bg-black/70 hover:text-white transition"
                        aria-label="Close image preview"
                    >
                        <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                    </button>
                </flux:modal.close>

                {{-- Previous button --}}
                <button
                    x-show="hasMultiple && hasPrev && !isZoomed"
                    x-transition.opacity
                    @click.stop="prev()"
                    type="button"
                    class="absolute left-2 top-1/2 -translate-y-1/2 z-20 rounded-full bg-black/50 p-2 text-white/80 hover:bg-black/70 hover:text-white transition"
                    aria-label="Previous image"
                >
                    <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" /></svg>
                </button>

                {{-- Next button --}}
                <button
                    x-show="hasMultiple && hasNext && !isZoomed"
                    x-transition.opacity
                    @click.stop="next()"
                    type="button"
                    class="absolute right-2 top-1/2 -translate-y-1/2 z-20 rounded-full bg-black/50 p-2 text-white/80 hover:bg-black/70 hover:text-white transition"
                    aria-label="Next image"
                >
                    <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" /></svg>
                </button>

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
                    x-show="isZoomed"
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

                {{-- Image --}}
                <div class="overflow-hidden rounded-xl select-none" :class="isZoomed ? 'touch-none' : ''">
                    <img
                        :src="currentUrl"
                        alt="MMS attachment"
                        class="block max-h-[80vh] w-auto max-w-full object-contain select-none transition-transform duration-100 ease-out"
                        :style="`transform: scale(${scale}) translate(${translateX / scale}px, ${translateY / scale}px); transform-origin: center center;`"
                        draggable="false"
                    />
                </div>
            </div>
        </flux:modal>
    @else
        {{-- No thread selected --}}
        <div class="flex flex-1 items-center justify-center">
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
