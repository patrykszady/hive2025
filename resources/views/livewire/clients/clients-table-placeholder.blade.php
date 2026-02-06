<div>
    <x-island-card heading="Clients" wire:transition>
        <div class="space-y-2 overflow-x-hidden">
            <flux:table class="table-fixed w-full">
                <flux:table.columns>
                    <flux:table.column class="w-[35%] min-w-0">Name</flux:table.column>
                    <flux:table.column class="w-[40%] min-w-0">Address</flux:table.column>
                    <flux:table.column class="w-[25%] min-w-0">Created</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    <flux:skeleton.group animate="shimmer">
                        @for ($i = 0; $i < 5; $i++)
                            <flux:table.row>
                                <flux:table.cell>
                                    <flux:skeleton.line class="w-32" />
                                </flux:table.cell>
                                <flux:table.cell>
                                    <flux:skeleton.line class="w-40" />
                                </flux:table.cell>
                                <flux:table.cell>
                                    <flux:skeleton.line class="w-20" />
                                </flux:table.cell>
                            </flux:table.row>
                        @endfor
                    </flux:skeleton.group>
                </flux:table.rows>
            </flux:table>
        </div>
    </x-island-card>
</div>
