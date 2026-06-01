<div>
@php
    $scopedId = $projectId ?? $leadId ?? null;
    $projectEvents = $scopedId ? collect($this->emailTrackingEvents->items()) : collect();
    $latestEvent = $scopedId ? $projectEvents->first() : null;
    $olderEvents = $scopedId ? $projectEvents->slice(1)->values() : collect();
@endphp
@if(!$scopedId || $this->emailTrackingEvents->isNotEmpty())

<x-island-card :separator="false" wire:loading.class="opacity-50 text-opacity-50" wire:transition x-data="{ expanded: false }">
    <div class="flex w-full items-center justify-between">
        <button type="button" @click="expanded = !expanded" class="flex items-center gap-2">
            <flux:heading size="lg" class="mb-0">Email Tracking</flux:heading>
        </button>
        @if($scopedId && $olderEvents->isNotEmpty())
            <button type="button" @click="expanded = !expanded" class="flex items-center gap-2 text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200">
                <flux:badge color="zinc" size="sm">{{ $olderEvents->count() }}</flux:badge>
                <flux:icon.chevron-down variant="mini" class="transition-transform duration-200" ::class="expanded && 'rotate-180'" />
            </button>
        @endif
    </div>

    <div class="space-y-2">
        @if($scopedId)
            @if($latestEvent)
                <flux:table :paginate="$this->emailTrackingEvents->hasPages() ? $this->emailTrackingEvents : null">
                    <flux:table.columns>
                        <flux:table.column>Event</flux:table.column>
                        <flux:table.column>Template</flux:table.column>
                        <flux:table.column class="w-48">Recipients</flux:table.column>
                        <flux:table.column>Date</flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @include('livewire.projects.partials.email-tracking-row', [
                            'event' => $latestEvent,
                            'projectId' => $scopedId,
                        ])

                        @if($olderEvents->isNotEmpty())
                            @foreach($olderEvents as $event)
                                @include('livewire.projects.partials.email-tracking-row', [
                                    'event' => $event,
                                    'projectId' => $scopedId,
                                    'attributes' => new \Illuminate\View\ComponentAttributeBag(['x-show' => 'expanded', 'x-cloak' => true]),
                                ])
                            @endforeach
                        @endif
                    </flux:table.rows>
                </flux:table>
            @else
                <flux:text class="text-gray-500">No tracking events found.</flux:text>
            @endif
        @else
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
                        @include('livewire.projects.partials.email-tracking-row', [
                            'event' => $event,
                            'projectId' => $projectId,
                        ])
                    @empty
                        <flux:table.row>
                            <flux:table.cell :colspan="5" class="text-center text-gray-500">No tracking events found.</flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        @endif
    </div>
</x-island-card>
@endif
</div>
