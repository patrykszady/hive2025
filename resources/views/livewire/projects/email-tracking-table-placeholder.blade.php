<x-island-card heading="Email Tracking" :separator="true" wire:transition>

    <div class="space-y-2">
        <flux:table>
            <flux:table.columns>
                <flux:table.column>Event</flux:table.column>
                <flux:table.column>Template</flux:table.column>
                <flux:table.column>Project</flux:table.column>
                <flux:table.column class="w-48">Recipients</flux:table.column>
                <flux:table.column>Date</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                <flux:skeleton.group animate="shimmer">
                    <flux:table.row>
                        <flux:table.cell>
                            <flux:skeleton class="h-5 w-16 rounded-full" />
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:skeleton class="h-5 w-24 rounded-full" />
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:skeleton.line class="w-28" />
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:skeleton.line class="w-32" />
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:skeleton.line class="w-20" />
                        </flux:table.cell>
                    </flux:table.row>
                </flux:skeleton.group>
            </flux:table.rows>
        </flux:table>
    </div>
</x-island-card>
