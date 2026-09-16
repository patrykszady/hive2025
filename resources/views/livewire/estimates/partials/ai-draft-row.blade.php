{{-- One drafted line, laid out like a line on the estimate itself. Streamed
     in while Claude drafts ($editable false) and shown again, now clickable
     and with a live quantity box, once the draft is complete. Both modes
     keep the same cells so the table never changes shape. --}}
@php
    $editable = $editable ?? false;
    $quantity = is_numeric($item['quantity'] ?? null) && (float) $item['quantity'] > 0 ? (float) $item['quantity'] : 1.0;
    $cost = (float) ($item['cost'] ?? 0);
    $unit = $item['unit_type'] ?? 'no_unit';
    $lumpSum = $unit === 'no_unit';
    $quantityLabel = rtrim(rtrim(number_format($quantity, 2, '.', ''), '0'), '.');
@endphp
<flux:table.row
    :class="$editable ? '' : 'animate-fade-in'"
    :wire:key="$editable ? 'draft-line-'.($item['id'] ?? $index) : null"
>
    <flux:table.cell class="align-top !pl-6">{{ $index + 1 }}.</flux:table.cell>
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
        </div>
    </flux:table.cell>
    <flux:table.cell class="align-top">
        @if($lumpSum)
        @elseif($editable)
            <flux:input type="number" step="0.1" min="0.1" size="sm" class="w-24" wire:model.live.debounce.500ms="generatedItems.{{ $index }}.quantity" />
        @else
            <flux:input type="number" size="sm" class="w-24" value="{{ $quantityLabel }}" readonly />
        @endif
    </flux:table.cell>
    <flux:table.cell class="align-top">{{ $lumpSum ? '' : $unit }}</flux:table.cell>
    <flux:table.cell class="align-top">{{ $lumpSum ? '' : money($cost) }}</flux:table.cell>
    <flux:table.cell variant="strong" class="align-top" :x-text="$editable ? 'money(lineTotal('.$index.'))' : null">{{ money($quantity * $cost) }}</flux:table.cell>
    <flux:table.cell class="align-top !pr-6">
        @if($editable)
            <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="removeItem({{ $index }})" />
        @else
            <flux:icon.check class="w-4 h-4 text-green-500 draft-row-done" />
            <flux:icon.arrow-path class="w-4 h-4 text-indigo-500 animate-spin draft-row-current" />
        @endif
    </flux:table.cell>
</flux:table.row>
