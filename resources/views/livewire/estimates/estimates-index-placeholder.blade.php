<div class="max-w-3xl">
    <x-island-card heading="Estimates" wire:transition>
        <x-slot:actions>
            <flux:skeleton class="h-8 w-28 rounded-md" animate="shimmer" />
        </x-slot:actions>

        <flux:table>
            <flux:table.columns>
                <flux:table.column>Estimate</flux:table.column>
                <flux:table.column>Date</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                <flux:skeleton.group animate="shimmer">
                    <flux:table.row>
                        <flux:table.cell>
                            <div class="flex items-center gap-2">
                                <flux:skeleton.line class="w-20" />
                                <flux:skeleton class="h-5 w-16 rounded-full" />
                            </div>
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:skeleton.line class="w-20" />
                        </flux:table.cell>
                    </flux:table.row>
                </flux:skeleton.group>
            </flux:table.rows>
        </flux:table>
    </x-island-card>
</div>
