{{-- Details tab content — ONE source for both modal layouts (tabbed for New
     leads, tab-less for Replied leads whose only content this is). --}}
@php
    // Read the computed properties ONCE at the top level. Blaze's slot
    // compiler mis-scopes $this-> reads nested inside @if/@foreach within a
    // component slot (it warns "Undefined array key" while rendering).
    $missingContactInfo = $this->missingContactInfo;
    $addressCandidates = $this->addressCandidates;
    $lastEmailBounced = $this->lastEmailBounced;
    // Files that arrived with the enquiry (email leads): drawings, bid
    // forms, photos of the damage.
    $leadFiles = $this->lead?->lead_data['attachments'] ?? [];
    $leadFileId = $this->lead?->id;
    // Email replies the lead has sent since — filed by the crew@ ingest.
    $leadReplies = $this->lead?->lead_data['email_replies'] ?? [];
@endphp
    <form id="lead_form_modal_form" wire:submit="{{$view_text['form_submit']}}" class="space-y-3">
        <flux:textarea
            wire:model.live="message"
            disabled
            label="Message"
            rows="auto"
            resize="none"
        />

        {{-- What the enquiry arrived with — often the actual substance
             (drawings, a bid request form, photos of the damage). Images
             preview as thumbnails; documents open in a new tab. --}}
        @if ($leadFiles !== [] && $leadFileId)
            <div class="flex flex-wrap gap-2">
                @foreach ($leadFiles as $index => $file)
                    @if (str_starts_with((string) ($file['mime'] ?? ''), 'image/'))
                        <a href="{{ route('leads.file', [$leadFileId, $index]) }}" target="_blank" rel="noopener"
                            class="block size-20 overflow-hidden rounded-lg bg-zinc-100 dark:bg-zinc-800"
                            title="{{ $file['name'] ?? '' }}">
                            <img src="{{ route('leads.file', [$leadFileId, $index, 'thumb' => 1]) }}" alt="{{ $file['name'] ?? '' }}"
                                class="h-full w-full object-cover transition hover:opacity-90" loading="lazy" />
                        </a>
                    @else
                        <a href="{{ route('leads.file', [$leadFileId, $index]) }}" target="_blank" rel="noopener"
                            class="flex items-center gap-1.5 rounded-lg border border-zinc-200 px-2.5 py-1.5 text-sm text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-200 dark:hover:bg-zinc-800">
                            <flux:icon.document class="size-4 shrink-0 text-zinc-400" />
                            <span class="max-w-40 truncate">{{ $file['name'] ?? 'Attachment' }}</span>
                        </a>
                    @endif
                @endforeach
            </div>
        @endif

        {{-- What they wrote back — email replies land here via the crew@
             ingest, so the conversation is readable without opening Outlook.
             Newest first. --}}
        @if ($leadReplies !== [])
            <flux:field>
                <flux:label>Their replies</flux:label>
                <div class="space-y-2">
                    @foreach ($leadReplies as $reply)
                        <div class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                            <div class="mb-1 flex items-baseline justify-between gap-2">
                                <flux:heading size="sm" class="truncate">{{ $reply['subject'] ?? 'Reply' }}</flux:heading>
                                @if (! empty($reply['at']))
                                    <flux:text class="shrink-0 text-xs text-zinc-500">{{ \Carbon\Carbon::parse($reply['at'])->format('M j, g:ia') }}</flux:text>
                                @endif
                            </div>
                            <flux:text class="whitespace-pre-line text-sm text-zinc-600 dark:text-zinc-300">{{ $reply['body'] ?? '' }}</flux:text>
                        </div>
                    @endforeach
                </div>
            </flux:field>
        @endif

        {{-- The lead's booking page needs only the lead — no project, no
             account. Copy puts the short link on the clipboard for texts or
             any channel the composer doesn't cover. --}}
        @if ($this->lead?->exists)
            <div x-data
                x-on:lead-schedule-link-copied.window="
                    navigator.clipboard?.writeText($event.detail.url);
                    $el.querySelector('[data-copied]').classList.remove('hidden');
                    setTimeout(() => $el.querySelector('[data-copied]')?.classList.add('hidden'), 2000);
                "
                class="flex items-center gap-2">
                <flux:button size="sm" icon="link" wire:click="copyScheduleLink">
                    Copy schedule link
                </flux:button>
                @if (! $this->needsPhone && trim((string) ($this->lead->lead_data['phone'] ?? '')) !== '')
                    <flux:button size="sm" icon="chat-bubble-left-right" wire:click="textScheduleLink"
                        wire:loading.attr="disabled" wire:target="textScheduleLink">
                        Text it
                    </flux:button>
                @endif
                <span data-copied class="hidden text-sm text-green-600 dark:text-green-400">Copied</span>
            </div>
        @endif

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

        @if ($this->blockingContactInfo !== [])
            <flux:callout icon="exclamation-triangle" variant="warning" inline>
                <flux:callout.heading>Incomplete contact</flux:callout.heading>
                <flux:callout.text>
                    This enquiry is missing {{ collect($this->blockingContactInfo)->join(', ', ' and ') }}.
                    Add {{ count($this->blockingContactInfo) === 1 ? 'it' : 'them' }} to reply.
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
                        />
                    </div>
                @endif
            </flux:field>
        @else
            {{-- Manual entry found someone already reachable at this
                 phone/email — continue their history instead of forking it,
                 or create anyway deliberately. --}}
            @if ($this->duplicateMatch)
                <flux:callout icon="user" variant="warning" inline>
                    <flux:callout.heading>Already in the system</flux:callout.heading>
                    <flux:callout.text>{{ $this->duplicateMatch['label'] }}</flux:callout.text>
                    <x-slot:controls>
                        @if ($this->duplicateMatch['lead_id'])
                            <flux:button size="sm" wire:click="editLead({{ $this->duplicateMatch['lead_id'] }})">
                                Open existing lead
                            </flux:button>
                        @elseif ($this->duplicateMatch['client_id'])
                            <flux:button size="sm" href="{{ route('clients.show', $this->duplicateMatch['client_id']) }}" target="_blank">
                                View client
                            </flux:button>
                        @endif
                        <flux:button size="sm" variant="ghost" wire:click="saveDespiteDuplicate">
                            Create anyway
                        </flux:button>
                    </x-slot:controls>
                </flux:callout>
            @endif

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

            {{-- Full address, split like the client and project forms —
                 one blob field is how city/state/zip went missing. --}}
            <flux:input
                wire:model.live="address"
                label="Address"
                type="text"
                placeholder="Street address"
            />

            <div class="grid grid-cols-4 items-end gap-2">
                <div class="col-span-2">
                    <flux:input
                        wire:model.live="city"
                        label="City"
                        type="text"
                        placeholder="City"
                    />
                </div>
                <flux:input
                    wire:model.live="state"
                    label="State"
                    type="text"
                    placeholder="IL"
                />
                <flux:input
                    wire:model.live="zip"
                    label="Zip"
                    type="text"
                    placeholder="Zip"
                />
            </div>
        @endif

    </form>

    @if ($lead?->id)
        <div class="mt-4">
            <livewire:projects.email-tracking-table :lead-id="$lead->id" :key="'lead-tracking-' . $lead->id" />
        </div>
    @endif
