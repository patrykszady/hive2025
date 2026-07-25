@props([
    'event',
    'projectId' => null,
    // Client page: projects all share the client's address, so just the
    // project name. Elsewhere the combined "address | name" disambiguates.
    'shortProjectName' => false,
])

<flux:table.row :key="$event->id" {{ $attributes }}>
    <flux:table.cell class="!px-2 whitespace-nowrap">
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
    <flux:table.cell class="!px-2 whitespace-nowrap">
        @if($event->email_template_name)
            {{-- inset: without it this badge is 24px and the row grows to 49px,
                 4px taller than every other index row (and than the skeleton). --}}
            <flux:badge size="sm" color="zinc" variant="outline" inset="top bottom">
                {{ $event->email_template_name }}
            </flux:badge>
        @else
            <span class="text-gray-400">-</span>
        @endif
    </flux:table.cell>
    @if(!$projectId)
    <flux:table.cell class="!px-2 whitespace-nowrap">
        @if($event->project)
            {{-- Expressions inlined: a php directive directly following an if directive miscompiles under Blaze. --}}
            @if($shortProjectName)
                {{-- Truncated name — tooltip supplies the address context. --}}
                <flux:tooltip :content="trim(($event->project->project_name ?? '') . ' · ' . ($event->project->address ?? ''), ' ·')" position="top">
                    <a wire:navigate.hover href="{{ route('projects.show', $event->project_id) }}" class="font-semibold text-zinc-900 dark:text-zinc-100 hover:text-indigo-600 dark:hover:text-indigo-400">
                        {{ \Illuminate\Support\Str::limit($event->project->project_name ?? $event->project->address ?? '-', 16) }}
                    </a>
                </flux:tooltip>
            @else
                {{-- Full "address | name" is already self-explanatory. --}}
                <a wire:navigate.hover href="{{ route('projects.show', $event->project_id) }}" class="font-semibold text-zinc-900 dark:text-zinc-100 hover:text-indigo-600 dark:hover:text-indigo-400">
                    {{ $event->project->name ?? '-' }}
                </a>
            @endif
        @else
            <span class="text-gray-400">-</span>
        @endif
    </flux:table.cell>
    @endif
    <flux:table.cell class="!px-2 whitespace-nowrap">
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
                <div class="text-sm text-zinc-700 dark:text-zinc-300 cursor-default">
                    {{ $displayName }}@if($extraCount > 0) <span class="text-xs text-zinc-500 dark:text-zinc-400">+{{ $extraCount }}</span>@endif
                </div>
            </flux:tooltip>
        @else
            <span class="text-gray-400">-</span>
        @endif
    </flux:table.cell>
    <flux:table.cell class="!px-2 whitespace-nowrap">
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
