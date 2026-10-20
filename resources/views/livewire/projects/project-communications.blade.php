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
        </flux:tabs>
    </x-slot:actions>

    <flux:separator variant="subtle" />

    <div class="space-y-1 py-2" wire:loading.class="opacity-50" wire:target="kind">
        @forelse ($this->items as $item)
            @php
                $tone = $item['direction'] === 'inbound' ? 'text-green-500' : 'text-indigo-500';
            @endphp
            <div wire:key="comm-{{ $item['kind'] }}-{{ $item['id'] }}">
                @if ($item['kind'] === 'email')
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
