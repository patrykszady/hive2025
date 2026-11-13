{{-- The drafted lines, in the same card and columns as a section on the
     estimate, grouped under the section each landed in. While a draft runs
     the rows are re-read every second; afterwards the same table shows them
     editable. Rows stay one flat list ($generatedItems), so each quantity
     box binds to its own row whatever section it is in. --}}
@php
    $drafting = ($mode ?? 'review') === 'drafting';
    $currentSection = null;
    $position = 0;
    $sectionTotals = [];
    foreach ($items as $item) {
        $sectionTotals[$item['section_id'] ?? 0] = ($sectionTotals[$item['section_id'] ?? 0] ?? 0) + (float) ($item['total'] ?? 0);
    }
@endphp
<flux:card class="!p-0 overflow-hidden">
    <flux:table>
        <flux:table.columns>
            <flux:table.column class="w-10 !pl-6"></flux:table.column>
            <flux:table.column class="w-1/3">Item</flux:table.column>
            <flux:table.column>Quantity</flux:table.column>
            <flux:table.column>Unit</flux:table.column>
            <flux:table.column>Cost</flux:table.column>
            <flux:table.column>Total</flux:table.column>
            <flux:table.column class="w-12 !pr-6"></flux:table.column>
        </flux:table.columns>

        <flux:table.rows :class="$drafting ? 'draft-rows' : ''">
            @foreach($items as $index => $item)
                @if(($item['section_id'] ?? 0) !== $currentSection)
                    @php
                        $currentSection = $item['section_id'] ?? 0;
                        $position = 0;
                    @endphp
                    <flux:table.row wire:key="draft-section-{{ $currentSection }}-at-{{ $index }}" class="bg-zinc-50 dark:bg-zinc-800/50">
                        <flux:table.cell colspan="5" class="!pl-6 !whitespace-normal">
                            <span class="font-semibold text-zinc-800 dark:text-white">{{ $item['section_name'] ?? 'Section' }}</span>
                            @if(in_array($currentSection, $newSections ?? [], true))
                                <flux:badge size="sm" color="indigo" class="ml-2">New section</flux:badge>
                            @endif
                            @if(!empty($sectionScopes[$currentSection]))
                                <div class="mt-0.5 text-xs italic font-normal text-zinc-500 dark:text-zinc-400 !whitespace-normal">{{ $sectionScopes[$currentSection] }}</div>
                            @endif
                            {{-- The note typed for this section alone: what went to Claude for it (2026-11-13). --}}
                            @if(!empty($sectionNotes[$currentSection]))
                                <div class="mt-0.5 text-xs font-normal text-zinc-600 dark:text-zinc-300 !whitespace-normal" data-section-note><span class="font-medium">Note to Claude:</span> {{ $sectionNotes[$currentSection] }}</div>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell variant="strong" :x-text="$drafting ? null : 'money(sectionTotal('.$currentSection.'))'">{{ money($sectionTotals[$currentSection] ?? 0) }}</flux:table.cell>
                        <flux:table.cell class="!pr-6">
                            @unless($drafting)
                                <div class="flex items-center justify-end gap-1">
                                    <flux:button size="xs" variant="ghost" icon="arrow-path" wire:click="startRedo({{ (int) $currentSection }})" aria-label="Draft {{ $item['section_name'] ?? 'this section' }} again" tooltip="Draft this section again" />
                                    <flux:button size="xs" variant="ghost" icon="trash" wire:click="removeSection({{ (int) $currentSection }})" wire:confirm="Remove everything drafted into {{ $item['section_name'] ?? 'this section' }}?" aria-label="Remove everything drafted into {{ $item['section_name'] ?? 'this section' }}" tooltip="Remove this section's draft" />
                                </div>
                            @endunless
                        </flux:table.cell>
                    </flux:table.row>
                    @if(! $drafting && ($redoSectionId ?? null) === $currentSection)
                        <flux:table.row wire:key="draft-section-redo-{{ $currentSection }}" class="bg-indigo-50/60 dark:bg-indigo-900/10">
                            <flux:table.cell colspan="7" class="!pl-6 !pr-6 !whitespace-normal">
                                <div class="flex items-end gap-2" data-redo-section>
                                    <div class="min-w-0 flex-1">
                                        <flux:input wire:model="redoNote" x-on:keydown.enter.prevent="$el.blur()" size="sm" maxlength="300" label="Draft {{ $item['section_name'] ?? 'this section' }} again — what should be different?" placeholder="e.g. full new kitchen with an island and a pantry wall" autofocus />
                                    </div>
                                    <flux:button size="sm" variant="primary" icon="sparkles" class="whitespace-nowrap" wire:click="redoSection" wire:loading.attr="disabled" wire:target="redoSection">Draft it again</flux:button>
                                    <flux:button size="sm" variant="ghost" wire:click="cancelRedo">Cancel</flux:button>
                                </div>
                                <flux:text size="sm" class="mt-1">Its drafted lines are replaced; the other sections stay as they are. About half a minute.</flux:text>
                                <flux:error name="redoNote" />
                            </flux:table.cell>
                        </flux:table.row>
                    @endif
                @endif
                @php
                    $position++;
                @endphp
                @include('livewire.estimates.partials.ai-draft-row', ['item' => $item, 'index' => $index, 'number' => $position, 'editable' => ! $drafting])
            @endforeach
        </flux:table.rows>

        <flux:table.rows>
            <flux:table.row class="bg-zinc-50 dark:bg-zinc-800/50">
                <flux:table.cell colspan="5" class="!pl-6 text-right font-semibold">Total:</flux:table.cell>
                <flux:table.cell variant="strong" class="text-lg" :x-text="$drafting ? null : 'money(total())'">{{ money($total ?? 0) }}</flux:table.cell>
                <flux:table.cell class="!pr-6"></flux:table.cell>
            </flux:table.row>
        </flux:table.rows>
    </flux:table>
</flux:card>
