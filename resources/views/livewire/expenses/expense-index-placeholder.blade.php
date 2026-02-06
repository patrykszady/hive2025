<x-island-card heading="Expenses" :separator="true" wire:transition>

    <flux:table>
        <flux:table.columns>
            <flux:table.column>Amount</flux:table.column>
            <flux:table.column>Date</flux:table.column>
            <flux:table.column>Vendor</flux:table.column>
            <flux:table.column align="end">Status</flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            <flux:skeleton.group animate="shimmer">
                @for ($i = 0; $i < 4; $i++)
                    <flux:table.row>
                        <flux:table.cell><flux:skeleton.line class="w-20" /></flux:table.cell>
                        <flux:table.cell><flux:skeleton.line class="w-24" /></flux:table.cell>
                        <flux:table.cell><flux:skeleton.line class="w-28" /></flux:table.cell>
                        <flux:table.cell align="end"><flux:skeleton class="h-5 w-16 rounded-full" /></flux:table.cell>
                    </flux:table.row>
                @endfor
            </flux:skeleton.group>
        </flux:table.rows>
    </flux:table>
</x-island-card>
