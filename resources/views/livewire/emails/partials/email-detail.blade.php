{{-- One stakeholder email in full: header, AI summary + action items, the
     new part of the body with the quoted history behind a toggle, and the
     attachments through their signed links. Shared by the project page's
     Communications card and the unplaced email inbox.
     Expects $email (App\Models\EmailMessage), $latestPart, $quotedPart. --}}
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

    <div class="text-sm text-zinc-700 dark:text-zinc-200 whitespace-pre-wrap">{{ $latestPart }}</div>

    @if ($quotedPart !== '')
        <div>
            <flux:button size="xs" variant="ghost" x-on:click="showQuoted = !showQuoted">
                <span x-show="!showQuoted">Show quoted text</span>
                <span x-show="showQuoted" x-cloak>Hide quoted text</span>
            </flux:button>
            <div
                x-show="showQuoted"
                x-cloak
                class="mt-2 text-sm text-zinc-400 dark:text-zinc-500 whitespace-pre-wrap border-l-2 border-zinc-200 dark:border-zinc-700 pl-3"
            >{{ $quotedPart }}</div>
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
