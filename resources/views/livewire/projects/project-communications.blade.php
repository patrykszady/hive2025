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
        <div class="space-y-4" x-data="{ showQuoted: false }" wire:key="email-modal-{{ $email->id }}">
            <div>
                <flux:heading size="lg">{{ $email->subject ?: '(no subject)' }}</flux:heading>
                <flux:subheading>
                    {{ $email->isInbound() ? 'Received' : 'Sent' }}
                    {{ $email->sent_at->copy()->setTimezone('America/Chicago')->format('M j, Y g:i A') }} CT
                </flux:subheading>
            </div>

            <div class="text-sm space-y-1 text-zinc-500 dark:text-zinc-400">
                <div>
                    <span class="font-medium text-zinc-700 dark:text-zinc-300">From:</span>
                    {{ trim(($email->from_name ? $email->from_name.' ' : '').'<'.$email->from_email.'>') }}
                </div>
                @if (! empty($email->to))
                    <div>
                        <span class="font-medium text-zinc-700 dark:text-zinc-300">To:</span>
                        {{ implode(', ', (array) $email->to) }}
                    </div>
                @endif
                @if (! empty($email->cc))
                    <div>
                        <span class="font-medium text-zinc-700 dark:text-zinc-300">Cc:</span>
                        {{ implode(', ', (array) $email->cc) }}
                    </div>
                @endif
            </div>

            @if ($email->summary)
                <div class="rounded-lg bg-indigo-50 dark:bg-indigo-900/20 p-3 space-y-2">
                    <div class="flex items-center gap-1.5 text-xs font-medium text-indigo-600 dark:text-indigo-400 uppercase tracking-wide">
                        <flux:icon.sparkles variant="micro" class="size-3.5" />
                        AI Summary
                    </div>
                    <p class="text-sm text-zinc-700 dark:text-zinc-200">{{ $email->summary }}</p>
                    @if (! empty($email->action_items))
                        <ul class="list-disc list-inside text-sm text-zinc-600 dark:text-zinc-300 space-y-0.5">
                            @foreach ($email->action_items as $actionItem)
                                <li wire:key="action-item-{{ $loop->index }}">{{ $actionItem }}</li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @endif

            <div class="text-sm text-zinc-700 dark:text-zinc-200 whitespace-pre-wrap">{{ $this->selectedEmailLatestPart }}</div>

            @if ($this->selectedEmailQuotedPart !== '')
                <div>
                    <flux:button size="xs" variant="ghost" x-on:click="showQuoted = !showQuoted">
                        <span x-show="!showQuoted">Show quoted text</span>
                        <span x-show="showQuoted" x-cloak>Hide quoted text</span>
                    </flux:button>
                    <div
                        x-show="showQuoted"
                        x-cloak
                        class="mt-2 text-sm text-zinc-400 dark:text-zinc-500 whitespace-pre-wrap border-l-2 border-zinc-200 dark:border-zinc-700 pl-3"
                    >{{ $this->selectedEmailQuotedPart }}</div>
                </div>
            @endif

            @if (! empty($email->attachments))
                <div class="space-y-2">
                    <div class="text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide">Attachments</div>
                    <div class="flex flex-wrap gap-2">
                        @foreach ((array) $email->attachments as $index => $attachment)
                            @php $isImage = str_starts_with((string) ($attachment['mime'] ?? ''), 'image/'); @endphp
                            <a
                                wire:key="attachment-{{ $index }}"
                                href="{{ $email->attachmentUrl($index) }}"
                                target="_blank"
                                rel="noopener"
                                class="flex items-center gap-2 rounded-lg border border-zinc-200 dark:border-zinc-700 px-2 py-1.5 hover:bg-zinc-50 dark:hover:bg-zinc-800"
                            >
                                @if ($isImage)
                                    <img
                                        src="{{ $email->attachmentUrl($index, true) }}"
                                        alt="{{ $attachment['name'] ?? 'attachment' }}"
                                        class="size-8 rounded object-cover"
                                        loading="lazy"
                                    />
                                @else
                                    <flux:icon.paper-clip variant="micro" class="size-4 text-zinc-400" />
                                @endif
                                <span class="text-xs text-zinc-600 dark:text-zinc-300 max-w-[12rem] truncate">{{ $attachment['name'] ?? 'Attachment' }}</span>
                                <span class="text-xs text-zinc-400 whitespace-nowrap">{{ \Illuminate\Support\Number::fileSize((int) ($attachment['size'] ?? 0)) }}</span>
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    @endif
</flux:modal>
</div>
