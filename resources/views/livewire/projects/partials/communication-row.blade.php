{{-- One timeline row's inner markup — shared by the call/sms <a> link and
     the email <button>, which wrap this differently (see
     project-communications.blade.php). $item is one entry from
     ProjectCommunicationsTimeline::forProject(); $tone is its direction
     color, resolved once by the caller. --}}
@php
    $icon = match ($item['kind']) {
        'call' => $item['direction'] === 'inbound' ? 'phone-arrow-down-left' : 'phone-arrow-up-right',
        'sms' => 'chat-bubble-left',
        'email' => 'envelope',
        'meeting' => 'microphone',
    };
@endphp
<div class="shrink-0 mt-0.5">
    <flux:icon :icon="$icon" variant="micro" class="size-4 {{ $tone }}" />
</div>
<div class="min-w-0 flex-1">
    <div class="flex items-center justify-between gap-2">
        <span class="text-sm font-medium text-zinc-900 dark:text-zinc-100 truncate">{{ $item['who'] }}</span>
        <span class="text-xs text-zinc-400 shrink-0 whitespace-nowrap">{{ $this->formatWhen($item['at']) }}</span>
    </div>
    <div class="flex items-center justify-between gap-2 mt-0.5">
        <span class="text-xs text-zinc-500 dark:text-zinc-400 truncate">
            {{ $item['title'] }}@if (! empty($item['preview'])) — {{ Str::limit($item['preview'], 90) }}@endif
        </span>
        <div class="flex items-center gap-1 shrink-0">
            @if ($item['kind'] === 'call' && ! empty($item['has_voicemail']))
                <flux:badge size="sm" color="indigo" icon="microphone">Voicemail</flux:badge>
            @elseif ($item['kind'] === 'call' && ($item['status'] ?? null) === 'missed')
                <flux:badge size="sm" color="red">Missed</flux:badge>
            @elseif ($item['kind'] === 'call' && ($item['status'] ?? null) === 'failed')
                <flux:badge size="sm" color="rose">Failed</flux:badge>
            @endif
            @if (in_array($item['kind'], ['call', 'meeting'], true) && (int) ($item['duration_seconds'] ?? 0) > 0)
                <span class="text-xs text-zinc-400 whitespace-nowrap">
                    @php $dur = (int) $item['duration_seconds']; @endphp
                    {{ $dur < 60 ? $dur.'s' : floor($dur / 60).'m '.($dur % 60).'s' }}
                </span>
            @endif
            @if ($item['kind'] === 'sms' && (int) ($item['media_count'] ?? 0) > 0)
                <flux:badge size="sm" color="zinc" icon="paper-clip">{{ $item['media_count'] }}</flux:badge>
            @endif
            @if ($item['kind'] === 'email' && (int) ($item['attachment_count'] ?? 0) > 0)
                <flux:badge size="sm" color="zinc" icon="paper-clip">{{ $item['attachment_count'] }}</flux:badge>
            @endif
        </div>
    </div>
</div>
