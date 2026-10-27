<div x-init="document.addEventListener('livewire:navigating', () => { if ($wire.showModal) $wire.showModal = false })">
    <flux:modal wire:model="showModal" name="new-sms-thread" class="max-w-lg">
        {{-- Rendered only while open. The "Name or Number" box searches on the
             server (TextRecipientSearch), at most 20 matches at a time. --}}
        @if($showModal)
        <div class="space-y-4">
            <flux:heading size="lg">New Group Message</flux:heading>

            <form class="space-y-4">
                {{-- Recipient type --}}
                <flux:radio.group wire:model.live="recipientType" variant="segmented" size="sm">
                    <flux:radio value="client" label="Client" icon="user" />
                    <flux:radio value="vendor" label="Vendor" icon="briefcase" />
                </flux:radio.group>

                {{-- One box for both: a person, the whole household (or company), or a typed number.
                     :filter="false" — searchResults() already did the matching on the server. --}}
                <flux:field>
                    <flux:autocomplete
                        label="Name or Number"
                        wire:model.live.debounce.300ms="search"
                        :filter="false"
                        placeholder="{{ $recipientType === 'client' ? 'Search a client or type a number…' : 'Search a vendor or type a number…' }}"
                        icon="magnifying-glass"
                        clearable
                        data-recipient-search
                    >
                        @forelse ($this->searchResults as $result)
                            <flux:autocomplete.item
                                wire:key="recipient-option-{{ $result['key'] }}"
                                value="{{ $result['key'] }}"
                                wire:click="pick('{{ $result['key'] }}')"
                            >
                                <div class="flex items-center justify-between w-full gap-2 min-w-0">
                                    <div class="min-w-0">
                                        <div class="truncate">{{ $result['name'] }}</div>
                                        @if ($result['detail'] !== '')
                                            <div class="truncate text-xs text-zinc-500 dark:text-zinc-400">{{ $result['detail'] }}</div>
                                        @endif
                                    </div>
                                    <span class="flex items-center gap-1.5 shrink-0">
                                        @if (in_array($result['kind'], ['Household', 'Company'], true))
                                            <flux:badge size="sm" color="zinc" icon="user-group">All</flux:badge>
                                        @elseif ($result['phone'])
                                            <span class="text-xs text-zinc-400 dark:text-zinc-500">{{ phone_display($result['phone']) }}</span>
                                        @endif
                                    </span>
                                </div>
                            </flux:autocomplete.item>
                        @empty
                            <flux:autocomplete.item value="" disabled>
                                {{ mb_strlen(trim($search)) < 2 ? 'Type a name or a phone number…' : 'No matches — type the full phone number' }}
                            </flux:autocomplete.item>
                        @endforelse
                    </flux:autocomplete>
                    @error('newNumber')
                        <flux:error>{{ $message }}</flux:error>
                    @enderror
                    @error('clientId')
                        <flux:error>{{ $message }}</flux:error>
                    @enderror
                    @error('vendorId')
                        <flux:error>{{ $message }}</flux:error>
                    @enderror
                </flux:field>

                @if ((($recipientType === 'client' && $clientId) || ($recipientType === 'vendor' && $vendorId)) && count($recipientPresetOptions) > 0)
                    <flux:field>
                        <flux:select label="Conversation" wire:model.live="recipientPreset" variant="listbox" placeholder="Choose recipients...">
                            @foreach($recipientPresetOptions as $option)
                                <flux:select.option value="{{ $option['value'] }}">{{ $option['label'] }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </flux:field>
                @endif

                {{-- Existing thread warning --}}
                @if ($existingThreadId)
                    <flux:callout variant="warning" icon="exclamation-triangle">
                        <flux:callout.heading>Thread already exists</flux:callout.heading>
                        <flux:callout.text>A message thread already exists for this participant group.</flux:callout.text>
                        <x-slot:actions>
                            <flux:button size="sm" variant="primary" wire:click="goToExistingThread">
                                Go to Thread
                            </flux:button>
                        </x-slot:actions>
                    </flux:callout>
                @endif

                    {{-- Recipients list --}}
                    @if (count($recipients) > 0)
                        <flux:field>
                            <flux:label>Recipients ({{ count($recipients) }})</flux:label>
                            <div class="space-y-1">
                                @foreach ($recipients as $index => $phone)
                                    <div wire:key="recipient-{{ $index }}" class="flex items-center gap-2 bg-zinc-50 dark:bg-zinc-800/50 rounded-lg px-3 py-2">
                                        <flux:icon name="phone" class="size-4 text-zinc-400" />
                                        @if ($phone['label'])
                                            <span class="text-sm font-medium text-zinc-700 dark:text-zinc-300">{{ $phone['label'] }}</span>
                                            <span class="text-xs text-zinc-400 dark:text-zinc-500">{{ $phone['display'] }}</span>
                                        @else
                                            <span class="text-sm text-zinc-700 dark:text-zinc-300">{{ $phone['display'] }}</span>
                                        @endif
                                        <button type="button" wire:click="removeRecipient({{ $index }})" class="ml-auto text-zinc-400 hover:text-red-500 transition-colors">
                                            <flux:icon name="x-mark" class="size-4" />
                                        </button>
                                    </div>
                                @endforeach
                            </div>
                        </flux:field>
                    @endif

                    {{-- Message --}}
                    @if (count($recipients) > 0 && ! $existingThreadId)
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
                            <flux:button wire:click="send" variant="primary" icon="paper-airplane" wire:loading.attr="disabled" wire:target="send">
                                Send Message
                            </flux:button>
                        </div>
                    @endif
            </form>
        </div>
    @endif
    </flux:modal>
</div>
