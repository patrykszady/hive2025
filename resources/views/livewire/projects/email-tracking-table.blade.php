{{-- Root wrapper carries the transition, like every other lazy index card
     (estimates, payments, leads): the placeholder wraps its card the same way,
     so Livewire patches the card in place instead of destroying and recreating
     it — which is what made this card blink out before its content appeared. --}}
<div wire:transition>
@php
    // ONE presentation for every embedded card — project, lead AND client: the
    // latest event, with the rest collapsed behind the header toggle. Only the
    // standalone /email-tracking index paginates.
    $embeddedId = $projectId ?? $leadId ?? $clientId ?? null;
    $events = collect($this->emailTrackingEvents->items());
    $latestEvent = $embeddedId ? $events->first() : null;
    $olderEvents = $embeddedId ? $events->slice(1)->values() : collect();
    $hasAccordion = $embeddedId && $olderEvents->isNotEmpty();

    // The Project column is implied on a project card, meaningful everywhere
    // else. Widths come from the shared defs so every table matches.
    $columns = \App\Livewire\Projects\EmailTrackingTable::columnDefs(
        scoped: (bool) $projectId,
        narrow: (bool) $embeddedId,
    );
@endphp
{{-- An embedded card with nothing tracked renders no card at all; the
     standalone index keeps its empty state. --}}
@if($this->emailTrackingEvents->isNotEmpty() || ! $embeddedId)

{{-- Heading comes from <x-index-table> like every other index card — never a
     hand-rolled header here, or the card's title shifts between the skeleton
     and the loaded state. The older-events toggle rides in the actions slot. --}}
<x-index-table heading="Email Tracking" wire:loading.class="opacity-50 text-opacity-50" wire:transition
    x-data="{ open: false }" :clickable="$hasAccordion"
    :paginator="$embeddedId ? null : $this->emailTrackingEvents">
    {{-- Slot forwarded UNCONDITIONALLY: Blaze hoists <x-slot> out of an @if,
         which would render the toggle (with a "0" badge) on cards that have no
         history. The receiver drops it when the content is empty. --}}
    {{-- Count sits beside the title like every other card (Tasks, details
         cards): it's the TOTAL number of emails, not the hidden remainder. --}}
    <x-slot:badge>
        @if($hasAccordion)
            <flux:badge color="zinc" size="sm">{{ $events->count() }}</flux:badge>
        @endif
    </x-slot:badge>
    <x-slot:actions>
        @if($hasAccordion)
            <x-card-collapse-toggle label="Toggle email history" />
        @endif
    </x-slot:actions>

        @if($embeddedId)
            @include('livewire.projects.partials.email-tracking-table', [
                'columns' => $columns,
                'events' => $latestEvent ? collect([$latestEvent]) : collect(),
                'header' => true,
                'floor' => false,
                'projectId' => $projectId,
                'shortProjectName' => ! $projectId,
                'shortDate' => true,
            ])

            @if($olderEvents->isNotEmpty())
                {{-- History in a plain <div>: x-collapse animates height, which
                     table elements ignore (a <tr> or <tbody> snaps open in one
                     frame). Same partial, colgroup instead of a header row. --}}
                <div x-show="open" x-collapse x-cloak>
                    @include('livewire.projects.partials.email-tracking-table', [
                        'columns' => $columns,
                        'events' => $olderEvents,
                        'header' => false,
                        'floor' => false,
                        'projectId' => $projectId,
                        'shortProjectName' => ! $projectId,
                        'shortDate' => true,
                    ])
                </div>
            @endif
        @else
            @include('livewire.projects.partials.email-tracking-table', [
                'columns' => $columns,
                'events' => $this->emailTrackingEvents,
                'header' => true,
                'floor' => true,
                'projectId' => $projectId,
                'shortProjectName' => false,
            ])
        @endif
</x-index-table>
@endif
</div>
