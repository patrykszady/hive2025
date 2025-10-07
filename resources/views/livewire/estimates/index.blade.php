<div class="max-w-3xl">
    @if($view === NULL)
        <flux:card class="space-y-2 mb-4">
            <div class="flex justify-between">
                <flux:heading size="lg">Filters</flux:heading>
            </div>
        </flux:card>
    @endif

    <flux:card class="space-y-2">
        <div class="flex justify-between">
            <flux:heading size="lg">Estimates</flux:heading>
            @if($view !== 'estimates.index')
                @can('create', [App\Models\Estimate::class, $project])
                    <flux:button
                        href="{{route('estimates.create', $project->id)}}"
                        size="sm"
                        >
                        Add Estimate
                    </flux:button>
                @endcan
            @endif
        </div>

        <div class="space-y-2">
            <flux:table :paginate="$this->estimates">
                <flux:table.columns>
                    <flux:table.column>Estimate</flux:table.column>
                    <flux:table.column>Amount</flux:table.column>
                    <flux:table.column>Date</flux:table.column>
                    @if($view === 'estimates.index')
                        <flux:table.column>Client</flux:table.column>
                    @endif
                    {{-- <flux:table.column>Status</flux:table.column> --}}
                    <flux:table.column></flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach($this->estimates as $estimate)
                        <flux:table.row :key="$estimate->id">
                            @if($estimate->status === 'Active')
                                <flux:table.cell
                                    wire:navigate.hover
                                    href="{{route('estimates.show', $estimate->id)}}"
                                    variant="strong"
                                    class="cursor-pointer"
                                    >
                                    # {{ $estimate->id }}
                                    <flux:badge
                                        size="sm"
                                        :color="$estimate->status === 'Active' ? 'green' : 'red'"
                                        inset="top bottom"
                                        >
                                        {{$estimate->status}}
                                    </flux:badge>
                                </flux:table.cell>
                            @else
                                <flux:table.cell>
                                    # {{ $estimate->id }}
                                    <flux:badge
                                        size="sm"
                                        :color="$estimate->status === 'Active' ? 'green' : 'red'"
                                        inset="top bottom"
                                        >
                                        {{$estimate->status}}
                                    </flux:badge>
                                </flux:table.cell>
                            @endif

                            <flux:table.cell>{{ money($estimate->estimate_sections->sum('total')) }}</flux:table.cell>
                            
                            <flux:table.cell>{{ $estimate->created_at->format('m/d/Y') }}</flux:table.cell>
                            
                            @if($view === 'estimates.index')
                                <flux:table.cell
                                    wire:navigate.hover
                                    href="{{route('clients.show', $estimate->project->client->id)}}"
                                    class="cursor-pointer"
                                    >
                                    {{ $estimate->project->client->name }}
                                </flux:table.cell>
                            @endif

                            <flux:table.cell>
                                <flux:dropdown position="bottom" align="end">
                                    <flux:button inset="top bottom" size="sm" icon="ellipsis-horizontal"></flux:button>

                                    <flux:menu>
                                        @if($estimate->status === 'Active')
                                            <flux:menu.item href="{{route('estimates.show', $estimate->id)}}">Open</flux:menu.item>
                                            <flux:menu.item wire:click="disableEstimate({{ $estimate->id }})" variant="danger">Disable</flux:menu.item>
                                        @else
                                            <flux:menu.item wire:click="activateEstimate({{ $estimate->id }})">Restore</flux:menu.item>
                                            <flux:menu.item wire:click="removeEstimate({{ $estimate->id }})" variant="danger">Delete</flux:menu.item>
                                        @endif
                                    </flux:menu>
                                </flux:dropdown>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>
    </flux:card>
</div>
