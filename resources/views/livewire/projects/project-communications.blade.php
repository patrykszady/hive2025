{{-- Root wrapper carries the transition, like every other lazy card on this
     page (materials, email tracking): the placeholder wraps its card the
     same way, so Livewire patches the card in place instead of
     destroying/recreating it. --}}
<div wire:transition>
<x-island-card heading="Communications" wire:transition>
    <x-slot:actions>
        <flux:tabs
            wire:model.live="kind"
            variant="segmented"
            size="sm"
            class="!flex [&>button]:flex-1 !rounded-lg !bg-zinc-100 dark:!bg-zinc-800 !p-0.5"
        >
            <flux:tab name="all">All</flux:tab>
            <flux:tab name="call">Calls</flux:tab>
            <flux:tab name="sms">Texts</flux:tab>
            <flux:tab name="email">Emails</flux:tab>
            <flux:tab name="meeting">Meetings</flux:tab>
            @if ($this->isSearching())
                <flux:tab name="action_item" wire:key="comm-tab-action-items">Action items</flux:tab>
            @endif
        </flux:tabs>
    </x-slot:actions>

    <flux:separator variant="subtle" />

    <div class="px-2 pt-3">
        <flux:input
            wire:model.live.debounce.300ms="search"
            size="sm"
            icon="magnifying-glass"
            placeholder="Search calls, texts, emails, meetings and action items"
            aria-label="Search this project's communications"
            clearable
        />
        @if ($this->isSearching())
            <flux:text size="sm" class="mt-2" wire:loading.remove wire:target="search,kind">
                {{ count($this->items).(count($this->items) === \App\Services\Communications\ProjectCommunicationsSearch::LIMIT ? '+' : '').' '.Str::plural('result', count($this->items)) }} for “{{ trim($search) }}”, newest first
            </flux:text>
        @endif
    </div>

    <div class="space-y-1 py-2" wire:loading.class="opacity-50" wire:target="kind,search">
        @forelse ($this->items as $item)
            @php
                $tone = $item['direction'] === 'inbound' ? 'text-green-500' : 'text-indigo-500';
            @endphp
            <div wire:key="comm-{{ $item['kind'] }}-{{ $item['id'] }}">
                @if ($item['kind'] === 'meeting')
                    {{-- Meetings open their summary and transcript in place. --}}
                    <button
                        type="button"
                        wire:click="selectMeeting({{ $item['id'] }})"
                        class="w-full flex items-start gap-3 px-2 py-2 rounded-lg text-left hover:bg-zinc-50 dark:hover:bg-zinc-800"
                    >
                        @include('livewire.projects.partials.communication-row', ['item' => $item, 'tone' => 'text-red-500'])
                    </button>
                @elseif ($item['kind'] === 'action_item')
                    {{-- An action item opens where it was said, in place. --}}
                    @if (in_array($item['source_kind'] ?? null, ['email', 'meeting', 'call', 'sms'], true) && ! empty($item['source_id']))
                        <button
                            type="button"
                            wire:click="openActionItemSource('{{ $item['source_kind'] }}', {{ $item['source_id'] }})"
                            class="w-full flex items-start gap-3 px-2 py-2 rounded-lg text-left hover:bg-zinc-50 dark:hover:bg-zinc-800"
                        >
                            @include('livewire.projects.partials.communication-row', ['item' => $item, 'tone' => 'text-amber-500'])
                        </button>
                    @else
                        <div class="w-full flex items-start gap-3 px-2 py-2">
                            @include('livewire.projects.partials.communication-row', ['item' => $item, 'tone' => 'text-amber-500'])
                        </div>
                    @endif
                @elseif ($item['kind'] === 'email')
                    {{-- Emails open the detail modal in place; calls/texts
                         deep-link to their existing UI on the Messages page. --}}
                    <button
                        type="button"
                        wire:click="selectEmail({{ $item['id'] }})"
                        class="w-full flex items-start gap-3 px-2 py-2 rounded-lg text-left hover:bg-zinc-50 dark:hover:bg-zinc-800"
                    >
                        @include('livewire.projects.partials.communication-row', ['item' => $item, 'tone' => $tone])
                    </button>
                @elseif ($this->isSearching())
                    {{-- A search result opens in place, with the searched
                         words marked; the modal links on to Messages. --}}
                    <button
                        type="button"
                        wire:click="{{ $item['kind'] === 'call' ? 'selectCall' : 'selectText' }}({{ $item['id'] }})"
                        class="w-full flex items-start gap-3 px-2 py-2 rounded-lg text-left hover:bg-zinc-50 dark:hover:bg-zinc-800"
                    >
                        @include('livewire.projects.partials.communication-row', ['item' => $item, 'tone' => $tone])
                    </button>
                @else
                    <a
                        wire:navigate.hover
                        href="{{ $item['kind'] === 'call'
                            ? route('sms.index', ['activeTab' => 'calls', 'callId' => $item['id']])
                            : route('sms.index', ['threadId' => $item['thread_id']]) }}"
                        class="w-full flex items-start gap-3 px-2 py-2 rounded-lg hover:bg-zinc-50 dark:hover:bg-zinc-800"
                    >
                        @include('livewire.projects.partials.communication-row', ['item' => $item, 'tone' => $tone])
                    </a>
                @endif
            </div>
        @empty
            <div class="text-center py-8 text-sm text-zinc-400">
                {{ $this->isSearching() ? 'Nothing matches “'.trim($search).'”.' : 'No communications yet.' }}
            </div>
        @endforelse
    </div>

    @if ($this->nextBefore)
        <flux:button variant="ghost" size="sm" class="w-full" wire:click="loadMore" wire:loading.attr="disabled" wire:target="loadMore">
            <span wire:loading.remove wire:target="loadMore">Load more</span>
            <span wire:loading wire:target="loadMore">Loading…</span>
        </flux:button>
    @endif
