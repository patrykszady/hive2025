@props([
    'event',
    'projectId' => null,
    // Client page: projects all share the client's address, so just the
    // project name. Elsewhere the combined "address | name" disambiguates.
    'shortProjectName' => false,
    // Embedded cards: "44m ago" instead of "44 minutes ago" — the narrow Date
    // column fits the short form without truncating.
    'shortDate' => false,
])

@php
    $eventLabel = ucfirst($event->event_type)
        . ((isset($event->event_count) && $event->event_count > 1) ? ' x' . $event->event_count : '');
@endphp

<flux:table.row :key="$event->id" {{ $attributes }}>
    {{-- min-w-0 + truncating badge, same as Template: nowrap content here
         otherwise paints over the next column on narrow embedded cards. --}}
    <flux:table.cell class="whitespace-nowrap min-w-0 overflow-hidden">
        <x-truncate-tooltip :content="$eventLabel">
            <flux:badge
                size="sm"
                :color="match($event->event_type) {
                    'opened' => 'blue',
                    'clicked' => 'green',
                    'replied' => 'purple',
                    'bounced' => 'red',
                    default => 'zinc'
                }"
                inset="top bottom"
                class="max-w-full">
                <span class="block truncate">{{ $eventLabel }}</span>
            </flux:badge>
        </x-truncate-tooltip>
    </flux:table.cell>
    {{-- min-w-0 + overflow-hidden: a long template name would otherwise spill
         out of its column and sit on top of the next one. --}}
    <flux:table.cell class="whitespace-nowrap min-w-0 overflow-hidden">
        @if($event->email_template_name)
            {{-- inset: without it this badge is 24px and the row grows to 49px,
                 4px taller than every other index row (and than the skeleton). --}}
            {{-- Shared tooltip: shows only when the name is actually clipped. --}}
            <x-truncate-tooltip :content="$event->email_template_name">
                <flux:badge size="sm" color="zinc" variant="outline" inset="top bottom" class="max-w-full">
                    <span class="block truncate">{{ $event->email_template_name }}</span>
                </flux:badge>
            </x-truncate-tooltip>
        @else
            <span class="text-gray-400">-</span>
        @endif
    </flux:table.cell>
    @if(!$projectId)
    {{-- min-w-0 + overflow-hidden so a long project name truncates inside its
         column instead of running under Recipients. --}}
    <flux:table.cell class="whitespace-nowrap min-w-0 overflow-hidden">
        @if($event->project)
            {{-- Expressions inlined: a php directive directly following an if directive miscompiles under Blaze. --}}
            @if($shortProjectName)
                {{-- Client page: just the name; the tooltip adds the address. --}}
                <x-truncate-tooltip :content="trim(($event->project->project_name ?? '') . ' · ' . ($event->project->address ?? ''), ' ·')">
                    <a wire:navigate.hover href="{{ route('projects.show', $event->project_id) }}" class="block truncate font-semibold text-zinc-900 dark:text-zinc-100 hover:text-indigo-600 dark:hover:text-indigo-400">
                        {{ $event->project->project_name ?? $event->project->address ?? '-' }}
                    </a>
                </x-truncate-tooltip>
            @else
                {{-- Standalone index: full "address | name", truncated to its
                     column with the tooltip showing only when it's clipped. --}}
                <x-truncate-tooltip :content="$event->project->name ?? ''">
                    <a wire:navigate.hover href="{{ route('projects.show', $event->project_id) }}" class="block truncate font-semibold text-zinc-900 dark:text-zinc-100 hover:text-indigo-600 dark:hover:text-indigo-400">
                        {{ $event->project->name ?? '-' }}
                    </a>
                </x-truncate-tooltip>
            @endif
        @else
            <span class="text-gray-400">-</span>
        @endif
    </flux:table.cell>
    @endif
    <flux:table.cell class="whitespace-nowrap min-w-0 overflow-hidden">
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
                // Hover reveals everyone: full names when known, else the raw addresses.
                $recipientTooltip = ! empty($recipientFullNames) ? implode(', ', $recipientFullNames) : implode(', ', $emails);
        @endphp
        @if($displayName)
            <flux:tooltip :content="$recipientTooltip !== '' ? $recipientTooltip : (string) $displayName" position="top">
                <div class="text-sm text-zinc-700 dark:text-zinc-300 cursor-default truncate">
                    {{ $displayName }}@if($extraCount > 0) <span class="text-xs text-zinc-500 dark:text-zinc-400">+{{ $extraCount }}</span>@endif
                </div>
            </flux:tooltip>
        @else
            <span class="text-gray-400">-</span>
        @endif
    </flux:table.cell>
    {{-- Last column: without truncation its nowrap content ("44 minutes ago")
         juts past the table edge, and that overhang IS the card's horizontal
         scrollbar on narrow embedded cards (lead modal, client page). --}}
    <flux:table.cell class="whitespace-nowrap min-w-0 overflow-hidden">
        @if($event->event_at)
            @php
                $daysAgo = $event->event_at->diffInDays(now());
                $dateLabel = $daysAgo > 14
                    ? $event->event_at->format('m/d/y')
                    : $event->event_at->diffForHumans(['short' => (bool) $shortDate]);
            @endphp
            <x-truncate-tooltip :content="$event->event_at->format('m/d/y g:i A')">
                <div class="text-sm text-zinc-700 dark:text-zinc-300 truncate">{{ $dateLabel }}</div>
            </x-truncate-tooltip>
        @else
            <span class="text-gray-400">-</span>
        @endif
    </flux:table.cell>
</flux:table.row>
