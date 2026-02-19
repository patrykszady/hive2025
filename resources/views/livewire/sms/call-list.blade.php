<div class="space-y-2">
    {{-- Filter tabs --}}
    <div class="mb-3">
        <flux:tabs wire:model.live="callFilter" variant="segmented" size="sm">
            <flux:tab name="all">All</flux:tab>
            <flux:tab name="missed">Missed</flux:tab>
            <flux:tab name="voicemail">Voicemail</flux:tab>
            <flux:tab name="completed">Completed</flux:tab>
        </flux:tabs>
    </div>

    {{-- Call list --}}
    @forelse ($this->calls as $call)
        <div
            wire:key="call-{{ $call->id }}"
            wire:click="selectCall({{ $call->id }})"
            class="flex items-center gap-3 px-3 py-2.5 rounded-lg cursor-pointer transition-colors
                {{ $selectedCallId === $call->id ? 'bg-zinc-100 dark:bg-zinc-700' : 'hover:bg-zinc-50 dark:hover:bg-zinc-800' }}"
        >
            {{-- Status icon --}}
            <div class="shrink-0">
                @if ($call->status === 'voicemail')
                    <flux:icon.microphone variant="micro" class="size-4 text-blue-500" />
                @elseif ($call->status === 'missed')
                    <flux:icon.phone-x-mark variant="micro" class="size-4 text-red-400" />
                @elseif ($call->status === 'completed')
                    <flux:icon.phone variant="micro" class="size-4 text-green-500" />
                @else
                    <flux:icon.phone-arrow-down-left variant="micro" class="size-4 text-zinc-400" />
                @endif
            </div>

            {{-- Call details --}}
            <div class="flex-1 min-w-0">
                <div class="flex items-center justify-between gap-2">
                    <span class="text-base lg:text-sm font-medium text-zinc-900 dark:text-zinc-100 truncate">
                        {{ $call->caller_name ?: $this->formatPhone($call->from_number) }}
                    </span>
                    <span class="text-sm lg:text-xs text-zinc-400 whitespace-nowrap">
                        {{ $call->created_at->diffForHumans(short: true) }}
                    </span>
                </div>

                <div class="flex items-center gap-2 mt-0.5">
                    @if ($call->caller_name)
                        <span class="text-sm lg:text-xs text-zinc-400">{{ $this->formatPhone($call->from_number) }}</span>
                    @endif

                    @if ($call->duration_seconds && $call->duration_seconds > 0)
                        <span class="text-sm lg:text-xs text-zinc-400">
                            @if ($call->duration_seconds < 60)
                                {{ $call->duration_seconds }}s
                            @else
                                {{ floor($call->duration_seconds / 60) }}:{{ str_pad($call->duration_seconds % 60, 2, '0', STR_PAD_LEFT) }}
                            @endif
                        </span>
                    @endif

                    @if ($call->status === 'voicemail')
                        <span class="text-sm lg:text-xs text-blue-500 font-medium">Voicemail</span>
                    @elseif ($call->status === 'missed')
                        <span class="text-sm lg:text-xs text-red-400 font-medium">Missed</span>
                    @endif
                </div>

                {{-- Expanded details --}}
                @if ($selectedCallId === $call->id)
                    <div class="mt-2 pt-2 border-t border-zinc-200 dark:border-zinc-600 space-y-2">
                        <div class="flex items-center justify-between">
                            <div class="text-sm lg:text-xs text-zinc-500">
                                {{ $call->created_at->format('M j, Y g:i A') }}
                                @if ($call->forwarded_to)
                                    &middot; {{ $this->formatPhone($call->forwarded_to) }}
                                @endif
                            </div>

                            @if ($call->from_number)
                                <flux:button size="xs" variant="primary" icon="phone" wire:click.stop="callBack('{{ $call->from_number }}')">
                                    Call Back
                                </flux:button>
                            @endif
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

    {{-- Pagination --}}
    @if ($this->calls->hasPages())
        <div class="mt-3">
            {{ $this->calls->links() }}
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
