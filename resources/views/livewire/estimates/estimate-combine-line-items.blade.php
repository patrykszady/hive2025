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
                {{-- One catalog item on its own: only lines with its global line_item id are listed and combined (2026-11-14). --}}
                @if (count($this->itemChoices) > 1)
                    <flux:select wire:model.live="only" label="Show" size="sm" data-combine-only>
                        <flux:select.option value="">Every item ({{ count($this->itemChoices) }})</flux:select.option>
                        @foreach ($this->itemChoices as $choice)
                            <flux:select.option value="{{ $choice['line_item_id'] }}" wire:key="combine-only-{{ $choice['line_item_id'] }}">{{ $choice['label'] }} (#{{ $choice['line_item_id'] }})</flux:select.option>
                        @endforeach
                    </flux:select>
                @endif

                <flux:checkbox.group wire:model.live="selected" class="divide-y divide-zinc-100 rounded-lg border border-zinc-200 dark:divide-white/5 dark:border-white/10">
                    @foreach ($this->shown as $item)
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

                {{-- One Flux combobox: pick a section already on the estimate, or type a new one's name and create it in line (2026-11-14). --}}
                <div class="space-y-1" data-combine-into>
                    <flux:select wire:model.live="into" variant="combobox" label="Put the combined items in">
                        <x-slot name="input">
                            <flux:select.input wire:model="sectionName" placeholder="Pick a section, or type a new one…" maxlength="60" />
                        </x-slot>

                        @if ($into === 'new' && trim($sectionName) !== '')
                            {{-- selected-label: the box takes the name alone, never the description (which would become the section's name). --}}
                            <flux:select.option value="new" icon="plus" description="New section at the end of the estimate" :selected-label="trim($sectionName)" wire:key="combine-into-new">{{ trim($sectionName) }}</flux:select.option>
                        @endif
                        @foreach ($this->sections as $section)
                            <flux:select.option value="{{ $section['id'] }}" description="Section {{ $section['position'] }} on this estimate" :selected-label="$section['name']" wire:key="combine-into-{{ $section['id'] }}">{{ $section['name'] }}</flux:select.option>
                        @endforeach

                        <flux:select.option.create wire:click="newSection" min-length="1">
                            New section "<span wire:text="sectionName"></span>"
                        </flux:select.option.create>
                    </flux:select>
                    <flux:error name="sectionName" />
                    <flux:text size="sm">
                        {{ $into === 'new' ? 'A new section at the end of the estimate, or one already carrying this name.' : 'At the end of that section; a section the lines come from can take them too.' }}
                    </flux:text>
                </div>
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
