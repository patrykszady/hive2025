<x-form-modal
    name="project_distributions_modal"
    :title="$project && $project->short_address ? $project->short_address.' Distributions' : 'Project distributions'"
    class="max-w-lg"
>
    @if ($project)
        @php
            $projectProfit = (float) data_get($project->finances, 'profit', 0);
            $projectTotal = (float) data_get($project->finances, 'total_project', 0);
            $projectProfitPercent = $projectTotal > 0
                ? (int) round(($projectProfit / $projectTotal) * 100)
                : 0;
        @endphp

        <x-slot name="subtitle">
            <flux:subheading class="truncate">
                {{ $project->client?->last_names ?? $project->client?->name ?? 'No Client' }}
                <span class="text-zinc-500 dark:text-zinc-400">—</span>
                <a
                    wire:navigate.hover
                    href="{{ route('projects.show', $project) }}"
                    class="hover:underline underline-offset-2"
                >
                    {{ $project->project_name ?: 'View project' }}
                </a>
            </flux:subheading>
        </x-slot>

        <div class="grid grid-cols-2 gap-2 mb-3">
            <flux:card class="space-y-0.5 p-3">
                <flux:text class="text-xs text-zinc-600 dark:text-zinc-400">Profit</flux:text>
                <div class="flex items-baseline gap-2">
                    <flux:heading size="md">{{ money(data_get($project->finances, 'profit')) }}</flux:heading>
                    @if ($projectProfitPercent > 0)
                        <span class="text-xs text-zinc-600 dark:text-zinc-400">{{ $projectProfitPercent }}%</span>
                    @endif
                </div>
            </flux:card>
            <flux:card class="space-y-0.5 p-3">
                <flux:text class="text-xs text-zinc-600 dark:text-zinc-400">Balance</flux:text>
                <flux:heading size="md">{{ money(data_get($project->finances, 'balance')) }}</flux:heading>
            </flux:card>
        </div>

        <form id="project_distributions_form" wire:submit="store" class="space-y-2">
            <div class="grid grid-cols-1 gap-2">
                @foreach ($distributions as $index => $distribution)
                    <flux:card class="p-3">
                        <div class="flex items-center justify-between gap-3">
                            <div class="min-w-0">
                                <flux:heading size="sm" class="truncate">{{ $distribution['name'] }}</flux:heading>
                                <flux:text class="text-xs text-zinc-600 dark:text-zinc-400">
                                    Amount: {{ $distribution['amount'] !== null ? money($distribution['amount']) : '—' }}
                                </flux:text>
                            </div>

                            <div class="w-28 shrink-0">
                                <flux:input.group>
                                    <flux:input
                                        type="number"
                                        inputmode="numeric"
                                        min="0"
                                        max="100"
                                        step="5"
                                        placeholder="0"
                                        wire:model.blur="distributions.{{ $index }}.percent"
                                    />
                                    <flux:input.group.suffix>%</flux:input.group.suffix>
                                </flux:input.group>
                                <flux:error name="distributions.{{ $index }}.percent" />
                            </div>
                        </div>
                    </flux:card>
                @endforeach
            </div>

            <flux:error name="percent_distributions_sum" />
        </form>

        <x-slot name="footer">
            <flux:modal.close>
                <flux:button type="button" variant="subtle">Cancel</flux:button>
            </flux:modal.close>

            <div class="flex-1 flex justify-center">
                <flux:button
                    type="button"
                    variant="filled"
                    color="{{ $this->percent_sum === 100 ? 'green' : 'orange' }}"
                    disabled
                >
                    {{ $this->percent_sum > 0 ? $this->percent_sum.'%' : '—%' }}
                </flux:button>
            </div>

            <flux:button type="submit" form="project_distributions_form" variant="primary">Save</flux:button>
        </x-slot>
    @endif
</x-form-modal>
