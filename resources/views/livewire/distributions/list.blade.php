{{-- PROJECT DETAILS --}}
<flux:card>
    <div class="flex justify-between">
        <flux:heading size="lg">Distributions</flux:heading>
        <flux:button
            size="sm"
            wire:click="$dispatchTo('distributions.distribution-create', 'newDistribution')"
            >
            Add New
        </flux:button>
    </div>

    <livewire:distributions.distribution-create />

    <flux:subheading class="m-0">Split Project profits between shareholders.</flux:subheading>

    <div class="space-y-2">
        <flux:table>
            <flux:table.columns>
                <flux:table.column>Distribution</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->distributions as $distribution)
                    <flux:table.row :key="$distribution->id">
                        {{-- detail="{{$registration == TRUE ? '' : 'Balance: '}}" href="{{$registration == TRUE ? '' : route('distributions.show', $distribution->id)}}"  --}}
                        <flux:table.cell>{{ $distribution->name }}</flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </div>
</flux:card>
