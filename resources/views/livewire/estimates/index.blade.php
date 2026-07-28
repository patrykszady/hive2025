@php
    // Embedded in a show-page column: inherit that column's spacing/width.
    $embedded = $view !== 'estimates.index';
@endphp
<div class="{{ $embedded ? 'space-y-4' : 'max-w-3xl' }}" wire:transition>
    @if($view === NULL)
        <x-island-card heading="Filters" class="mb-4">
        </x-island-card>
    @endif

    {{-- Standalone page: lazy island so the card paints the shared skeleton
         first, like the Payments/Checks cards. The card embedded on
         projects.show is already lazy-mounted (placeholder() handles it), so it
         renders immediately here. --}}
    @island(name: 'estimates-table', lazy: island_lazy($view === 'estimates.index'), always: true)
        @placeholder
            <x-index-table.placeholder
                heading="Estimates"
                :columns="\App\Livewire\Estimates\EstimatesIndex::columnDefs($view)"
                :rows="\App\Livewire\Estimates\EstimatesIndex::placeholderRows()"
                :compact="false"
            />
        @endplaceholder
    @php
        // Active estimates are the ones that matter day to day; superseded /
        // disabled ones collapse behind the header toggle, like the Email
        // Tracking card's history.
        $allEstimates = collect($this->estimates->items());
        $activeEstimates = $allEstimates->filter(fn ($e) => $e->status === 'Active')->values();
        $olderEstimates = $allEstimates->reject(fn ($e) => $e->status === 'Active')->values();
        // Nothing active? Show them all rather than an empty-looking card.
        if ($activeEstimates->isEmpty()) {
            $activeEstimates = $olderEstimates;
            $olderEstimates = collect();
        }
        $hasEstimateHistory = $olderEstimates->isNotEmpty();
        $estimateTableClass = $view === 'estimates.index' ? 'index-table' : 'table-fixed min-w-0 w-full';
    @endphp
    <x-index-table heading="Estimates" :paginator="$this->estimates"
        x-data="{ open: false }" :clickable="$hasEstimateHistory">
        <x-slot:badge>
            <div class="{{ $hasEstimateHistory ? 'contents' : 'hidden' }}">
                <flux:badge color="zinc" size="sm">{{ $allEstimates->count() }}</flux:badge>
            </div>
        </x-slot:badge>
        <x-slot:actions>
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
            <div class="{{ $hasEstimateHistory ? 'contents' : 'hidden' }}">
                <button type="button" @click.stop="open = !open" class="flex items-center p-1 text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300 cursor-pointer" aria-label="Toggle previous estimates">
                    <flux:icon.chevron-down variant="mini" class="transition-transform duration-200" ::class="open && 'rotate-180'" />
                </button>
            </div>
        </x-slot:actions>

        {{-- Empty card = header only (no stray table spacing) — same pattern as
             the Projects card on clients.show. --}}
        @if($allEstimates->isNotEmpty())
            <flux:table class="{{ $estimateTableClass }} [:where(&)]:p-0 [:where(&)]:space-y-0">
                @if($view === 'estimates.index')
                    <flux:table.columns>
                        <flux:table.column class="w-[40%] min-w-0">Estimate</flux:table.column>
                        <flux:table.column class="w-[25%]">Date</flux:table.column>
                        <flux:table.column class="w-[35%] min-w-0">Client</flux:table.column>
                    </flux:table.columns>
                @endif

                @include('livewire.estimates.partials.rows', ['estimateRows' => $activeEstimates])
            </flux:table>

            @if($hasEstimateHistory)
                {{-- Superseded estimates: a SECOND table inside a plain div —
                     x-collapse animates height, which table elements ignore.
                     Same column widths, so the two tables stay aligned. --}}
                <div x-show="open" x-collapse x-cloak>
                    <flux:table class="{{ $estimateTableClass }} [:where(&)]:p-0 [:where(&)]:space-y-0">
                        @if($view === 'estimates.index')
                            <colgroup>
                                <col class="w-[40%]">
                                <col class="w-[25%]">
                                <col class="w-[35%]">
                            </colgroup>
                        @endif

                        @include('livewire.estimates.partials.rows', ['estimateRows' => $olderEstimates])
                    </flux:table>
                </div>
            @endif
        @endif
    </x-index-table>
    @endisland
</div>