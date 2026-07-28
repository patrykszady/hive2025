{{-- Details tab content — ONE source for both modal layouts (tabbed for New
     leads, tab-less for Replied leads whose only content this is). --}}
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

    </form>

    @if ($lead?->id)
        <div class="mt-4">
            <livewire:projects.email-tracking-table :lead-id="$lead->id" :key="'lead-tracking-' . $lead->id" />
        </div>
    @endif
