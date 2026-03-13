<div class="space-y-2 max-h-[calc(100dvh-13rem)] lg:max-h-full lg:h-full scrollbar-gutter">
    {{-- Filter tabs --}}
    <div class="mb-3 flex items-center gap-2 sticky top-0 z-10 bg-white dark:bg-zinc-800">
        <flux:tabs wire:model.live="callFilter" variant="segmented" size="sm" class="w-full !flex [&>button]:flex-1">
            <flux:tab name="all">All</flux:tab>
            <flux:tab name="missed">Missed</flux:tab>
            <flux:tab name="voicemail">Voicemail</flux:tab>
        </flux:tabs>
    </div>

    {{-- Call list --}}
    @forelse ($this->calls as $call)
        @php
            $isIncoming = $call->direction === 'incoming';
            $isOutgoing = $call->direction === 'outgoing';

            // Resolve the "other party" phone to a contact name
            $otherNumber = $isOutgoing ? $call->to_number : $call->from_number;

            // If otherNumber is our own Telnyx number (phantom leg), try finding the
            // original caller from a sibling call log in the same session.
            $telnyxFrom = config('services.telnyx.from');
            if ($otherNumber && $telnyxFrom && $otherNumber === $telnyxFrom && $call->call_session_id) {
                $originalLeg = \App\Models\CallLog::where('call_session_id', $call->call_session_id)
                    ->where('direction', 'incoming')
                    ->where('from_number', '!=', $telnyxFrom)
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
                : ($isOutgoing && $call->from_number ? $this->resolvePhoneDisplay($call->from_number) : null);

            // Don't show secondary if it matches the display name
            if ($secondaryNumber === $displayName) {
                $secondaryNumber = null;
            }
        @endphp

        <div
            wire:key="call-{{ $call->id }}"
            wire:click="selectCall({{ $call->id }})"
            class="flex items-center gap-3 px-3 py-2.5 rounded-lg cursor-pointer transition-colors
                {{ $selectedCallId === $call->id ? 'bg-zinc-100 dark:bg-zinc-700' : 'hover:bg-zinc-50 dark:hover:bg-zinc-800' }}"
        >
            {{-- Status icon --}}
            <div class="shrink-0">
                @if ($call->status === 'blocked')
                    <flux:icon icon="shield-exclamation" variant="micro" class="size-4 text-amber-500" />
                @elseif ($call->status === 'missed' && $call->has_voicemail)
                    <flux:icon icon="microphone" variant="micro" class="size-4 text-indigo-500" />
                @elseif ($call->status === 'missed')
                    <flux:icon icon="phone-x-mark" variant="micro" class="size-4 text-red-500" />
                @elseif ($call->status === 'failed')
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

                <div class="flex items-center gap-2 mt-0.5">
                    @if ($secondaryNumber)
                        <span class="text-sm lg:text-xs text-zinc-400">{{ $secondaryNumber }}</span>
                    @endif

                    @if ($call->duration_seconds && $call->duration_seconds > 0)
                        <span class="text-sm lg:text-xs text-zinc-400">
                            @if ($call->duration_seconds < 60)
                                {{ $call->duration_seconds }} secs
                            @else
                                {{ round($call->duration_seconds / 60) }} mins
                            @endif
                        </span>
                    @endif

                    @if ($call->status === 'missed' && $call->has_voicemail)
                        <span class="text-sm lg:text-xs text-red-400 font-medium">Missed</span>
                        <span class="text-sm lg:text-xs text-indigo-500 font-medium">Voicemail</span>
                    @elseif ($call->status === 'missed')
                        <span class="text-sm lg:text-xs text-red-400 font-medium">Missed</span>
                    @elseif ($call->status === 'failed')
                        <span class="text-sm lg:text-xs text-rose-500 font-medium">Failed</span>
                    @endif
                </div>

                {{-- Expanded details --}}
                @if ($selectedCallId === $call->id)
                    <div class="mt-2 pt-2 border-t border-zinc-200 dark:border-zinc-600 space-y-2">
                        <div class="flex items-center justify-between">
                            <div class="text-sm lg:text-xs text-zinc-500">
                                {{ $call->created_at->copy()->setTimezone(browser_timezone())->format('M j, Y g:i A') }}
                                @if ($call->forwarded_to && $this->formatPhone($call->forwarded_to) !== $formattedOther)
                                    &middot; {{ $this->formatPhone($call->forwarded_to) }}
                                @endif
                            </div>

                            <div class="flex items-center gap-1">
                                @if ($otherNumber)
                                    <flux:button size="xs" variant="ghost" icon="chat-bubble-left" wire:click.stop="textBack('{{ $otherNumber }}')">
                                        Text
                                    </flux:button>
                                @endif
                                @if ($otherNumber)
                                    <flux:button size="xs" variant="primary" icon="phone" wire:click.stop="callBack('{{ $otherNumber }}')">
                                        Call Back
                                    </flux:button>
                                @endif
                            </div>
                        </div>

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
                @endif
            </div>
        </div>
    @empty
        <div class="text-center py-8 text-sm text-zinc-400">
            No calls found.
        </div>
    @endforelse

    {{-- Infinite scroll --}}
    @if ($this->calls->count() >= $limit)
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
