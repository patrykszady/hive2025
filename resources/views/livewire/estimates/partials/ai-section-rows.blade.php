{{-- The sections a draft fills. Rooms read from an uploaded scan are listed
     to create (ticked, renamable); the estimator can type more; sections
     already on the estimate can be ticked to add to. A room merged into
     another section is listed under it, with a way back out. Rows keep
     their own position in $sectionRows so each box binds to its own row —
     and each row's key carries that position: a tick box stays bound to the
     position it was first drawn with, so when rows are added above (a scan
     uploaded, an earlier request used again) a shifted row has to be drawn
     afresh or it would tick, and show the tick of, another row. --}}
@php
    $heads = [];
    $parts = [];
    foreach ($sectionRows as $position => $row) {
        if (empty($row['merged_into'])) {
            $heads[$position] = $row;
        } else {
            $parts[$row['merged_into']][] = $row;
        }
    }

    $toCreate = [];
    $existing = [];
    foreach ($heads as $position => $row) {
        if (($row['kind'] ?? '') === 'existing') {
            $existing[$position] = $row;
        } else {
            $toCreate[$position] = $row;
        }
    }

    $details = [];
    foreach ($heads as $row) {
        $merged = $parts[$row['key']] ?? [];
        $details[$row['key']] = $row['detail'];

        if ($merged !== []) {
            $rooms = array_merge(in_array($row['kind'] ?? '', ['room', 'saved'], true) ? [$row] : [], $merged);
            $sqft = array_sum(array_map(fn ($room) => (float) ($room['sqft'] ?? 0), $rooms));
            $measured = $sqft > 0 ? rtrim(rtrim(number_format($sqft, 1), '0'), '.').' sq.ft. · '.count($rooms).' '.\Illuminate\Support\Str::plural('room', count($rooms)).' together' : '';
            $details[$row['key']] = ($row['kind'] ?? '') === 'existing' ? trim($row['detail'].($measured !== '' ? ' · adding '.$measured : '')) : $measured;
        }
    }
