<div>
    <flux:modal wire:model="showModal" name="new-sms-thread" class="max-w-lg">
        <div class="space-y-4">
            <flux:heading size="lg">New Group Message</flux:heading>

            <form wire:submit="send" class="space-y-4">
                {{-- Client --}}
                <flux:field>
                    <flux:select label="Client" wire:model.live="clientId" variant="listbox" searchable placeholder="Choose client...">
                        <x-slot name="search">
                            <flux:select.search placeholder="Search..." />
                        </x-slot>

                        @foreach($this->clients as $client)
                            <flux:select.option value="{{ $client->id }}">{{ $client->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    @error('clientId')
                        <flux:error>{{ $message }}</flux:error>
                    @enderror
                </flux:field>

                {{-- Phone Numbers (from client) --}}
                @if ($existingThreadId)
                    <flux:callout variant="warning" icon="exclamation-triangle">
                        <flux:callout.heading>Thread already exists</flux:callout.heading>
                        <flux:callout.text>A message thread already exists for this client.</flux:callout.text>
                        <x-slot:actions>
                            <flux:button size="sm" variant="primary" wire:click="goToExistingThread">
                                Go to Thread
                            </flux:button>
                        </x-slot:actions>
                    </flux:callout>
                @elseif ($clientId && count($this->clientPhoneNumbers) > 0)
                    <flux:field>
                        <flux:label>Phone Numbers</flux:label>
                        <div class="space-y-1">
                            @foreach ($this->clientPhoneNumbers as $phone)
                                <div class="flex items-center gap-2 bg-zinc-50 dark:bg-zinc-800/50 rounded-lg px-3 py-2">
                                    <flux:icon name="phone" class="size-4 text-zinc-400" />
                                    <span class="text-sm text-zinc-700 dark:text-zinc-300">{{ $phone['display'] }}</span>
                                    <span class="text-xs text-zinc-400 dark:text-zinc-500">{{ $phone['label'] }}</span>
                                </div>
                            @endforeach
                        </div>
                    </flux:field>
                @elseif ($clientId)
                    <flux:field>
                        <flux:label>Phone Numbers</flux:label>
                        <p class="text-sm text-zinc-400 dark:text-zinc-500">No phone numbers on file for this client.</p>
                    </flux:field>
                @endif

                {{-- Message --}}
                @if (! $existingThreadId && $clientId && count($this->clientPhoneNumbers) > 0)
                <flux:field>
                    <flux:label>Message</flux:label>
                    <flux:textarea
                        wire:model="message"
                        placeholder="Type your message..."
                        rows="4"
                    />
                    <flux:description>
                        <span class="{{ strlen($message) > 1600 ? 'text-red-500' : '' }}">
                            {{ strlen($message) }} / 1600
                        </span>
                    </flux:description>
                    @error('message')
                        <flux:error>{{ $message }}</flux:error>
                    @enderror
                </flux:field>

                <div class="flex justify-end gap-2">
                    <flux:button wire:click="$set('showModal', false)">Cancel</flux:button>
                    <flux:button type="submit" variant="primary" icon="paper-airplane" wire:loading.attr="disabled">
                        Send Message
                    </flux:button>
                </div>
                @endif
            </form>
        </div>
    </flux:modal>
</div>
