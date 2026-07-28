<x-island-card :enter="true" heading="Project Finances" wire:transition>
    <x-slot:actions>
        @isset($project)
            @include('livewire.projects.partials.finances-actions')
        @endisset
    </x-slot:actions>

    <flux:separator variant="subtle" />

    <div class="card-flush-bottom">
    <flux:table>
        <flux:table.rows>
            <flux:skeleton.group animate="shimmer">
                {{-- Regular rows --}}
                @foreach (['Estimate', 'Change Order', 'Reimbursements'] as $label)
                    <flux:table.row>
                        <flux:table.cell>{{ $label }}</flux:table.cell>
                        <flux:table.cell><flux:skeleton.line class="w-20" /></flux:table.cell>
                    </flux:table.row>
                @endforeach

                {{-- Strong row: TOTAL PROJECT --}}
                <flux:table.row>
                    <flux:table.cell variant="strong">TOTAL PROJECT</flux:table.cell>
                    <flux:table.cell variant="strong" align="end"><flux:skeleton.line class="w-24 ml-auto" /></flux:table.cell>
                </flux:table.row>

                {{-- Cost rows --}}
                @foreach (['Expenses', 'Timesheets'] as $label)
                    <flux:table.row>
                        <flux:table.cell>{{ $label }}</flux:table.cell>
                        <flux:table.cell><flux:skeleton.line class="w-20" /></flux:table.cell>
                    </flux:table.row>
                @endforeach

                {{-- Strong row: TOTAL COST --}}
                <flux:table.row>
                    <flux:table.cell variant="strong">TOTAL COST</flux:table.cell>
                    <flux:table.cell variant="strong" align="end"><flux:skeleton.line class="w-24 ml-auto" /></flux:table.cell>
                </flux:table.row>

                {{-- Bottom rows --}}
                <flux:table.row>
                    <flux:table.cell>Payments</flux:table.cell>
                    <flux:table.cell><flux:skeleton.line class="w-20" /></flux:table.cell>
                </flux:table.row>
                @if($showProfit ?? false)
                    {{-- Only on Complete/Service Call, same as the loaded card. --}}
                    <flux:table.row>
                        <flux:table.cell variant="strong">Profit</flux:table.cell>
                        <flux:table.cell variant="strong"><flux:skeleton.line class="w-20" /></flux:table.cell>
                    </flux:table.row>
                @endif
                <flux:table.row>
                    <flux:table.cell>Balance</flux:table.cell>
                    <flux:table.cell><flux:skeleton.line class="w-20" /></flux:table.cell>
                </flux:table.row>
            </flux:skeleton.group>
        </flux:table.rows>
    </flux:table>
    </div>
</x-island-card>