@endphp
<div>
    <flux:label>Sections</flux:label>
    <flux:text size="sm" class="mt-1">
        Claude drafts each ticked section's own line items, sized from that room's measurements. Rename a room, merge rooms that belong in one section, add a note where the description leaves a room open, or untick what the job does not touch.
        Tick nothing and Claude splits the work into sections itself.
    </flux:text>

    @if($toCreate !== [])
        <div class="mt-3 space-y-2">
            @foreach($toCreate as $index => $row)
                <div wire:key="section-row-{{ $row['key'] }}-at-{{ $index }}" x-data="{ noting: {{ filled($row['note'] ?? '') ? 'true' : 'false' }} }">
                    <div class="flex items-center gap-3">
                        <flux:checkbox wire:model="sectionRows.{{ $index }}.include" aria-label="Draft this section" />
                        <div class="w-56 shrink-0">
                            <flux:input wire:model.blur="sectionRows.{{ $index }}.name" size="sm" placeholder="Section name" aria-label="Section name" />
                        </div>
                        <flux:button size="sm" variant="ghost" icon="pencil-square" class="shrink-0 whitespace-nowrap" x-on:click="noting = ! noting; if (noting) $nextTick(() => $refs.note?.focus())">Note</flux:button>
                        {{-- Right beside the name it acts on (2026-11-13). --}}
                        @if(count($heads) > 1)
                            <flux:dropdown align="start">
                                <flux:button size="sm" variant="ghost" icon="arrows-pointing-in" class="shrink-0 whitespace-nowrap">Merge</flux:button>
                                <flux:menu>
                                    <flux:menu.heading>Merge {{ trim((string) $row['name']) !== '' ? $row['name'] : 'this section' }} into</flux:menu.heading>
                                    @foreach($heads as $target)
                                        @continue($target['key'] === $row['key'] || trim((string) $target['name']) === '')
                                        <flux:menu.item wire:click="mergeSectionRow('{{ $row['key'] }}', '{{ $target['key'] }}')">{{ $target['name'] }}</flux:menu.item>
                                    @endforeach
                                </flux:menu>
                            </flux:dropdown>
                        @endif
                        <flux:text size="sm" class="min-w-0 flex-1 truncate" title="{{ $details[$row['key']] }}">{{ $details[$row['key']] }}</flux:text>
                        @if(in_array(\Illuminate\Support\Str::lower(trim((string) $row['name'])), $namesOnEstimate, true))
                            <flux:badge size="sm" color="zinc" class="shrink-0">Adds to this section</flux:badge>
                        @elseif(trim((string) $row['name']) !== '')
                            <flux:badge size="sm" color="indigo" class="shrink-0">New section</flux:badge>
                        @endif
                        @if(in_array($row['kind'] ?? '', ['manual', 'saved'], true))
                            <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="removeSectionRow('{{ $row['key'] }}')" aria-label="Remove this section" />
                        @endif
                    </div>
                    {{-- A word for Claude on this section alone: what the description leaves open for it. --}}
                    <div class="mt-1 ml-8" x-show="noting" x-cloak>
                        <flux:input x-ref="note" wire:model.blur="sectionRows.{{ $index }}.note" size="sm" maxlength="300" placeholder="For this section only — e.g. full new kitchen with an island; tile only, keep the cabinets" aria-label="Note for {{ trim((string) $row['name']) !== '' ? $row['name'] : 'this section' }}" />
                    </div>
                    @foreach($parts[$row['key']] ?? [] as $part)
                        <div class="mt-1 ml-8 flex items-center gap-2" wire:key="section-part-{{ $part['key'] }}">
                            <flux:icon.arrow-turn-down-right class="size-4 shrink-0 text-zinc-400" />
                            <flux:text size="sm" class="min-w-0 truncate">{{ trim((string) $part['name']) !== '' ? $part['name'] : 'Unnamed' }}@if($part['detail'] !== '') · {{ $part['detail'] }}@endif</flux:text>
                            <flux:button size="xs" variant="ghost" icon="arrows-pointing-out" class="shrink-0" wire:click="splitSectionRow('{{ $part['key'] }}')">Split out</flux:button>
                        </div>
                    @endforeach
                </div>
            @endforeach
        </div>
        @error('sectionRows.*.name')
            <flux:text variant="danger" class="mt-1">{{ $message }}</flux:text>
        @enderror
    @endif

    <div class="mt-2">
        <flux:button size="sm" variant="ghost" icon="plus" wire:click="addSectionRow">Add a section</flux:button>
    </div>

    @if($existing !== [])
        <flux:text size="sm" class="mt-3 font-medium">Already on this estimate — tick one to add to it</flux:text>
        <div class="mt-2 space-y-2">
            @foreach($existing as $index => $row)
                <div wire:key="section-row-{{ $row['key'] }}-at-{{ $index }}" x-data="{ noting: {{ filled($row['note'] ?? '') ? 'true' : 'false' }} }">
                    <div class="flex items-center gap-3">
                        <flux:checkbox wire:model="sectionRows.{{ $index }}.include" aria-label="Add to {{ $row['name'] }}" />
                        <flux:text class="w-56 shrink-0 truncate" variant="strong">{{ $row['name'] }}</flux:text>
                        <flux:button size="sm" variant="ghost" icon="pencil-square" class="shrink-0 whitespace-nowrap" x-on:click="noting = ! noting; if (noting) $nextTick(() => $refs.note?.focus())">Note</flux:button>
                        <flux:text size="sm" class="min-w-0 flex-1 truncate" title="{{ $details[$row['key']] }}">{{ $details[$row['key']] }}</flux:text>
                    </div>
                    <div class="mt-1 ml-8" x-show="noting" x-cloak>
                        <flux:input x-ref="note" wire:model.blur="sectionRows.{{ $index }}.note" size="sm" maxlength="300" placeholder="For this section only — what to add to it" aria-label="Note for {{ $row['name'] }}" />
                    </div>
                    @foreach($parts[$row['key']] ?? [] as $part)
                        <div class="mt-1 ml-8 flex items-center gap-2" wire:key="section-part-{{ $part['key'] }}">
                            <flux:icon.arrow-turn-down-right class="size-4 shrink-0 text-zinc-400" />
                            <flux:text size="sm" class="min-w-0 truncate">{{ trim((string) $part['name']) !== '' ? $part['name'] : 'Unnamed' }}@if($part['detail'] !== '') · {{ $part['detail'] }}@endif</flux:text>
                            <flux:button size="xs" variant="ghost" icon="arrows-pointing-out" class="shrink-0" wire:click="splitSectionRow('{{ $part['key'] }}')">Split out</flux:button>
                        </div>
                    @endforeach
                </div>
            @endforeach
        </div>
    @endif
</div>
