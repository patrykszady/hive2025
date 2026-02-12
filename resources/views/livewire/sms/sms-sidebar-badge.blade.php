<div wire:poll.15s>
    @if ($count > 0)
        <flux:badge color="indigo" size="sm" inset="top bottom">{{ $count }}</flux:badge>
    @endif
</div>
