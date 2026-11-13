{{-- The same catalog item in several sections, combined into one line in a
     section of its own (App\Livewire\Estimates\EstimateCombineLineItems). --}}
<div>
    <flux:modal name="estimate-combine-line-items-modal" class="w-full max-w-3xl">
        <form wire:submit="combine" class="space-y-5">
            <div>
                <flux:heading size="lg">Combine line items</flux:heading>
                <flux:text class="mt-1">An item priced in several sections becomes one line in a section of its own, quantities and totals added up. The lines it replaces come out of their sections (each can be restored there), and the estimate total does not change.</flux:text>
            </div>

            @if ($this->candidates === [])
                <flux:callout variant="secondary" icon="information-circle">
                    <flux:callout.text>No item is on this estimate in more than one section.</flux:callout.text>
                </flux:callout>
            @else
                <flux:checkbox.group wire:model.live="selected" class="divide-y divide-zinc-100 rounded-lg border border-zinc-200 dark:divide-white/5 dark:border-white/10">
                    @foreach ($this->candidates as $item)
                        @php
                            $lumpSum = $item['unit_type'] === 'no_unit';
                            $sections = collect($item['lines'])->groupBy('section_id');
                            $breakdown = [];
                            foreach ($sections as $inSection) {
                                $breakdown[] = $inSection->first()['section']
                                    .($lumpSum ? '' : ' '.rtrim(rtrim(number_format($inSection->sum('quantity'), 2), '0'), '.'))
                                    .' ('.money($inSection->sum('total')).')';
                            }
                            // Priced differently from section to section: a line per unit price, never an averaged one.
                            $prices = count($item['becomes']);
                            $becomes = $prices > 1
                                ? 'Priced '.$prices.' ways, so it becomes '.$prices.' lines: '.collect($item['becomes'])->map(fn (array $line) => rtrim(rtrim(number_format($line['quantity'], 2), '0'), '.').' '.$item['unit_type'].' at '.money($line['cost']))->implode(' · ')
                                : '';
                        @endphp
                        <label class="flex cursor-pointer items-start gap-3 px-4 py-3" wire:key="combine-item-{{ $item['key'] }}">
                            <flux:checkbox value="{{ $item['key'] }}" class="mt-0.5" />
                            <div class="min-w-0 flex-1">
                                <div class="flex items-baseline justify-between gap-3">
                                    <div class="min-w-0 truncate text-sm font-medium text-zinc-800 dark:text-white">
                                        {{ $item['name'] }}
                                        <span class="font-normal italic text-zinc-500">{{ $item['category'] }}@if($item['sub_category'])/{{ $item['sub_category'] }}@endif</span>
                                    </div>
                                    <div class="shrink-0 whitespace-nowrap text-sm text-zinc-800 dark:text-white">
                                        @unless($lumpSum){{ rtrim(rtrim(number_format($item['quantity'], 2), '0'), '.') }} {{ $item['unit_type'] }} · @endunless<span class="font-medium">{{ money($item['total']) }}</span>
                                    </div>
                                </div>
                                <div class="mt-0.5 text-xs text-zinc-500">
                                    {{ $sections->count() }} sections: {{ implode(' · ', $breakdown) }}{{ $item['allowances'] > 0 ? ' · allowances '.money($item['allowances']) : '' }}
                                </div>
                                @if($becomes !== '')
                                    <div class="mt-0.5 text-xs text-amber-700 dark:text-amber-400" data-priced-differently>{{ $becomes }}</div>
                                @endif
                            </div>
                        </label>
                    @endforeach
                </flux:checkbox.group>
                <flux:error name="selected" />

                <flux:field>
                    <flux:label>Section for the combined items</flux:label>
                    <flux:input wire:model="sectionName" placeholder="e.g. Flooring" maxlength="60" />
                    <flux:description>A new section at the end of the estimate, or one already carrying this name.</flux:description>
                    <flux:error name="sectionName" />
                </flux:field>
            @endif

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>
                @if ($this->candidates !== [])
                    <flux:button type="submit" variant="primary" icon="arrows-pointing-in" wire:loading.attr="disabled" wire:target="combine">Combine</flux:button>
                @endif
            </div>
        </form>
    </flux:modal>
</div>
