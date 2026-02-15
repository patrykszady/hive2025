<div class="flex-1 min-h-0 flex flex-col">
    @if ($this->thread)
        {{-- Header --}}
        @php
            $headerTitle = 'Group Message';
            if ($this->thread->client) {
                $headerTitle = $this->thread->client->name;
            } elseif ($this->thread->project) {
                $headerTitle = $this->thread->project->address;
            } else {
                $participants = $this->thread->participants ?? [];
                if (count($participants) > 0) {
                    $headerTitle = collect($participants)
                        ->map(fn ($p) => $this->resolvePhoneDisplay($p))
                        ->implode(', ');
                }
            }
        @endphp
        <div class="border-b border-zinc-200 dark:border-zinc-700 px-4 py-2">
            {{-- Title row --}}
            <div class="flex items-center gap-1.5" style="padding-left: 2rem" x-bind:style="window.innerWidth >= 1024 ? 'padding-left: 0' : 'padding-left: 2rem'">
                {{-- Mobile back button --}}
                <flux:button
                    type="button"
                    variant="subtle"
                    size="sm"
                    square
                    icon="arrow-left"
                    class="lg:hidden shrink-0"
                    wire:click="$parent.set('threadId', null)"
                    aria-label="Back to conversations"
                />
                @if ($this->thread->client)
                    <flux:heading size="lg" class="mb-0 truncate flex-1">
                        <a href="{{ route('clients.show', $this->thread->client_id) }}" wire:navigate.hover class="hover:underline">{{ $headerTitle }}</a>
                    </flux:heading>
                @else
                    <flux:heading size="lg" class="mb-0 truncate flex-1">{{ $headerTitle }}</flux:heading>
                @endif
                @if ($this->thread->project)
                    <flux:button size="sm" variant="ghost" href="{{ route('projects.show', $this->thread->project_id) }}" wire:navigate.hover icon="arrow-top-right-on-square">
                        Project
                    </flux:button>
                @endif
            </div>

            @if (! $isClientUser)
                @php
                    $callableContacts = collect();

                    if ($this->thread->client && $this->thread->client->users->isNotEmpty()) {
                        foreach ($this->thread->client->users as $user) {
                            $raw = $user->getRawOriginal('cell_phone');
                            if (! $raw) continue;
                            $digits = preg_replace('/[^0-9]/', '', $raw);
                            if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
                                $digits = substr($digits, 1);
                            }
                            $display = strlen($digits) === 10
                                ? '(' . substr($digits, 0, 3) . ') ' . substr($digits, 3, 3) . '-' . substr($digits, 6)
                                : $raw;
                            $callableContacts->push([
                                'name' => $user->first_name,
                                'e164' => $user->routeNotificationForTelnyx(),
                                'display' => $display,
                            ]);
                        }
                    } else {
                        $participants = $this->thread->participants ?? [];
                        foreach ($participants as $phone) {
                            $name = $this->resolvePhoneDisplay($phone);
                            $digits = preg_replace('/[^0-9]/', '', $phone);
                            if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
                                $d10 = substr($digits, 1);
                            } else {
                                $d10 = $digits;
                            }
                            $displayPhone = strlen($d10) === 10
                                ? '(' . substr($d10, 0, 3) . ') ' . substr($d10, 3, 3) . '-' . substr($d10, 6)
                                : $phone;
                            $callableContacts->push([
                                'name' => $name !== $displayPhone ? $name : null,
                                'e164' => $phone,
                                'display' => $displayPhone,
                            ]);
                        }
                    }
                @endphp

                @if ($callableContacts->isNotEmpty())
                    <div class="flex flex-wrap gap-x-4 gap-y-0.5 mt-1">
                        @foreach ($callableContacts as $contact)
                            <div class="flex items-center gap-1.5">
                                @if ($contact['name'] && $contact['name'] !== $headerTitle)
                                    <span class="text-xs font-medium text-zinc-600 dark:text-zinc-300">{{ $contact['name'] }}</span>
                                @endif
                                <button
                                    type="button"
                                    wire:click="initiateCall('{{ $contact['e164'] }}')"
                                    wire:loading.attr="disabled"
                                    wire:target="initiateCall"
                                    class="inline-flex items-center gap-1 text-xs text-indigo-500 hover:text-indigo-600 dark:text-indigo-400 hover:underline cursor-pointer disabled:opacity-50"
                                    title="Call {{ $contact['name'] ?? $contact['display'] }} via your phone"
                                >
                                    <flux:icon name="phone" class="size-3" />
                                    {{ $contact['display'] }}
                                </button>
                            </div>
                        @endforeach
                    </div>
                @endif
            @endif
        </div>

        {{-- Messages --}}
        @php
            // Build phone-to-name lookup — start with client user first names,
            // then merge the component's resolvePhoneDisplay map as fallback.
            $clientPhoneNames = collect();
            if ($this->thread->client) {
                foreach ($this->thread->client->users as $user) {
                    $telnyx = $user->routeNotificationForTelnyx();
                    if ($telnyx) {
                        $clientPhoneNames[$telnyx] = $user->first_name;
                    }
                }
                $rawHome = $this->thread->client->getRawOriginal('home_phone');
                if ($rawHome) {
                    $formatted = \App\Services\GroupSmsService::formatE164($rawHome);
                    if (! $clientPhoneNames->has($formatted)) {
                        $clientPhoneNames[$formatted] = $this->thread->client->name;
                    }
                }
            }
            // $phoneNameMap comes from render() via resolvePhoneDisplay — covers vendors/users.
            // Client first-name entries take precedence.
            $phoneNameMap = array_merge($phoneNameMap, $clientPhoneNames->all());

            $tz = browser_timezone();
            $now = now($tz);
            $todayDate = $now->toDateString();
            $yesterdayDate = $now->copy()->subDay()->toDateString();
        @endphp

        <div class="relative flex-1 min-h-0">
            <div class="sms-fade-overlay top"></div>
        <div
            class="sms-messages h-full overflow-y-auto flex flex-col-reverse gap-3 px-2 pt-6 pb-6"
        >
            @forelse ($this->smsMessages->reverse() as $msg)
                @php
                    $msgLocal = $msg->created_at->copy()->setTimezone($tz);
                    $msgDate = $msgLocal->toDateString();
                    if ($msgDate === $todayDate) {
                        $timeLabel = $msgLocal->format('g:i A');
                    } elseif ($msgDate === $yesterdayDate) {
                        $timeLabel = 'Yesterday ' . $msgLocal->format('g:i A');
                    } else {
                        $timeLabel = $msgLocal->format('M j, g:i A');
                    }
                @endphp
                <div wire:key="msg-{{ $msg->id }}" class="flex {{ $msg->isOutbound() ? 'justify-end' : 'justify-start' }}">
                    <div class="max-w-[85%] lg:max-w-[75%] {{ $msg->isOutbound() ? 'order-last' : '' }}">
                        @if ($msg->isInbound())
                            <p class="text-[10px] text-zinc-400 dark:text-zinc-500 mb-0.5 px-1">
                                {{ $phoneNameMap[$msg->from_number] ?? $msg->from_number }}
                            </p>
                        @elseif ($msg->isOutbound() && $msg->sentByUser)
                            <p class="text-[10px] text-zinc-400 dark:text-zinc-500 mb-0.5 px-1 text-right">
                                {{ $msg->sentByUser->first_name }}
                            </p>
                        @endif

                        <div class="rounded-2xl px-3.5 py-2 text-sm {{ $msg->isOutbound()
                            ? 'bg-indigo-600 text-white rounded-br-md'
                            : 'bg-zinc-200 dark:bg-zinc-700 text-zinc-900 dark:text-zinc-100 rounded-bl-md' }}">
                            @if ($msg->hasMedia())
                                <div class="space-y-2 {{ $msg->text ? 'mb-1.5' : '' }}">
                                    @foreach ($msg->media_urls as $url)
                                        <a href="{{ $url }}" target="_blank">
                                            <img src="{{ $url }}" alt="MMS attachment" class="max-w-full rounded-lg max-h-64 object-cover" loading="lazy" />
                                        </a>
                                    @endforeach
                                </div>
                            @endif
                            @if ($msg->display_text)
                                {!! preg_replace(
                                    '/(https?:\/\/[^\s<]+)/',
                                    '<a href="$1" target="_blank" class="underline ' . ($msg->isOutbound() ? 'text-indigo-100 hover:text-white' : 'text-indigo-600 hover:text-indigo-700 dark:text-indigo-400 dark:hover:text-indigo-300') . '">$1</a>',
                                    nl2br(e($msg->display_text))
                                ) !!}
                            @endif
                        </div>

                        <p class="text-[10px] text-zinc-400 dark:text-zinc-500 mt-0.5 {{ $msg->isOutbound() ? 'text-right' : '' }} px-1">{{ $timeLabel }}</p>
                    </div>
                </div>
            @empty
                <div class="text-center py-12">
                    <p class="text-sm text-zinc-400 dark:text-zinc-500">No messages yet. Send the first one!</p>
                </div>
            @endforelse
        </div>
            <div class="sms-fade-overlay bottom"></div>
        </div>

        {{-- Compose --}}
        <div class="shrink-0 px-1 pb-1">
            @if ($isClientUser)
                <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800/50 px-4 py-3 text-sm text-zinc-500 dark:text-zinc-400 text-center">
                    Homeowners are not able to message here yet. Please message us on your phone messaging app.
                </div>
            @else
            <form wire:submit="sendMessage">
                <flux:composer
                    wire:model="newMessage"
                    placeholder="Type a message..."
                    label="Message"
                    label:sr-only
                    rows="2"
                    max-rows="6"
                    submit="enter"
                >
                    @if ($attachment)
                        <x-slot name="header">
                            <div class="relative inline-block border border-zinc-200 dark:border-zinc-700 rounded-lg overflow-hidden">
                                <img src="{{ $attachment->temporaryUrl() }}" alt="Attachment preview" class="size-16 object-cover" />
                                <div class="absolute top-0 right-0 p-0.5">
                                    <button type="button" wire:click="removeAttachment" class="p-0.5 rounded-full bg-zinc-900/50 hover:bg-zinc-900/70 flex justify-center items-center">
                                        <flux:icon icon="x-mark" variant="micro" class="text-white" />
                                    </button>
                                </div>
                            </div>
                        </x-slot>
                    @endif

                    <x-slot name="actionsLeading">
                        <flux:button type="button" size="sm" variant="subtle" icon="paper-clip" x-on:click="$refs.fileInput.click()" />
                        <input x-ref="fileInput" type="file" wire:model="attachment" accept="image/*" class="hidden" />
                    </x-slot>

                    <x-slot name="actionsTrailing">
                        <flux:button type="submit" size="sm" variant="primary" icon="paper-airplane" wire:loading.attr="disabled" />
                    </x-slot>
                </flux:composer>
            </form>

            @error('newMessage')
                <p class="text-xs text-red-500 mt-1 px-1">{{ $message }}</p>
            @enderror
            @error('attachment')
                <p class="text-xs text-red-500 mt-1 px-1">{{ $message }}</p>
            @enderror

            <div class="flex items-center gap-2 px-2 pt-1">
                <flux:avatar size="xs" color="indigo" name="{{ auth()->user()->full_name }}" circle />
                <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ auth()->user()->full_name }}</span>
            </div>
            @endif
        </div>
    @else
        {{-- No thread selected --}}
        <div class="flex flex-1 items-center justify-center">
            <div class="text-center">
                <flux:icon name="chat-bubble-left-right" class="mx-auto h-12 w-12 text-zinc-300 dark:text-zinc-600" />
                <h3 class="mt-3 text-sm font-medium text-zinc-500 dark:text-zinc-400">No conversation selected</h3>
                <p class="mt-1 text-xs text-zinc-400 dark:text-zinc-500">Select a conversation or start a new one.</p>
            </div>
        </div>
    @endif
</div>

@script
<script>
    const container = $wire.$el.querySelector('.sms-messages');
    if (container) {
        let lastCount = container.children.length;
        new MutationObserver(() => {
            const newCount = container.children.length;
            if (newCount !== lastCount) {
                lastCount = newCount;
                container.scrollTop = container.scrollHeight;
            }
        }).observe(container, { childList: true });
    }
</script>
@endscript
