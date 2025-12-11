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
            <flux:table :paginate="$this->estimates->hasPages() ? $this->estimates : null">
                <flux:table.columns>
                    <flux:table.column>Estimate</flux:table.column>
                    <flux:table.column>Date</flux:table.column>
                    @if($view === 'estimates.index')
                        <flux:table.column>Client</flux:table.column>
                    @endif
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
                                    {{ money($estimate->estimate_sections->sum('total')) }}
                                    <flux:badge
                                        size="sm"
                                        :color="$estimate->status === 'Active' ? 'green' : 'orange'"
                                        inset="top bottom"
                                        >
                                        {{$estimate->status}}
                                    </flux:badge>
                                </flux:table.cell>
                            @else
                                <flux:table.cell>
                                    <flux:dropdown position="bottom" align="start">
                                        <flux:button variant="ghost" size="sm" class="!justify-start !p-0">
                                            {{ money($estimate->estimate_sections->sum('total')) }}
                                            <flux:badge
                                                size="sm"
                                                color="orange"
                                                inset="top bottom"
                                                class="ml-2"
                                                >
                                                {{$estimate->status}}
                                            </flux:badge>
                                        </flux:button>

                                        <flux:menu>
                                            <flux:menu.item icon="arrow-path" wire:click="activateEstimate({{ $estimate->id }})">Restore</flux:menu.item>
                                            <flux:menu.item icon="trash" wire:click="removeEstimate({{ $estimate->id }})" variant="danger">Delete</flux:menu.item>
                                        </flux:menu>
                                    </flux:dropdown>
                                </flux:table.cell>
                            @endif
                            
                            <flux:table.cell>{{ $estimate->created_at->format('m/d/y') }}</flux:table.cell>
                            
                            @if($view === 'estimates.index')
                                <flux:table.cell
                                    wire:navigate.hover
                                    href="{{route('clients.show', $estimate->project->client->id)}}"
                                    class="cursor-pointer"
                                    >
                                    {{ $estimate->project->client->name }}
                                </flux:table.cell>
                            @endif
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>
    </flux:card>
</div>
