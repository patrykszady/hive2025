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
    class="space-y-2 h-full overflow-y-auto scrollbar-gutter overscroll-contain"
    style="-webkit-overflow-scrolling: touch"
>
    {{-- Pull-to-refresh indicator --}}
    <div
        x-show="pullY > 0"
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

    {{-- Filter tabs --}}
    <div class="mb-3 flex items-center gap-2 sticky top-0 z-10 bg-white dark:bg-zinc-800">
        <flux:tabs wire:model.live="callFilter" variant="segmented" size="sm" class="w-full !flex [&>button]:flex-1">
            <flux:tab name="all">All</flux:tab>
            <flux:tab name="missed">Missed</flux:tab>
            <flux:tab name="voicemail">Voice</flux:tab>
            <flux:tab name="blocked">Blocked</flux:tab>
        </flux:tabs>
    </div>

    {{-- Call list --}}
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
            x-on:click="selectedCallId = selectedCallId === {{ $call->id }} ? null : {{ $call->id }}"
            class="flex items-center gap-3 px-3 py-2.5 rounded-lg cursor-pointer transition-colors"
            :class="selectedCallId === {{ $call->id }} ? 'bg-zinc-100 dark:bg-zinc-700' : 'hover:bg-zinc-50 dark:hover:bg-zinc-800'"
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
                    <span class="text-sm lg:text-xs text-zinc-400 whitespace-nowrap">
                        {{ $call->created_at->diffForHumans(short: true) }}
                    </span>
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

                {{-- Expanded details --}}
                <div x-show="selectedCallId === {{ $call->id }}" x-cloak class="mt-2 pt-2 border-t border-zinc-200 dark:border-zinc-600 space-y-2">
                        <div class="text-sm lg:text-xs text-zinc-500">
                            {{ $call->created_at->copy()->setTimezone(browser_timezone())->format('M j, Y g:i A') }}
                        </div>

                        @if ($otherNumber)
                            <div class="flex flex-col gap-1">
                                <flux:button size="xs" variant="primary" icon="phone" wire:click.stop="callBack('{{ $otherNumber }}')">
                                    Call Back
                                </flux:button>
                                <flux:button size="xs" variant="ghost" icon="chat-bubble-left" wire:click.stop="textBack('{{ $otherNumber }}')">
                                    Text
                                </flux:button>
                                @if ($otherNumber && ! $this->isKnownContact($otherNumber) && $effectiveStatus !== 'blocked' && ! in_array($otherNumber, $this->blockedNumbers))
                                    <flux:button size="xs" variant="primary" color="amber" icon="shield-exclamation" class="justify-center" wire:click.stop="markAsSpam({{ $call->id }})">
                                        Mark as Spam
                                    </flux:button>
                                @endif
                            </div>
                        @endif

                        @if ($call->has_voicemail && $call->recording_url)
                            <div class="mt-1">
                                <div class="text-sm lg:text-xs font-medium text-zinc-700 dark:text-zinc-300 mb-1">Voicemail</div>
                                <audio controls preload="none" class="w-full h-8">
                                    <source src="{{ $call->recording_url }}" type="audio/mpeg">
                                </audio>
                            </div>
                        @elseif ($call->recording_url)
                            <div class="mt-1">
                                <div class="text-sm lg:text-xs font-medium text-zinc-700 dark:text-zinc-300 mb-1">Recording</div>
                                <audio controls preload="none" class="w-full h-8">
                                    <source src="{{ $call->recording_url }}" type="audio/mpeg">
                                </audio>
                            </div>
                        @endif
                </div>
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

                <div class="flex justify-end gap-2">
                    <flux:button variant="ghost" wire:click="$set('showNewCallModal', false)">Cancel</flux:button>
                    <flux:button type="submit" variant="primary" icon="phone">Call</flux:button>
                </div>
            </form>
        </div>
    </flux:modal>
</div>
