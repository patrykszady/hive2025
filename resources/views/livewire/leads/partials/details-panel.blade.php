{{-- Details tab content — ONE source for both modal layouts (tabbed for New
     leads, tab-less for Replied leads whose only content this is). --}}
@php
    // Read the computed properties ONCE at the top level. Blaze's slot
    // compiler mis-scopes $this-> reads nested inside @if/@foreach within a
    // component slot (it warns "Undefined array key" while rendering).
    $missingContactInfo = $this->missingContactInfo;
    $addressCandidates = $this->addressCandidates;
    $lastEmailBounced = $this->lastEmailBounced;
@endphp
    <form id="lead_form_modal_form" wire:submit="{{$view_text['form_submit']}}" class="space-y-3">
        <flux:textarea
            wire:model.live="message"
            disabled
            label="Message"
            rows="auto"
            resize="none"
        />

        <div class="flex gap-4 items-end">
            <div class="flex-1">
                <flux:input
                    wire:model.live="date"
                    disabled
                    label="Date"
                    type="date"
                />
            </div>

            <div class="flex-1">
                <flux:input
                    wire:model.live="origin"
                    disabled
                    label="Origin"
                    type="text"
                />
            </div>
        </div>

        {{-- Say what's still needed before this lead can be replied to. The
             Message tab appears once nothing is outstanding. --}}
        {{-- A bounce means the reply never arrived, so the lead is only
             "Replied" on paper — say so, and the Message tab is back. --}}
        @if ($lastEmailBounced)
            <flux:callout icon="exclamation-triangle" variant="danger" inline>
                <flux:callout.heading>Email bounced</flux:callout.heading>
                <flux:callout.text>
                    Our last reply didn't reach {{ $full_name ?: 'this contact' }}. Check the
                    address and send again from the Message tab.
                </flux:callout.text>
            </flux:callout>
        @endif

        @if ($missingContactInfo !== [])
            <flux:callout icon="exclamation-triangle" variant="warning" inline>
                <flux:callout.heading>Incomplete contact</flux:callout.heading>
                <flux:callout.text>
                    This enquiry is missing {{ collect($missingContactInfo)->join(', ', ' and ') }}.
                    Add {{ count($missingContactInfo) === 1 ? 'it' : 'them' }} to reply.
                </flux:callout.text>
            </flux:callout>

            {{-- The same street can be a real address in more than one town
                 ("511 Sherwood Dr" is both Addison and Streamwood), so offer
                 the matches near the office rather than picking one. --}}
            @if ($addressCandidates !== [])
                <flux:field>
                    <flux:label>Which address is this?</flux:label>
                    <flux:description class="mb-2">
                        Matches near the office, closest first.
                    </flux:description>
                    <div class="flex flex-col gap-2">
                        @foreach ($addressCandidates as $index => $candidate)
                            <flux:button
                                size="sm"
                                class="justify-start"
                                wire:click="selectAddressCandidate({{ $index }})"
                                wire:loading.attr="disabled"
                                wire:target="selectAddressCandidate"
                            >
                                {{ $candidate['address'] }}, {{ $candidate['city'] }}, {{ $candidate['state'] }} {{ $candidate['zip_code'] }}
                                <span class="ms-2 text-zinc-500">{{ $candidate['miles'] }} mi</span>
                            </flux:button>
                        @endforeach
                    </div>
                </flux:field>
            @endif
        @endif

        {{-- The enquiry carried no phone. We don't invent one, so this is where
             it gets filled in. --}}
        @if ($this->needsPhone)
            <flux:field>
                <flux:label>Phone Number</flux:label>
                <flux:description>
                    This enquiry didn't include a phone number. Add one to reply.
                </flux:description>
                <flux:input.group>
                    <flux:input
                        wire:model="phoneEntry"
                        type="tel"
                        placeholder="(224) 555-0134"
                        wire:keydown.enter.prevent="saveContactPhone"
                    />
                    <flux:button wire:click="saveContactPhone" wire:loading.attr="disabled" wire:target="saveContactPhone">
                        Save
                    </flux:button>
                </flux:input.group>
                <flux:error name="phoneEntry" />
            </flux:field>
        @endif

        @if ($client)
            <flux:field>
                <flux:label>Client</flux:label>
                <a href="{{ route('clients.show', $client) }}" target="_blank" rel="noopener" class="block rounded-lg border border-zinc-200 dark:border-zinc-700 p-3 space-y-1 hover:bg-zinc-50 dark:hover:bg-zinc-800 transition">
                    <flux:heading size="sm">{{ $client->name }}</flux:heading>
                    @if ($client->address)
                        <flux:text class="text-zinc-500">
                            @php($cityStateZip = collect([$client->city, trim(($client->state ?? '').' '.($client->zip_code ?? ''))])->filter()->implode(', '))
                            {{ $client->address }}@if ($cityStateZip !== ''), {{ $cityStateZip }}@endif
                        </flux:text>
                    @endif
                    @foreach ($client->users as $clientUser)
                        @if ($clientUser->email)
                            <flux:text class="text-zinc-500">{{ $clientUser->email }}</flux:text>
                        @endif
                        @if ($clientUser->cell_phone)
                            <flux:text class="text-zinc-500">{{ phone_display($clientUser->cell_phone) }}</flux:text>
                        @endif
                    @endforeach
                </a>
            </flux:field>
        @elseif ($user)
            @php($linkedClient = $user->clients->first())
            <flux:field>
                <flux:label>User</flux:label>
                @if ($linkedClient)
                    <a href="{{ route('clients.show', $linkedClient) }}" target="_blank" rel="noopener" class="block rounded-lg border border-zinc-200 dark:border-zinc-700 p-3 space-y-1 hover:bg-zinc-50 dark:hover:bg-zinc-800 transition">
                        <flux:heading size="sm">{{ $user->full_name }}</flux:heading>
                        @if ($user->email)
                            <flux:text class="text-zinc-500">{{ $user->email }}</flux:text>
                        @endif
                        @if ($user->cell_phone)
                            <flux:text class="text-zinc-500">{{ phone_display($user->cell_phone) }}</flux:text>
                        @endif
                    </a>
                @else
                    <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 p-3 space-y-1">
                        <flux:heading size="sm">{{ $user->full_name }}</flux:heading>
                        @if ($user->email)
                            <flux:text class="text-zinc-500">{{ $user->email }}</flux:text>
                        @endif
                        @if ($user->cell_phone)
                            <flux:text class="text-zinc-500">{{ phone_display($user->cell_phone) }}</flux:text>
                        @endif
                    </div>
                @endif

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
        @else
            <flux:input.group label="User">
                <flux:input
                    wire:model.live="full_name"
                    type="text"
                    placeholder="Lead User"
                />

                <flux:button icon="plus">
                    Add User
                </flux:button>
            </flux:input.group>

            {{-- Hidden while the gate prompt is up: two boxes for the same
                 number is confusing, and this one is the legacy edit field.
                 type=tel, not number — a phone isn't a quantity (number strips
                 leading zeros and shows spinners). --}}
            @if (! $this->needsPhone)
                <flux:input
                    wire:model.live="phone"
                    label="Phone"
                    type="tel"
                    placeholder="Phone"
                />
            @endif

            <flux:input
                wire:model.live="email"
                label="Email"
                type="text"
                placeholder="Email"
            />

            <flux:input
                wire:model.live="address"
                label="Address"
                type="text"
                placeholder="Address"
            />
        @endif

    </form>

    @if ($lead?->id)
        <div class="mt-4">
            <livewire:projects.email-tracking-table :lead-id="$lead->id" :key="'lead-tracking-' . $lead->id" />
        </div>
    @endif
