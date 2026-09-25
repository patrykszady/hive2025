{{-- Single root: the delete confirmation is a sibling of the lead modal. --}}
<div>
<x-form-modal name="lead_form_modal" title="Lead" x-data="{ activeLeadTab: 'details' }"
    x-on:lead-modal-opened.window="activeLeadTab = 'details'">
    {{-- The Message tab stays available after a reply: a consult email is
         re-sent from here when the first one went unanswered, or a follow-up
         is written (2026-09-15: Jeanne Bondi, Replied, needed the email again
         and the only way in was flipping the status back to New). What a
         reply still locks is Remove — see the footer. --}}
    <flux:tab.group>
        {{-- The footer's controls follow the selected tab (status on Details,
             Send on Message), so the tab state is bound, not tracked by
             click: a click handler missed keyboard and programmatic tab
             changes and left the Message tab open with no Send button. --}}
        <flux:tabs x-model="activeLeadTab">
            <flux:tab name="details">Details</flux:tab>
            {{-- Incomplete contact: finish it on Details first. We never invent
                 the missing pieces, so the blanks are the prompt. --}}
            @if ($this->blockingContactInfo === [])
                <flux:tab name="messages">Message</flux:tab>
            @endif
        </flux:tabs>

        <flux:tab.panel name="details" class="pt-4">
            @include('livewire.leads.partials.details-panel')
        </flux:tab.panel>
        @if ($this->blockingContactInfo === [])
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

                {{-- Booked consult: what's on the calendar right now, and that
                     picking any slot/time below and sending MOVES it. --}}
                @if ($this->bookedConsult)
                    <flux:callout :color="$this->bookedConsult['past'] ? 'amber' : null" icon="calendar-days">
                        <flux:callout.text>
                            @if ($this->bookedConsult['past'])
                                Consult was scheduled for <strong>{{ $this->bookedConsult['label'] }}</strong>
                                ({{ $this->bookedConsult['virtual'] ? 'video call' : 'in person' }}) — that date has passed.
                                Propose a new day below and send to rebook.
                            @else
                                Consult booked: <strong>{{ $this->bookedConsult['label'] }}</strong>
                                ({{ $this->bookedConsult['virtual'] ? 'video call' : 'in person' }}).
                                To reschedule, pick a time below and send — the calendar invite moves with it.
                            @endif
                        </flux:callout.text>
                    </flux:callout>
                @endif

                {{-- GS-side scheduling: propose any weekday, not just the slots
                     the homeowner shared. Picking a date adds it as a slot; the
                     exact-time chips below are gated by Greg's and Patryk's
                     calendars, same as every other consult time. --}}
                <flux:field>
                    <flux:label>{{ $this->bookedConsult ? 'Propose a different day' : 'Propose a day' }}</flux:label>
                    <flux:input type="date" wire:model.live="proposeDate" min="{{ now(\App\Livewire\Leads\PickTimes::timezone())->toDateString() }}" class="max-w-48" />
                    <flux:error name="proposeDate" />
                </flux:field>

                {{-- Hot lead: let their own picker offer times starting an hour
                     from now instead of the usual three days. --}}
                <flux:switch
                    wire:model.live="consultWithinTheHour"
                    label="Consult within the hour"
                    description="Lets the homeowner pick a time as soon as an hour from now instead of three days."
                />

                @if (! empty($availability))
                    <flux:field>
                        <flux:label>Availability</flux:label>
                        @php($problems = $this->slotProblems)
                        @if ($this->hasUsableAvailability)
                            <flux:description class="mb-2">Click to select a slot for the email.</flux:description>
                        @elseif (in_array('booked', $problems, true))
                            <flux:description class="mb-2">The calendars are booked for {{ in_array('past', $problems, true) ? 'the rest of' : 'all of' }} these preferred times — the email asks {{ $full_name ?: 'the client' }} to pick new ones instead.</flux:description>
                        @else
                            <flux:description class="mb-2">These preferred times have passed — the email asks {{ $full_name ?: 'the client' }} to pick new ones instead.</flux:description>
                        @endif
                        <div class="flex flex-wrap gap-2">
                            @foreach ($availability as $index => $slot)
                                @php($selected = in_array($index, $selectedAvailability, true))
                                @php($problem = $problems[$index] ?? null)
                                {{-- A slot that has passed is struck through; one the
                                     calendars have filled since the homeowner picked it
                                     says so — neither can be selected. --}}
                                <button type="button"
                                    wire:click="insertAvailabilitySlot({{ $index }})"
                                    @disabled($problem !== null)
                                    title="{{ $problem === 'booked' ? 'No free start left — the calendars are booked then.' : '' }}"
                                    class="{{ $problem !== null ? 'cursor-not-allowed' : 'cursor-pointer' }}"
                                >
                                    <flux:badge :color="$problem !== null ? 'zinc' : ($selected ? 'indigo' : 'sky')" :class="$problem === 'past' ? 'line-through opacity-60' : ($problem === 'booked' ? 'opacity-60' : '')">
                                        @if ($selected)
                                            <flux:icon.check variant="micro" class="size-3.5" />
                                        @endif
                                        {{ \Carbon\Carbon::parse($slot['date'])->format('D, M j') }} · {{ $slot['time'] }}
                                        @if ($problem === 'booked')
                                            <span class="ml-1 font-normal">· booked</span>
                                        @endif
                                    </flux:badge>
                                </button>
                            @endforeach
                        </div>

                        {{-- A window the homeowner picked can fill up on Patryk's or
                             Greg's calendar between their pick and this email: say so,
                             instead of a slot with nothing under it and Send blocked. --}}
                        @if (! empty($selectedAvailability) && $this->exactTimeOptions === [] && $this->selectedSlotWindowKnown)
                            <flux:callout variant="warning" icon="calendar" class="mt-2" heading="No free start left in this window — the calendars are booked then. Pick another of their slots, or propose a different time." />
                        @endif
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

                        {{-- Where the consult goes: one of the client's projects
                             (a returning client's new job may well be a new project
                             while an old one is still open) or a new one. --}}
                        @if ($this->selectedExactTime !== null && $this->client)
                            <div class="mt-3">
                                {{-- One box: pick one of the client's projects from the
                                     suggestions, or type a name and a new project is created. --}}
                                <flux:autocomplete
                                    wire:model.live.debounce.300ms="projectName"
                                    label="Project"
                                    placeholder="Pick a project or type a new name"
                                >
                                    {{-- Each suggestion shows the project's stage as the badge the
                                         projects table draws for it; picking one puts the plain
                                         "Name — Stage" text in the box (the value), which is what
                                         resolvedConsultProject() reads back. --}}
                                    @foreach ($this->consultProjectOptions as $option)
                                        <flux:autocomplete.item wire:key="consult-project-{{ $option['id'] }}" value="{{ $option['label'] }}" label="{{ $option['label'] }}" class="gap-2">
                                            <span class="truncate">{{ $option['name'] }}</span>
                                            <flux:badge size="sm" inset="top bottom" :color="$option['color']">{{ $option['stage'] }}</flux:badge>
                                        </flux:autocomplete.item>
                                    @endforeach
                                </flux:autocomplete>
                            </div>
                        @endif
                    </flux:field>
                @endif

                <flux:editor wire:model.live.debounce.750ms="emailBody" label="Body" />
            </form>

        </flux:tab.panel>
        @endif
    </flux:tab.group>

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
            {{-- A replied lead is a conversation on record: it can be emailed
                 again but not removed. --}}
            @if (! $this->hasReplied)
                <flux:button wire:click="confirmRemove" variant="danger">Remove</flux:button>
            @endif
            @if ($this->blockingContactInfo === [])
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
                        @if(($impact['consults'] ?? []) !== [])
                            The <strong>consultation on {{ collect($impact['consults'])->join(', ', ' and ') }}</strong> will be cancelled and the calendar invite withdrawn.
                        @elseif($impact['booked_consult'])
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

                @foreach($impact['consult_projects'] ?? [] as $projectName)
                    <li>The project <strong>{{ $projectName }}</strong>, created for that consultation and holding nothing else, is removed.</li>
                @endforeach

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
