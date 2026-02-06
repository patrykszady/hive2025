<x-island-card heading="Distributions" subheading="Assign receipt emails to team members or office accounts.">
    <x-slot:actions>
        <flux:button
            size="sm"
            icon="plus"
            wire:click="$dispatchTo('distributions.distribution-create', 'newDistribution')"
            >
            Add New
        </flux:button>
    </x-slot:actions>

    <livewire:distributions.distribution-create />

    <div class="space-y-2">
        <flux:table>
            <flux:table.columns>
                <flux:table.column>Distribution</flux:table.column>
                <flux:table.column class="text-right">Balance</flux:table.column>
                <flux:table.column class="text-right">YTD Earned</flux:table.column>
                <flux:table.column class="text-right">YTD Paid</flux:table.column>
                <flux:table.column class="text-right">YTD Balance</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->distributions as $distribution)
                    <flux:table.row :key="$distribution->id">
                        <flux:table.cell>
                            <a href="{{ route('distributions.show', $distribution) }}" class="hover:underline">
                                {{ $distribution->name }}
                            </a>
                        </flux:table.cell>
                        <flux:table.cell class="text-right {{ $distribution->balance < 0 ? 'text-red-600' : '' }}">
                            {{ money($distribution->balance ?? 0) }}
                        </flux:table.cell>
                        <flux:table.cell class="text-right">{{ money($distribution->ytd_earned ?? 0) }}</flux:table.cell>
                        <flux:table.cell class="text-right">{{ money($distribution->ytd_paid ?? 0) }}</flux:table.cell>
                        <flux:table.cell class="text-right {{ $distribution->ytd_balance < 0 ? 'text-red-600' : '' }}">
                            {{ money($distribution->ytd_balance ?? 0) }}
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </div>
</x-island-card>
