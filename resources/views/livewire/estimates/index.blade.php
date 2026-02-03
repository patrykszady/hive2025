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
                                <flux:table.cell variant="strong">
                                    <div class="flex items-center gap-2">
                                        <flux:link
                                            href="{{route('estimates.show', $estimate->id)}}"
                                            variant="ghost"
                                            :accent="false"
                                            class="font-bold no-underline hover:no-underline hover:text-indigo-600 dark:hover:text-indigo-400"
                                            wire:navigate.hover
                                        >
                                            {{ money($estimate->estimate_sections->sum('total')) }}
                                        </flux:link>
                                        <flux:badge
                                            size="sm"
                                            :color="$estimate->status === 'Active' ? 'green' : 'orange'"
                                            inset="top bottom"
                                            >
                                            {{$estimate->status}}
                                        </flux:badge>
                                    </div>
                                </flux:table.cell>
                            @else
                                <flux:table.cell>
                                    <div class="flex items-center gap-2">
                                        <span>{{ money($estimate->estimate_sections->sum('total')) }}</span>
                                        <flux:badge
                                            size="sm"
                                            color="orange"
                                            inset="top bottom"
                                            >
                                            {{$estimate->status}}
                                        </flux:badge>

                                        <flux:dropdown position="bottom" align="start">
                                            <flux:button
                                                variant="ghost"
                                                size="xs"
                                                icon="cog-6-tooth"
                                                class="!p-1"
                                                aria-label="Estimate actions"
                                            />

                                            <flux:menu>
                                                <flux:menu.item icon="arrow-path" wire:click="activateEstimate({{ $estimate->id }})">Restore</flux:menu.item>
                                                <flux:menu.item icon="trash" wire:click="removeEstimate({{ $estimate->id }})" variant="danger">Delete</flux:menu.item>
                                            </flux:menu>
                                        </flux:dropdown>
                                    </div>
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
