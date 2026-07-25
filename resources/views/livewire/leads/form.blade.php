{{-- Single root: the delete confirmation is a sibling of the lead modal. --}}
<div>
<x-form-modal name="lead_form_modal" title="Lead">
    <flux:tab.group>
        <flux:tabs>
            <flux:tab name="details">Details</flux:tab>
            <flux:tab name="messages">Messages</flux:tab>
        </flux:tabs>

        <flux:tab.panel name="details">
            <form id="lead_form_modal_form" wire:submit="{{$view_text['form_submit']}}" class="space-y-4">
                <flux:textarea
                    wire:model.live="message"
                    disabled
                    label="Message"
                    rows="auto"
                    resize="none"
                />

                <flux:input
                    wire:model.live="date"
                    disabled
                    label="Date"
                    type="date"
                />

                <flux:input
                    wire:model.live="origin"
                    disabled
                    label="Origin"
                    type="text"
                />

                @if ($client)
                    <flux:field>
                        <flux:label>Client</flux:label>
                        <a href="{{ route('clients.show', $client) }}" wire:navigate.hover class="block rounded-lg border border-zinc-200 dark:border-zinc-700 p-3 space-y-1 hover:bg-zinc-50 dark:hover:bg-zinc-800 transition">
                            <flux:heading size="sm">{{ $client->name }}</flux:heading>
                            @if ($client->address)
                                <flux:text class="text-zinc-500">
                                    {{ $client->address }}@if ($client->city), {{ $client->city }}@endif @if ($client->state) {{ $client->state }}@endif @if ($client->zip_code) {{ $client->zip_code }}@endif
                                </flux:text>
                            @endif
                            @foreach ($client->users as $clientUser)
                                @if ($clientUser->email)
                                    <flux:text class="text-zinc-500">{{ $clientUser->email }}</flux:text>
                                @endif
                                @if ($clientUser->cell_phone)
                                    <flux:text class="text-zinc-500">{{ $clientUser->cell_phone }}</flux:text>
                                @endif
                            @endforeach
                        </a>
                    </flux:field>
                @elseif ($user)
                    @php($linkedClient = $user->clients->first())
                    <flux:field>
                        <flux:label>User</flux:label>
                        @if ($linkedClient)
                            <a href="{{ route('clients.show', $linkedClient) }}" wire:navigate.hover class="block rounded-lg border border-zinc-200 dark:border-zinc-700 p-3 space-y-1 hover:bg-zinc-50 dark:hover:bg-zinc-800 transition">
                                <flux:heading size="sm">{{ $user->full_name }}</flux:heading>
                                @if ($user->email)
                                    <flux:text class="text-zinc-500">{{ $user->email }}</flux:text>
                                @endif
                                @if ($user->cell_phone)
                                    <flux:text class="text-zinc-500">{{ $user->cell_phone }}</flux:text>
                                @endif
                            </a>
                        @else
                            <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 p-3 space-y-1">
                                <flux:heading size="sm">{{ $user->full_name }}</flux:heading>
                                @if ($user->email)
                                    <flux:text class="text-zinc-500">{{ $user->email }}</flux:text>
                                @endif
                                @if ($user->cell_phone)
                                    <flux:text class="text-zinc-500">{{ $user->cell_phone }}</flux:text>
                                @endif
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

                    <flux:input
                        wire:model.live="phone"
                        label="Phone"
                        type="number"
                        placeholder="Phone"
                    />

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

                {{--  id="new_project_id"  --}}
                <flux:select
                    wire:model.live="lead_status"
                    label="Status"
                    variant="listbox"
                    placeholder="Choose Status..."
                >
                    @foreach(\App\Models\Lead::selectableStatuses() as $status)
                        <flux:select.option value="{{ $status['code'] }}"><flux:badge :color="$status['color']">{{ $status['label'] }}</flux:badge></flux:select.option>
                    @endforeach
                </flux:select>
            </form>
        </flux:tab.panel>
        <flux:tab.panel name="messages">
            <form wire:submit="send_message" class="space-y-4">
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
                        <flux:description class="mb-2">Click to select a slot for the email.</flux:description>
                        <div class="flex flex-wrap gap-2">
                            @foreach ($availability as $index => $slot)
                                @php($selected = in_array($index, $selectedAvailability, true))
                                <button type="button"
                                    wire:click="insertAvailabilitySlot({{ $index }})"
                                    class="cursor-pointer"
                                >
                                    <flux:badge :color="$selected ? 'green' : 'sky'">
                                        @if ($selected)
                                            <flux:icon.check variant="micro" class="size-3.5" />
                                        @endif
                                        {{ \Carbon\Carbon::parse($slot['date'])->format('D, M j') }} · {{ $slot['time'] }}
                                    </flux:badge>
                                </button>
                            @endforeach
                        </div>
                    </flux:field>
                @endif

                <flux:editor wire:model.live="emailBody" label="Body" />

                <div class="flex justify-end pt-2">
                    <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="send_message">
                        Send Email
                    </flux:button>
                </div>
            </form>

            @if ($lead?->id)
                <div class="mt-6">
                    <livewire:projects.email-tracking-table :lead-id="$lead->id" :key="'lead-tracking-' . $lead->id" />
                </div>
            @endif
        </flux:tab.panel>
    </flux:tab.group>

    <x-slot name="footer">
        <flux:spacer />
        <flux:button wire:click="confirmRemove" variant="danger">Remove</flux:button>
        <flux:button type="submit" form="lead_form_modal_form" variant="primary">{{$view_text['button_text']}}</flux:button>
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
