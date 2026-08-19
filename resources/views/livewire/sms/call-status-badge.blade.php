{{-- Poll only while the badge shows something: the websocket push is the
     fast path; this is the safety net for a dropped connection, so a stale
     "In a call" can outlive a hangup by 15s at most. --}}
<div class="flex items-center gap-2" @if ($activeCallId) wire:poll.15s="updateCallStatus" @endif>
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
        @if ($activeCallStatus === 'transferred' && $this->availableParticipants->isNotEmpty())
            <flux:button
                size="sm"
                variant="ghost"
                icon="user-plus"
                wire:click="openAddParticipantModal"
                title="Add team member to call"
            >
                Add Participant
            </flux:button>
        @endif

        <!-- Add Participant Modal -->
        <flux:modal name="add-participant" class="md:w-96">
            <div class="space-y-4">
                <div>
                    <flux:heading level="2">Add Team Member</flux:heading>
                    <flux:description>
                        Select a team member to add to this call.
                    </flux:description>
                </div>

                @if ($this->availableParticipants->isNotEmpty())
                    <flux:field>
                        <flux:label>Select Team Member</flux:label>
                        <flux:select 
                            wire:model="selectedParticipantId"
                            placeholder="Choose a team member..."
                        >
                            @foreach ($this->availableParticipants as $participant)
                                <option value="{{ $participant->id }}">
                                    {{ $participant->full_name }}
                                    @if ($participant->cell_phone)
                                        ({{ $participant->cell_phone }})
                                    @endif
                                </option>
                            @endforeach
                        </flux:select>
                    </flux:field>
                @else
                    <flux:callout type="warning" title="No team members available">
                        There are no other team members with phone numbers to add.
                    </flux:callout>
                @endif

                <div class="flex gap-2 justify-end">
                    <flux:button 
                        variant="ghost"
                        wire:click="closeAddParticipantModal"
                    >
                        Cancel
                    </flux:button>
                    <flux:button 
                        variant="primary"
                        wire:click="inviteParticipant"
                        :disabled="!$this->selectedParticipantId"
                    >
                        Invite
                    </flux:button>
                </div>
            </div>
        </flux:modal>
    @endif
</div>
