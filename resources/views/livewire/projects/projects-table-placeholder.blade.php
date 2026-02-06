<x-island-card heading="Projects" wire:transition>

    <div class="space-y-2 overflow-x-hidden">
        <flux:table class="table-fixed w-full">
            <flux:table.columns>
                <flux:table.column class="w-[30%] min-w-0">Address</flux:table.column>
                @if(!auth()->user()->is_client_user)
                    <flux:table.column class="w-[25%] min-w-0">Client</flux:table.column>
                @endif
                <flux:table.column class="w-[25%] min-w-0">Name</flux:table.column>
                @if(auth()->user()->is_client_user)
                    <flux:table.column class="w-[25%] min-w-0">Contractor</flux:table.column>
                @endif
                <flux:table.column align="end" class="w-[30%] min-w-[5rem] shrink-0">Status</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                <flux:skeleton.group animate="shimmer">
                    @for ($i = 0; $i < 5; $i++)
                        <flux:table.row>
                            <flux:table.cell>
                                <flux:skeleton.line class="w-32" />
                            </flux:table.cell>
                            @if(!auth()->user()->is_client_user)
                                <flux:table.cell>
                                    <flux:skeleton.line class="w-24" />
                                </flux:table.cell>
                            @endif
                            <flux:table.cell>
                                <flux:skeleton.line class="w-28" />
                            </flux:table.cell>
                            @if(auth()->user()->is_client_user)
                                <flux:table.cell>
                                    <flux:skeleton.line class="w-24" />
                                </flux:table.cell>
                            @endif
                            <flux:table.cell align="end">
                                <flux:skeleton class="h-5 w-16 rounded-full" />
                            </flux:table.cell>
                        </flux:table.row>
                    @endfor
                </flux:skeleton.group>
            </flux:table.rows>
        </flux:table>
    </div>
</x-island-card>
