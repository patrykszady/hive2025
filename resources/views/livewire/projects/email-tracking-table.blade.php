<div>
@php
    $scopedId = $projectId ?? $leadId ?? null;
    $projectEvents = $scopedId ? collect($this->emailTrackingEvents->items()) : collect();
    $latestEvent = $scopedId ? $projectEvents->first() : null;
    $olderEvents = $scopedId ? $projectEvents->slice(1)->values() : collect();
@endphp
{{-- Scoped to a project, lead or client: no events means no card at all. The
     unscoped index keeps its empty state. --}}
@if($this->emailTrackingEvents->isNotEmpty() || (! $scopedId && ! $clientId))

<x-index-table wire:loading.class="opacity-50 text-opacity-50" wire:transition x-data="{ expanded: false }"
    :paginator="$scopedId ? null : $this->emailTrackingEvents">
    <x-slot:before>
    <div class="flex w-full items-center justify-between pb-2">
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
    </x-slot:before>

        @if($scopedId)
            @if($latestEvent)
                {{-- Scoped card sits in ~500px columns/modals: no 640px floor --}}
                <flux:table class="table-fixed min-w-0 w-full [:where(&)]:p-0 [:where(&)]:space-y-0">
                    <flux:table.columns>
                        @foreach(\App\Livewire\Projects\EmailTrackingTable::columnDefs(scoped: true) as $trackingColumn)
                            <flux:table.column class="{{ $trackingColumn['width'] }}">{{ $trackingColumn['label'] }}</flux:table.column>
                        @endforeach
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
            <flux:table class="index-table [:where(&)]:p-0 [:where(&)]:space-y-0">
                <flux:table.columns>
                    @foreach(\App\Livewire\Projects\EmailTrackingTable::columnDefs(scoped: false) as $trackingColumn)
                        <flux:table.column class="{{ $trackingColumn['width'] }}">{{ $trackingColumn['label'] }}</flux:table.column>
                    @endforeach
                </flux:table.columns>

                <flux:table.rows>
                    @forelse ($this->emailTrackingEvents as $event)
                        @include('livewire.projects.partials.email-tracking-row', [
                            'event' => $event,
                            'projectId' => $projectId,
                            'shortProjectName' => (bool) $clientId,
                        ])
                    @empty
                        <flux:table.row>
                            <flux:table.cell :colspan="5" class="text-center text-gray-500">No tracking events found.</flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        @endif
</x-index-table>
@endif
</div>
