{{-- Single root: the delete confirmation is a sibling of the lead modal. --}}
<div>
<x-form-modal name="lead_form_modal" title="Lead" x-data="{ activeLeadTab: 'details' }"
    x-on:lead-modal-opened.window="activeLeadTab = 'details'">
    @if ($this->hasReplied)
        {{-- Replied leads have nothing but Details — skip the tab chrome. --}}
        <div class="pt-2">
            @include('livewire.leads.partials.details-panel')
        </div>
    @else
    <flux:tab.group>
        <flux:tabs>
            <flux:tab name="details" x-on:click="activeLeadTab = 'details'">Details</flux:tab>
            {{-- Incomplete contact: finish it on Details first. We never invent
                 the missing pieces, so the blanks are the prompt. --}}
            @if (! $this->hasReplied && $this->blockingContactInfo === [])
                <flux:tab name="messages" x-on:click="activeLeadTab = 'messages'">Message</flux:tab>
            @endif
        </flux:tabs>

        <flux:tab.panel name="details" class="pt-4">
            @include('livewire.leads.partials.details-panel')
        </flux:tab.panel>
        @if (! $this->hasReplied && $this->blockingContactInfo === [])
        <flux:tab.panel name="messages" class="pt-4">
            <form id="lead_messages_form" wire:submit="send_message" class="space-y-4">
                <flux:textarea
                    wire:model.live="message"
                    disabled
                    label="Message"
                    rows="auto"
                    resize="none"
                />

                {{-- Recipients --}}
                <div>
                    <div class="flex items-center gap-2">
                        <flux:heading size="sm" class="mb-0">To:</flux:heading>
                        @foreach ($to as $recipientEmail)
                            <span class="inline-flex items-center gap-1 rounded-md bg-zinc-100 dark:bg-zinc-700 px-2 py-1 text-sm text-zinc-700 dark:text-zinc-200">
                                {{ $this->getUserDisplayName($recipientEmail) }}
                            </span>
                        @endforeach
                    </div>
                    @error('to')
                        <p class="mt-1 text-sm text-red-500">{{ $message }}</p>
                    @enderror
                </div>

                <flux:select wire:model.live="from" label="From" placeholder="Select sender email...">
                    @foreach ($availableFromEmails as $companyEmail)
                        <flux:select.option :value="$companyEmail->email">{{ $this->getFromUserDisplayName($companyEmail->email) }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:input.group label="Email Template">
                    <flux:select wire:model.live="selectedTemplateId" variant="listbox" placeholder="Select a template...">
                        @foreach ($availableTemplates as $template)
                            <flux:select.option :value="$template->id">
                                {{ $template->name }}
                            </flux:select.option>
                        @endforeach
                    </flux:select>

                    <flux:button href="{{ route('templates.index') }}" target="_blank" icon="plus">Add</flux:button>
                </flux:input.group>

                <flux:input
                    wire:model.live.debounce.500ms="subject"
                    label="Subject"
                    placeholder="Subject"
                />

                @if (! empty($availability))
                    <flux:field>
                        <flux:label>Availability</flux:label>
                        @if ($this->hasUsableAvailability)
                            <flux:description class="mb-2">Click to select a slot for the email.</flux:description>
                        @else
                            <flux:description class="mb-2">These preferred times have passed — the email asks {{ $full_name ?: 'the client' }} to pick new ones instead.</flux:description>
                        @endif
                        <div class="flex flex-wrap gap-2">
                            @foreach ($availability as $index => $slot)
                                @php($selected = in_array($index, $selectedAvailability, true))
                                @php($past = ! \App\Models\Lead::slotIsBookable((array) $slot))
                                <button type="button"
                                    wire:click="insertAvailabilitySlot({{ $index }})"
                                    @disabled($past)
                                    class="{{ $past ? 'cursor-not-allowed' : 'cursor-pointer' }}"
                                >
                                    <flux:badge :color="$past ? 'zinc' : ($selected ? 'indigo' : 'sky')" :class="$past ? 'line-through opacity-60' : ''">
                                        @if ($selected)
                                            <flux:icon.check variant="micro" class="size-3.5" />
                                        @endif
                                        {{ \Carbon\Carbon::parse($slot['date'])->format('D, M j') }} · {{ $slot['time'] }}
                                    </flux:badge>
                                </button>
                            @endforeach
                        </div>

                        @if (! empty($selectedAvailability) && $this->exactTimeOptions !== [])
                            <flux:description class="mt-2 mb-1">Pick the exact time for the consult.</flux:description>
                            <div class="flex flex-wrap gap-2">
                                @foreach ($this->exactTimeOptions as $option)
                                    @php($timeSelected = $selectedExactTime === $option['value'])
                                    <button type="button"
                                        wire:click="selectExactTime('{{ $option['value'] }}')"
                                        class="cursor-pointer"
                                    >
                                        <flux:badge size="sm" :color="$timeSelected ? 'green' : 'zinc'">
                                            @if ($timeSelected)
                                                <flux:icon.check variant="micro" class="size-3.5" />
                                            @endif
                                            {{ $option['label'] }}
                                        </flux:badge>
                                    </button>
                                @endforeach
                            </div>
                        @endif

                        {{-- How the consult happens. Virtual books a Teams
                             meeting — the join link rides the calendar
                             invite. Pre-set from the homeowner's own pick on
                             the times page when they stated one. --}}
                        <flux:description class="mt-3 mb-1">Meeting type</flux:description>
                        <div class="flex gap-2">
                            <button type="button" wire:click="$set('consultMeetingType', 'in_person')" class="cursor-pointer">
                                <flux:badge :color="$consultMeetingType === 'in_person' ? 'indigo' : 'zinc'" icon="map-pin">
                                    In person
                                </flux:badge>
                            </button>
                            <button type="button" wire:click="$set('consultMeetingType', 'virtual')" class="cursor-pointer">
                                <flux:badge :color="$consultMeetingType === 'virtual' ? 'indigo' : 'zinc'" icon="video-camera">
                                    Video call (Teams)
                                </flux:badge>
                            </button>
                            @if (($this->lead?->lead_data['meeting_preference'] ?? null) === 'virtual')
                                <flux:badge size="sm" color="amber">They asked for a video call</flux:badge>
                            @endif
                        </div>

                        @if ($this->needsProjectName)
                            <div class="mt-3">
                                <flux:input
                                    wire:model.live.debounce.300ms="projectName"
                                    label="Project Name"
                                    type="text"
                                    placeholder="e.g. Kitchen Remodel"
                                    description="Booking this consult creates the client's project."
                                />
                            </div>
                        @endif
                    </flux:field>
                @endif

                <flux:editor wire:model.live.debounce.750ms="emailBody" label="Body" />
            </form>

        </flux:tab.panel>
        @endif
    </flux:tab.group>
    @endif

    <x-slot name="footer">
        <flux:spacer />
        @if ($lead?->exists)
            {{-- Status lives in the footer on Details; changes save immediately
                 (LeadCreate::updated). --}}
            <div x-show="activeLeadTab === 'details'" class="w-44">
                <flux:select
                    wire:model.live="lead_status"
                    variant="listbox"
                    placeholder="Choose Status..."
                >
                    @foreach(\App\Models\Lead::selectableStatuses() as $status)
                        {{-- A replied lead can't go back to New. --}}
                        <flux:select.option value="{{ $status['code'] }}"><flux:badge :color="$status['color']">{{ $status['label'] }}</flux:badge></flux:select.option>
                    @endforeach
                </flux:select>
            </div>
            @if (! $this->hasReplied)
                <flux:button wire:click="confirmRemove" variant="danger">Remove</flux:button>
                {{-- No Update button: status changes apply immediately (see
                     LeadCreate::updated), everything else on Details is read-only. --}}
                <div x-show="activeLeadTab === 'messages'" class="flex items-center gap-2">
                    {{-- One click: apply the Consult template and send it — asks
                         the homeowner to pick consultation times on the signed
                         picker (gated by Greg's and Patryk's calendars). --}}
                    <flux:button wire:click="sendConsultInvite" icon="calendar-days"
                        wire:confirm="Send the consultation invite to {{ $full_name ?: 'this homeowner' }}?"
                        wire:loading.attr="disabled" wire:target="sendConsultInvite, send_message"
                        :disabled="$this->sendBlockedReason !== null">
                        Send Consult Invite
                    </flux:button>
                    <flux:button type="submit" form="lead_messages_form" variant="primary"
                        wire:loading.attr="disabled" wire:target="send_message"
                        :disabled="$this->sendBlockedReason !== null">
                        Send Email
                    </flux:button>
                </div>
            @endif
        @else
            {{-- Locked while in flight — a double-tap on Create was minting
                 twin records before the first response landed. --}}
            <flux:button type="submit" form="lead_form_modal_form" variant="primary"
                wire:loading.attr="disabled" wire:target="save, edit, saveDespiteDuplicate">{{$view_text['button_text']}}</flux:button>
        @endif
    </x-slot>

    {{-- <livewire:users.user-create /> --}}
</x-form-modal>

{{-- DELETE LEAD CONFIRMATION --}}
<flux:modal wire:model.self="showLeadDelete" name="lead-delete-confirm" class="max-w-md">
    @if($lead?->exists)
        @php($impact = $this->deleteImpact)
        <div class="space-y-4">
            <flux:heading size="lg">Delete this lead?</flux:heading>

            <flux:text>{{ $full_name ?: 'This lead' }} — {{ $lead->origin }}{{ $lead->date ? ', ' . $lead->date->format('M j, Y') : '' }}</flux:text>

            @if($impact['schedule_link'] || $impact['booked_consult'])
                <flux:callout icon="exclamation-triangle" variant="warning" inline>
                    <flux:callout.text>
                        @if($impact['booked_consult'])
                            This lead has a <strong>booked consultation</strong>.
                        @endif
                        @if($impact['schedule_link'])
                            A <strong>scheduling link</strong> for this lead is still out in their email or texts — it keeps working only because deleted leads are kept recoverable.
                        @endif
                    </flux:callout.text>
                </flux:callout>
            @endif

            <ul class="list-disc pl-5 space-y-1 text-sm text-zinc-600 dark:text-zinc-300">
                <li>The lead, its statuses and its message history are removed.</li>

                @foreach($impact['clients'] as $clientName)
                    <li>The client record <strong>{{ $clientName }}</strong> is removed — it has no projects and no other contacts.</li>
                @endforeach

                @if($impact['user'])
                    <li>The contact <strong>{{ $impact['user'] }}</strong> and their portal access are removed — they aren't linked to anything else.</li>
                @elseif($lead->user)
                    <li>The contact {{ $lead->user->full_name }} keeps their account — they're linked to other records.</li>
                @endif
            </ul>

            <div class="flex justify-end gap-2 pt-2">
                <flux:button variant="ghost" wire:click="$set('showLeadDelete', false)">Cancel</flux:button>
                <flux:button variant="danger" icon="trash" wire:click="remove">Delete Lead</flux:button>
            </div>
        </div>
    @endif
</flux:modal>
</div>