</x-island-card>

{{-- In-person meeting modal. --}}
<flux:modal wire:model.self="showMeetingModal" class="max-w-2xl">
    @if ($showMeetingModal && $this->selectedMeeting)
        @php $meeting = $this->selectedMeeting; @endphp
        <div class="space-y-4" data-meeting-modal wire:key="meeting-modal-{{ $meeting->id }}" x-data x-init="$nextTick(() => $el.querySelector('mark')?.scrollIntoView({ block: 'center' }))">
            <div>
                <flux:heading size="lg">In-person meeting</flux:heading>
                <flux:text>
                    {{ ($meeting->started_at ?? $meeting->created_at)->timezone('America/Chicago')->format('l, M j, Y g:i A') }}
                    @if ($meeting->duration_seconds) · {{ gmdate($meeting->duration_seconds >= 3600 ? 'G:i:s' : 'i:s', $meeting->duration_seconds) }} @endif
                    @if ($meeting->recordedBy) · recorded by {{ \App\Support\Sms\ConversationPresenter::preferredUserDisplayName($meeting->recordedBy) }} @endif
                </flux:text>
            </div>
            @if ($meeting->status === \App\Models\MeetingRecording::STATUS_PROCESSING)
                <flux:callout icon="arrow-path" color="amber" heading="Still transcribing — check back in a few minutes." />
            @endif
            @if ($meeting->summary)
                <flux:text>{{ $this->highlight((string) $meeting->summary) }}</flux:text>
            @endif
            @if ($meeting->action_items)
                <div>
                    <flux:heading size="sm">Action items</flux:heading>
                    <ul class="mt-1 list-disc space-y-0.5 pl-5 text-sm text-zinc-700 dark:text-zinc-300">
                        @foreach ($meeting->action_items as $actionItem)
                            <li>{{ is_string($actionItem) ? $this->highlight($actionItem) : '' }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
            @if ($meeting->next_steps)
                <div>
                    <flux:heading size="sm">Next steps</flux:heading>
                    <ul class="mt-1 list-disc space-y-0.5 pl-5 text-sm text-zinc-700 dark:text-zinc-300">
                        @foreach ($meeting->next_steps as $step)
                            <li>{{ is_string($step) ? $this->highlight($step) : '' }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
            @if ($meeting->transcript)
                <details @if ($this->isSearching()) open @endif>
                    <summary class="cursor-pointer text-sm text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300">Transcript</summary>
                    <div class="mt-1 max-h-96 overflow-y-auto whitespace-pre-line rounded bg-zinc-50 p-2 text-xs text-zinc-700 dark:bg-white/5 dark:text-zinc-300">{{ $this->highlight((string) $meeting->transcript) }}</div>
                </details>
            @endif
        </div>
    @endif
</flux:modal>

{{-- Email detail modal. --}}
<flux:modal wire:model.self="showEmailModal" class="max-w-2xl">
    @if ($showEmailModal && $this->selectedEmail)
        @php $email = $this->selectedEmail; @endphp
        @include('livewire.emails.partials.email-detail', [
            'email' => $email,
            'latestPart' => $this->selectedEmailLatestPart,
            'quotedPart' => $this->selectedEmailQuotedPart,
            'canCreateTask' => (bool) $email->project_id,
            'mark' => fn (string $text) => $this->highlight($text),
        ])
    @endif
</flux:modal>

{{-- Call detail modal (search results and action items open here). --}}
<flux:modal wire:model.self="showCallModal" class="max-w-2xl">
    @if ($showCallModal && $this->selectedCall)
        @php
            $call = $this->selectedCall['call'];
            $callItem = $this->selectedCall['item'];
            $transcript = $call->transcript;
        @endphp
        <div class="space-y-4" data-call-modal wire:key="call-modal-{{ $call->id }}" x-data x-init="$nextTick(() => $el.querySelector('mark')?.scrollIntoView({ block: 'center' }))">
            <div>
                <flux:heading size="lg">{{ $callItem['title'] }} · {{ $callItem['who'] }}</flux:heading>
                <flux:text>
                    {{ $call->created_at->timezone('America/Chicago')->format('l, M j, Y g:i A') }}
                    @if ($call->duration_seconds) · {{ gmdate($call->duration_seconds >= 3600 ? 'G:i:s' : 'i:s', $call->duration_seconds) }} @endif
                </flux:text>
            </div>
            @if ($transcript?->summary)
                <flux:text>{{ $this->highlight((string) $transcript->summary) }}</flux:text>
            @endif
            @foreach (['Action items' => $transcript?->action_items, 'Next steps' => $transcript?->next_steps] as $listHeading => $lines)
                @if (! empty($lines))
                    <div wire:key="call-list-{{ $loop->index }}">
                        <flux:heading size="sm">{{ $listHeading }}</flux:heading>
                        <ul class="mt-1 list-disc space-y-0.5 pl-5 text-sm text-zinc-700 dark:text-zinc-300">
                            @foreach ((array) $lines as $line)
                                <li wire:key="call-line-{{ $loop->parent->index }}-{{ $loop->index }}">{{ is_string($line) ? $this->highlight($line) : '' }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            @endforeach
            @if ($call->notes)
                <div>
                    <flux:heading size="sm">Notes</flux:heading>
                    <flux:text class="whitespace-pre-line">{{ $this->highlight((string) $call->notes) }}</flux:text>
                </div>
            @endif
            @if ($transcript?->text)
                <div>
                    <flux:heading size="sm">Transcript</flux:heading>
                    <div class="mt-1 max-h-96 overflow-y-auto whitespace-pre-line rounded bg-zinc-50 p-2 text-xs text-zinc-700 dark:bg-white/5 dark:text-zinc-300">{{ $this->highlight((string) $transcript->text) }}</div>
                </div>
            @endif
            <flux:button size="sm" variant="ghost" icon:trailing="arrow-top-right-on-square" wire:navigate :href="route('sms.index', ['activeTab' => 'calls', 'callId' => $call->id])">
                Open in Messages
            </flux:button>
        </div>
    @endif
</flux:modal>

{{-- Text detail modal: the message inside its conversation. --}}
<flux:modal wire:model.self="showTextModal" class="max-w-2xl">
    @if ($showTextModal && $this->selectedText)
        <div class="space-y-4" data-text-modal wire:key="text-modal-{{ $openTextId }}" x-data x-init="$nextTick(() => $el.querySelector('[data-selected-text]')?.scrollIntoView({ block: 'center' }))">
            <flux:heading size="lg">Text conversation</flux:heading>
            <div class="max-h-[60vh] space-y-2 overflow-y-auto">
                @foreach ($this->selectedText['messages'] as $message)
                    @php $outbound = $message['direction'] !== 'inbound'; @endphp
                    <div wire:key="text-modal-message-{{ $message['id'] }}" class="flex {{ $outbound ? 'justify-end' : 'justify-start' }}">
                        <div
                            @if ($message['id'] === $openTextId) data-selected-text @endif
                            class="max-w-[85%] rounded-lg px-3 py-2 text-sm {{ $outbound ? 'bg-indigo-50 dark:bg-indigo-900/30' : 'bg-zinc-100 dark:bg-zinc-800' }} {{ $message['id'] === $openTextId ? 'ring-2 ring-yellow-400' : '' }}"
                        >
                            <div class="text-xs text-zinc-500 dark:text-zinc-400">
                                {{ $message['who'] }} · {{ \Illuminate\Support\Carbon::parse($message['at'])->timezone('America/Chicago')->format('M j, Y g:i A') }}
                            </div>
                            <div class="mt-0.5 whitespace-pre-line text-zinc-800 dark:text-zinc-100">{{ $this->highlight($message['text']) }}</div>
                            @if ((int) ($message['media_count'] ?? 0) > 0)
                                <flux:badge size="sm" color="zinc" icon="paper-clip" class="mt-1">{{ $message['media_count'] }}</flux:badge>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
            <flux:button size="sm" variant="ghost" icon:trailing="arrow-top-right-on-square" wire:navigate :href="route('sms.index', ['threadId' => $this->selectedText['thread_id']])">
                Open thread in Messages
            </flux:button>
        </div>
    @endif
</flux:modal>
</div>
