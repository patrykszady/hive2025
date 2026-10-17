{{-- Poll only while the badge shows something: the websocket push is the
     fast path; this is the safety net for a dropped connection, so a stale
     "In a call" can outlive a hangup by 15s at most. --}}
<div class="flex items-center gap-2 {{ $activeCallId ? 'mb-3' : '' }}" @if ($activeCallId) wire:poll.15s="updateCallStatus" @endif>
    @if ($activeCallId)
        <flux:badge color="green" icon="phone">
            <span>In a call</span>
            @if ($otherPartyDisplay)
                <span class="text-xs opacity-75">• {{ $otherPartyDisplay }}</span>
            @endif
        </flux:badge>

        {{-- Only offer "add" once the conference is live (status transferred):
             inviteParticipantToCall hard-requires a conference_id, so showing
             it during initiated/answered just produces a "No Conference" toast. --}}
        @if ($activeCallStatus === 'transferred')
            <flux:button
                size="sm"
                variant="ghost"
                icon="user-plus"
                wire:click="openAddParticipantModal"
                title="Add someone to this call"
            >
                Add Participant
            </flux:button>
        @endif

        <!-- Add Participant Modal -->
        <flux:modal name="add-participant" wire:model.self="addingParticipant" class="md:w-96">
            <div class="space-y-4">
                <div>
                    <flux:heading level="2">Add to Call</flux:heading>
                    <flux:description>
                        Search your team, this client's people, or anyone else the
                        company knows — or type a phone number to dial it straight in.
                    </flux:description>
                </div>

                {{-- :filter="false" because the options below are already the
                     matches — participantSearchResults() does the filtering
                     server-side (team + call-tied people with nothing typed,
                     widening to everyone the company knows, plus a hand-typed
                     number, once two or more characters are entered). Each
                     item invites immediately on click; there is no separate
                     confirm step. --}}
                <flux:autocomplete
                    wire:model.live.debounce.300ms="participantSearch"
                    :filter="false"
                    placeholder="Search by name or phone, or type a number..."
                    clearable
                >
                    @forelse ($this->participantSearchResults as $result)
                        <flux:autocomplete.item
                            wire:key="invite-{{ $result['key'] }}"
                            value="{{ $result['key'] }}"
                            wire:click="inviteParticipant('{{ $result['key'] }}')"
                        >
                            <div class="flex items-center justify-between w-full gap-2">
                                <span class="truncate">{{ $result['name'] }}</span>
                                <span class="flex items-center gap-1.5 shrink-0">
                                    <flux:badge size="sm" color="{{ match ($result['kind']) {
                                        'Team' => 'blue',
                                        'Client' => 'green',
                                        'Vendor' => 'amber',
                                        default => 'zinc',
                                    } }}">
                                        {{ $result['kind'] }}
                                    </flux:badge>
                                    <span class="text-xs text-zinc-400 dark:text-zinc-500">{{ $this->formatPhone($result['phone']) }}</span>
                                </span>
                            </div>
                        </flux:autocomplete.item>
                    @empty
                        <flux:autocomplete.item value="" disabled>
                            {{ trim($participantSearch) === ''
                                ? 'Start typing a name or phone number…'
                                : 'No matches — try a full phone number' }}
                        </flux:autocomplete.item>
                    @endforelse
                </flux:autocomplete>

                <div class="flex gap-2 justify-end">
                    <flux:button
                        variant="ghost"
                        wire:click="closeAddParticipantModal"
                    >
                        Close
                    </flux:button>
                </div>
            </div>
        </flux:modal>
    @endif
</div>
