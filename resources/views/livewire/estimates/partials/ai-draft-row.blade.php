{{-- One drafted line, laid out like a line on the estimate itself. Shown
     read-only while Claude drafts ($editable false) and again, now clickable
     and with a live quantity box, once the draft is complete. Both modes
     keep the same cells so the table never changes shape. The row's key
     carries its position: the quantity box, the live total and the remove
     button all address the row by position, so when a removal shifts the
     list each later row is rebuilt rather than kept with its old position
     (which typed into, and totalled, the next line). --}}
@php
    $editable = $editable ?? false;
    $quantity = is_numeric($item['quantity'] ?? null) && (float) $item['quantity'] > 0 ? (float) $item['quantity'] : 1.0;
    $cost = (float) ($item['cost'] ?? 0);
    $unit = $item['unit_type'] ?? 'no_unit';
    // No unit: one flat price per line — shown and, in the review, typed like any other (2026-11-13).
    $lumpSum = $unit === 'no_unit';
    $quantityLabel = rtrim(rtrim(number_format($quantity, 2, '.', ''), '0'), '.');
@endphp
<flux:table.row
    :class="$editable ? '' : 'animate-fade-in'"
    :wire:key="'draft-line-'.($item['id'] ?? 'new').'-at-'.$index"
>
    <flux:table.cell class="align-top !pl-6">{{ $number ?? $index + 1 }}.</flux:table.cell>
    <flux:table.cell variant="strong" class="align-top !whitespace-normal break-words">
        <div class="flex flex-col min-w-0">
            <div class="leading-5">
                @if($editable && !empty($item['id']))
                    <a class="cursor-pointer min-w-0" wire:click="$dispatchTo('line-items.estimate-line-item-create', 'editOnEstimate', { estimate_line_item_id: {{ $item['id'] }} })">
                        <b>{{ $item['name'] ?? 'Unknown' }}</b>
                    </a>
                @else
                    <b>{{ $item['name'] ?? 'Unknown' }}</b>
                @endif
            </div>
            <div class="leading-5"><i>{{ $item['category'] ?? '' }}@if(!empty($item['sub_category']))/{{ $item['sub_category'] }}@endif</i></div>
            {{-- The line's notes, the client's to read on the signing page; click the line to change them (2026-11-14). --}}
            @if(!empty($item['notes']))
                <div class="mt-1 text-xs font-normal italic leading-4 text-zinc-500 dark:text-zinc-400 whitespace-pre-line" data-line-notes>{{ $item['notes'] }}</div>
            @endif
            {{-- Drafted lines from several sections, now one line (Combine Items). --}}
            @if(!empty($item['combined']))
                <div class="mt-1"><flux:badge size="sm" color="zinc" icon="arrows-pointing-in" data-combined-line>Combined</flux:badge></div>
            @endif
        </div>
    </flux:table.cell>
    <flux:table.cell class="align-top">
        @if($editable)
            <flux:input type="number" step="0.1" min="0.1" size="sm" class="w-24" wire:model.live.debounce.500ms="generatedItems.{{ $index }}.quantity" aria-label="Quantity of {{ $item['name'] ?? 'this line' }}" />
        @else
            <flux:input type="number" size="sm" class="w-24" value="{{ $quantityLabel }}" readonly />
        @endif
    </flux:table.cell>
    <flux:table.cell class="align-top">{{ $lumpSum ? '' : $unit }}</flux:table.cell>
    {{-- A no-unit line (Painting, Demo) carries the catalog's one flat price, which the estimator sets per job: its price is typed here (2026-11-13). --}}
    <flux:table.cell class="align-top">
        @if($lumpSum && $editable)
            <flux:input type="number" step="0.01" min="0" size="sm" class="w-28" icon="currency-dollar" wire:model.live.debounce.700ms="generatedItems.{{ $index }}.cost" aria-label="Price of {{ $item['name'] ?? 'this line' }}" data-lump-sum-price />
        @else
            {{ money($cost) }}
        @endif
    </flux:table.cell>
    <flux:table.cell variant="strong" class="align-top" :x-text="$editable ? 'money(lineTotal('.$index.'))' : null">{{ money($quantity * $cost) }}</flux:table.cell>
    <flux:table.cell class="align-top !pr-6">
        @if($editable)
            <div class="flex items-center justify-end gap-1">
                {{-- In more than one section: combine this catalog item alone (same global line_item id), 2026-11-14. --}}
                @if(in_array((int) ($item['line_item_id'] ?? 0), $repeatedIds ?? [], true))
                    <flux:button size="xs" variant="ghost" icon="arrows-pointing-in" wire:click="combineItem({{ (int) $item['line_item_id'] }})" aria-label="Combine every {{ $item['name'] ?? 'line' }} into one line" tooltip="Combine every {{ $item['name'] ?? 'line' }} into one line" data-combine-item />
                @endif
                <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="removeItem({{ $index }}{{ !empty($item['id']) ? ', '.(int) $item['id'] : '' }})" aria-label="Remove {{ $item['name'] ?? 'this line' }}" />
            </div>
        @else
            <flux:icon.check class="w-4 h-4 text-green-500 draft-row-done" />
            <flux:icon.arrow-path class="w-4 h-4 text-indigo-500 animate-spin draft-row-current" />
        @endif
    </flux:table.cell>
</flux:table.row>
