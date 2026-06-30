<div
    x-data="{
        selectedCallId: null,
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
    x-on:touchstart.passive="onTouchStart($event)"
    x-on:touchmove="onTouchMove($event)"
    x-on:touchend="onTouchEnd()"
    x-init="
        $nextTick(() => {
            if (! $store.sms.callId
                && window.matchMedia('(min-width: 1024px)').matches
                && $el.dataset.firstCallId
            ) {
                const id = parseInt($el.dataset.firstCallId, 10);
                $store.sms.callId = id;
                Livewire.dispatch('call-selected', { callId: id });
            }
        })
    "
    data-first-call-id="{{ optional($this->calls->first())['call']?->id }}"
    class="space-y-2 h-full overflow-y-auto scrollbar-gutter overscroll-contain"
    style="-webkit-overflow-scrolling: touch"
>
    {{-- Pull-to-refresh indicator --}}
    <div
        x-show="pullY > 0"        x-cloak        x-bind:style="'height: ' + pullY + 'px'"
        class="flex items-end justify-center overflow-hidden transition-none sticky top-0 z-20"
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

    {{-- Filter tabs --}}
    <div class="mb-3 sticky top-0 z-10">
        <flux:input
            wire:model.live.debounce.350ms="search"
            icon="magnifying-glass"
            placeholder="Search calls, numbers, transcripts…"
            size="sm"
            clearable
            class="mb-2"
        />
        <flux:tabs wire:model.live="callFilter" variant="segmented" size="sm" class="w-full !flex [&>button]:flex-1 !rounded-lg !bg-zinc-100 dark:!bg-zinc-800 !p-0.5">
            <flux:tab name="all">All</flux:tab>
            <flux:tab name="missed">Missed</flux:tab>
            <flux:tab name="voicemail">Voice</flux:tab>
            <flux:tab name="blocked">Blocked</flux:tab>
        </flux:tabs>
    </div>

    {{-- Call list --}}
    <div class="relative min-h-0">
        <div
            wire:loading
            wire:target="callFilter,$refresh,loadMore,search"
            class="absolute inset-0 z-20 pointer-events-none"
        >
            @include('livewire.sms.call-list-skeleton')
        </div>

    @forelse ($this->calls as $group)
        @php
            $call = $group['call'];
            $groupCount = $group['count'];
            $isIncoming = $call->direction === 'incoming';
            $isOutgoing = $call->direction === 'outgoing';
            $effectiveStatus = $this->effectiveStatus($call);

            // Resolve the "other party" phone to a contact name
            $otherNumber = $isOutgoing ? $call->to_number : $call->from_number;

            // If otherNumber is our own Telnyx number (phantom leg), try finding the
            // original caller from a sibling call log in the same session.
            if ($otherNumber && \App\Services\GroupSmsService::isOurNumber($otherNumber) && $call->call_session_id) {
                $originalLeg = \App\Models\CallLog::where('call_session_id', $call->call_session_id)
                    ->where('direction', 'incoming')
                    ->where(fn ($q) => $q->whereNotIn('from_number', config('services.telnyx.numbers', [])))
                    ->first();
                if ($originalLeg) {
                    $otherNumber = $originalLeg->from_number;
                }
            }

            $resolvedName = $otherNumber ? $this->resolvePhoneDisplay($otherNumber) : null;
            $formattedOther = $otherNumber ? $this->formatPhone($otherNumber) : null;

            // Use caller_name from the call record if meaningful, otherwise resolved name
            $displayName = ($call->caller_name && ! in_array($call->caller_name, ['Incoming Call', 'Outgoing Call'], true))
                ? $call->caller_name
                : ($resolvedName ?? 'Unknown');

            // Show formatted phone as secondary only when display name differs from it
            $secondaryNumber = ($formattedOther && $displayName !== $formattedOther)
                ? $formattedOther
                : null;

            // Don't show secondary if it matches the display name
            if ($secondaryNumber === $displayName) {
                $secondaryNumber = null;
            }

        @endphp

        <div
            wire:key="call-{{ $call->id }}"
            x-on:click="$store.sms.callId = {{ $call->id }}; Livewire.dispatch('call-selected', { callId: {{ $call->id }} })"
            class="flex items-center gap-3 px-3 py-2.5 rounded-lg cursor-pointer transition-colors"
            :class="$store.sms.callId === {{ $call->id }} ? 'bg-zinc-100 dark:bg-zinc-700' : 'hover:bg-zinc-50 dark:hover:bg-zinc-800'"
        >
            {{-- Status icon --}}
            <div class="shrink-0">
                @if ($effectiveStatus === 'blocked')
                    <flux:icon icon="shield-exclamation" variant="micro" class="size-4 text-amber-500" />
                @elseif ($effectiveStatus === 'missed' && $call->has_voicemail)
                    <flux:icon icon="microphone" variant="micro" class="size-4 text-indigo-500" />
                @elseif ($effectiveStatus === 'missed' && $call->direction === 'outgoing')
                    <flux:icon icon="phone-arrow-up-right" variant="micro" class="size-4 text-orange-500" />
                @elseif ($effectiveStatus === 'missed')
                    <flux:icon icon="phone-x-mark" variant="micro" class="size-4 text-red-500" />
                @elseif ($effectiveStatus === 'failed')
                    <flux:icon icon="x-circle" variant="micro" class="size-4 text-rose-500" />
                @elseif ($call->direction === 'outgoing')
                    <flux:icon icon="phone-arrow-up-right" variant="micro" class="size-4 text-indigo-500" />
                @elseif ($call->direction === 'incoming')
                    <flux:icon icon="phone-arrow-down-left" variant="micro" class="size-4 text-green-500" />
                @else
                    <flux:icon icon="phone" variant="micro" class="size-4 text-zinc-400" />
                @endif
            </div>

            {{-- Call details --}}
            <div class="flex-1 min-w-0">
                <div class="flex items-center justify-between gap-2">
                    <span class="text-base lg:text-sm font-medium text-zinc-900 dark:text-zinc-100 truncate">
                        {{ $displayName }}
                    </span>
                    <div class="flex items-center gap-1.5 whitespace-nowrap">
                        @if ($call->transcript && ($call->transcript->summary || $call->transcript->text))
                            <flux:icon icon="sparkles" variant="micro" class="size-3.5 text-indigo-500" title="AI summary available" />
                        @endif
                        <span class="text-sm lg:text-xs text-zinc-400">
                            {{ $call->created_at->diffForHumans(short: true) }}
                        </span>
                    </div>
                </div>

                <div class="flex items-center justify-between gap-2 mt-0.5">
                    <div class="flex items-center gap-2">
                        @if ($secondaryNumber)
                            <span class="text-sm lg:text-xs text-zinc-400">{{ $secondaryNumber }}</span>
                        @endif

                        @if ($effectiveStatus === 'blocked')
                            <span class="text-sm lg:text-xs text-amber-500 font-medium">Blocked</span>
                        @elseif ($effectiveStatus === 'missed' && $call->has_voicemail)
                            <span class="text-sm lg:text-xs text-red-400 font-medium">Missed</span>
                            <span class="text-sm lg:text-xs text-indigo-500 font-medium">Voicemail</span>
                        @elseif ($effectiveStatus === 'missed' && $call->direction === 'outgoing')
                            <span class="text-sm lg:text-xs text-orange-500 font-medium">No Answer</span>
                        @elseif ($effectiveStatus === 'missed')
                            <span class="text-sm lg:text-xs text-red-400 font-medium">Missed</span>
                        @elseif ($effectiveStatus === 'failed')
                            <span class="text-sm lg:text-xs text-rose-500 font-medium">Failed</span>
                        @endif

                        @if ($groupCount > 1)
                            <span class="text-sm lg:text-xs text-zinc-400 font-medium">({{ $groupCount }})</span>
                        @endif
                    </div>

                    @if ($call->duration_seconds && abs($call->duration_seconds) > 0 && !in_array($effectiveStatus, ['missed', 'failed']))
                        @php $dur = abs($call->duration_seconds); @endphp
                        <span class="text-sm lg:text-xs text-zinc-400 whitespace-nowrap italic">
                            @if ($dur < 60)
                                {{ $dur }}s
                            @else
                                {{ floor($dur / 60) }}m {{ $dur % 60 }}s
                            @endif
                        </span>
                    @endif
                </div>

                {{-- Detail pane lives in the right column; the inline expansion has been removed. --}}
            </div>
        </div>
    @empty
        <div class="text-center py-8 text-sm text-zinc-400">
            No calls found.
        </div>
    @endforelse

    {{-- Infinite scroll --}}
    @if ($this->calls->sum('count') >= $limit)
        <div wire:intersect="loadMore" class="text-center py-2">
            <span wire:loading wire:target="loadMore" class="text-xs text-zinc-400">Loading...</span>
        </div>
    @endif
    </div>

    {{-- New Call Modal --}}
    <flux:modal wire:model="showNewCallModal" class="max-w-sm">
        <div class="space-y-4">
            <flux:heading size="lg">New Call</flux:heading>
            <flux:text class="text-sm text-zinc-500">Select a contact or enter a number. Your phone will ring first, then we'll connect you.</flux:text>

            <form wire:submit="placeNewCall" class="space-y-4">
                {{-- Contact Dropdown --}}
                <flux:field>
                    <flux:select label="Contact" wire:model.live="selectedUserId" variant="listbox" searchable clearable placeholder="Choose a contact...">
                        <x-slot name="search">
                            <flux:select.search placeholder="Search..." />
                        </x-slot>

                        @foreach($this->contactUsers as $contactUser)
                            <flux:select.option value="{{ $contactUser->id }}">
                                {{ $contactUser->full_name }}
                                @if($contactUser->cell_phone)
                                    — {{ $this->formatPhone($contactUser->cell_phone) }}
                                @endif
                            </flux:select.option>
                        @endforeach
                    </flux:select>
                </flux:field>

                {{-- Phone Number --}}
                <flux:field>
                    <flux:input
                        wire:model="newCallNumber"
                        label="Phone Number"
                        placeholder="(555) 123-4567"
                        icon="phone"
                        type="tel"
                    />
                    <flux:description>Or enter a number manually.</flux:description>
                </flux:field>

                {{-- Multi-recipient Selection --}}
                @if($this->callRecipients->isNotEmpty())
                    <flux:field>
                        <flux:label>Ring These Team Members</flux:label>
                        <flux:description class="text-xs">Leave blank to use default settings from your vendor options.</flux:description>
                        <div class="mt-2 flex flex-col gap-2 max-h-48 overflow-y-auto">
                            @foreach($this->callRecipients as $recipient)
                                <label class="flex items-center gap-3 cursor-pointer">
                                    <flux:checkbox wire:model="selectedCallRecipients" value="{{ $recipient->id }}" />
                                    <div class="text-sm">
                                        <span class="font-medium text-zinc-900 dark:text-zinc-100">{{ $recipient->full_name }}</span>
                                        @if($recipient->cell_phone)
                                            <span class="text-xs text-zinc-500 ml-1">{{ $this->formatPhone($recipient->cell_phone) }}</span>
                                        @endif
                                    </div>
                                </label>
                            @endforeach
                        </div>
                    </flux:field>
                @endif

                <div class="flex justify-end gap-2">
                    <flux:button variant="ghost" wire:click="$set('showNewCallModal', false)">Cancel</flux:button>
                    <flux:button type="submit" variant="primary" icon="phone">Call</flux:button>
                </div>
            </form>
        </div>
    </flux:modal>
</div>
