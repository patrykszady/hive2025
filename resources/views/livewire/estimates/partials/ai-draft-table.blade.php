{{-- The drafted lines, in the same card and columns as a section on the
     estimate. While drafting the rows stream in; afterwards the same table
     shows them from the estimate, editable. --}}
@php
    $drafting = ($mode ?? 'review') === 'drafting';
@endphp
<flux:card class="!p-0 overflow-hidden">
    <flux:table>
        <flux:table.columns>
            <flux:table.column class="w-10 !pl-6"></flux:table.column>
            <flux:table.column class="w-1/3">Item</flux:table.column>
            <flux:table.column>Quantity</flux:table.column>
            <flux:table.column>Unit</flux:table.column>
            <flux:table.column>Cost</flux:table.column>
            <flux:table.column>Total</flux:table.column>
            <flux:table.column class="w-12 !pr-6"></flux:table.column>
        </flux:table.columns>

        @if($drafting)
            <flux:table.rows class="draft-rows" wire:stream="draft-rows" x-ref="rows"></flux:table.rows>
        @else
            <flux:table.rows>
                @foreach($items as $index => $item)
                    @include('livewire.estimates.partials.ai-draft-row', ['item' => $item, 'index' => $index, 'editable' => true])
                @endforeach
            </flux:table.rows>
        @endif

        <flux:table.rows>
            <flux:table.row class="bg-zinc-50 dark:bg-zinc-800/50">
                <flux:table.cell colspan="5" class="!pl-6 text-right font-semibold">Total:</flux:table.cell>
                @if($drafting)
                    <flux:table.cell variant="strong" class="text-lg" wire:stream="draft-total" x-ref="total">$0.00</flux:table.cell>
                @else
                    <flux:table.cell variant="strong" class="text-lg" x-text="money(total())">{{ money($total ?? 0) }}</flux:table.cell>
                @endif
                <flux:table.cell class="!pr-6"></flux:table.cell>
            </flux:table.row>
        </flux:table.rows>
    </flux:table>
</flux:card>
