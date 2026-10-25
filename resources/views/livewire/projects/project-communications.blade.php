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
        </flux:tabs>
    </x-slot:actions>

    <flux:separator variant="subtle" />

    <div class="space-y-1 py-2" wire:loading.class="opacity-50" wire:target="kind">
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
                No communications yet.
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
        <div class="space-y-4" data-meeting-modal>
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
                <flux:text>{{ $meeting->summary }}</flux:text>
            @endif
            @if ($meeting->action_items)
                <div>
                    <flux:heading size="sm">Action items</flux:heading>
                    <ul class="mt-1 list-disc space-y-0.5 pl-5 text-sm text-zinc-700 dark:text-zinc-300">
                        @foreach ($meeting->action_items as $actionItem)
                            <li>{{ $actionItem }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
            @if ($meeting->next_steps)
                <div>
                    <flux:heading size="sm">Next steps</flux:heading>
                    <ul class="mt-1 list-disc space-y-0.5 pl-5 text-sm text-zinc-700 dark:text-zinc-300">
                        @foreach ($meeting->next_steps as $step)
                            <li>{{ $step }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
            @if ($meeting->transcript)
                <details>
                    <summary class="cursor-pointer text-sm text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300">Transcript</summary>
                    <div class="mt-1 max-h-96 overflow-y-auto whitespace-pre-line rounded bg-zinc-50 p-2 text-xs text-zinc-700 dark:bg-white/5 dark:text-zinc-300">{{ $meeting->transcript }}</div>
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
        ])
    @endif
</flux:modal>
</div>
