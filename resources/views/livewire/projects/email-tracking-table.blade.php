<div>
@if(!$projectId || $this->emailTrackingEvents->isNotEmpty())
<x-island-card heading="Email Tracking" :separator="true" wire:loading.class="opacity-50 text-opacity-50" wire:transition>

    <div class="space-y-2">
        <flux:table :paginate="$this->emailTrackingEvents->hasPages() ? $this->emailTrackingEvents : null">
            <flux:table.columns>
                <flux:table.column>Event</flux:table.column>
                <flux:table.column>Template</flux:table.column>
                <flux:table.column>Project</flux:table.column>
                <flux:table.column class="w-48">Recipients</flux:table.column>
                <flux:table.column>Date</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($this->emailTrackingEvents as $event)
                    <flux:table.row :key="$event->id">
                        <flux:table.cell>
                            <flux:badge
                                size="sm"
                                :color="match($event->event_type) {
                                    'opened' => 'blue',
                                    'clicked' => 'green',
                                    'replied' => 'purple',
                                    'bounced' => 'red',
                                    default => 'zinc'
                                }"
                                inset="top bottom">
                                {{ ucfirst($event->event_type) }}
                                @if(isset($event->event_count) && $event->event_count > 1)
                                    <span class="ml-1">x{{ $event->event_count }}</span>
                                @endif
                            </flux:badge>
                        </flux:table.cell>
                        <flux:table.cell>
                            @if($event->email_template_name)
                                <flux:badge size="sm" color="zinc" variant="outline">
                                    {{ $event->email_template_name }}
                                </flux:badge>
                            @else
                                <span class="text-gray-400">-</span>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>
                            @if($event->project)
                                <a wire:navigate.hover href="{{ route('projects.show', $event->project_id) }}" class="font-semibold text-zinc-900 dark:text-zinc-100 hover:text-indigo-600 dark:hover:text-indigo-400">
                                    {{ $event->project?->address ?? '-' }}
                                </a>
                            @else
                                <span class="text-gray-400">-</span>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell class="w-48">
                            @php
                                $recipientUsers = $event->recipient_users ?? collect();
                                $recipientCount = $recipientUsers instanceof \Illuminate\Support\Collection ? $recipientUsers->count() : 0;
                                $firstRecipient = $recipientCount > 0 ? $recipientUsers->first() : null;
                                $firstName = $firstRecipient?->first_name ?? null;

                                if (! $firstName && $firstRecipient?->full_name) {
                                    $nameParts = explode(' ', trim((string) $firstRecipient->full_name));
                                    $firstName = $nameParts[0] ?? null;
                                }

                                $emails = is_array($event->all_recipient_emails ?? null) ? $event->all_recipient_emails : [];
                                $emailCount = count($emails);
                                $fallbackEmail = $emailCount > 0 ? $emails[0] : null;
                                $displayName = $firstName ?: $fallbackEmail;
                                $extraCount = $recipientCount > 0
                                    ? max($recipientCount - 1, 0)
                                    : max($emailCount - 1, 0);
                                    $recipientFullNames = $recipientUsers instanceof \Illuminate\Support\Collection
                                        ? $recipientUsers->pluck('full_name')->filter()->values()->all()
                                        : [];
                                    $recipientTitle = ! empty($recipientFullNames) ? implode(', ', $recipientFullNames) : null;
                            @endphp
                            @if($displayName)
                                    <div class="text-sm text-zinc-700 dark:text-zinc-300" @if($recipientTitle) title="{{ $recipientTitle }}" @endif>
                                    {{ $displayName }}@if($extraCount > 0) <span class="text-xs text-zinc-500 dark:text-zinc-400">+{{ $extraCount }}</span>@endif
                                </div>
                            @else
                                <span class="text-gray-400">-</span>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>
                            @if($event->event_at)
                                @php
                                    $daysAgo = $event->event_at->diffInDays(now());
                                    $dateLabel = $daysAgo > 14
                                        ? $event->event_at->format('m/d/y')
                                        : $event->event_at->diffForHumans();
                                @endphp
                                <div class="text-sm text-zinc-700 dark:text-zinc-300">{{ $dateLabel }}</div>
                            @else
                                <span class="text-gray-400">-</span>
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="5" class="text-center text-gray-500">No tracking events found.</flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>
</x-island-card>
@endif
</div>
