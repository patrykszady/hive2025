@php
    // Widths (and the header labels) come from the component, so the header,
    // the cells and the skeleton can't drift — same contract as Email Tracking.
    $columns = \App\Livewire\LienWaivers\Index::columnDefs(scoped: true);
@endphp

@if($isProjectScoped)
    @php($groups = $this->drawGroups)
    @if($groups['draws']->isNotEmpty() || $groups['other']->isNotEmpty())
        <div class="space-y-2">
            {{-- One collapsible section per draw: the GCSS statement IS the header; its waivers table under it --}}
            @foreach($groups['draws'] as $group)
                @php($statement = $group['statement'])
                {{-- Each draw starts collapsed: the card shows the list of draws,
                     and you open the one whose waivers you want. --}}
                <div wire:key="draw-{{ $statement->id }}" x-data="{ open: false }">
                    {{-- Group header: same shape as the app's other grouped rows
                         (components/schedule/day-group) — heading + meta badges +
                         chevron, no filled pill. --}}
                    <div class="flex items-center justify-between gap-2 py-1 cursor-pointer select-none" role="button" @click="open = !open">
                        <div class="flex items-center gap-2 min-w-0">
                            <flux:heading size="sm" class="mb-0 text-zinc-700 dark:text-zinc-300">Draw {{ $groups['draws']->count() - $loop->index }}</flux:heading>
                            <flux:badge color="zinc" size="sm" inset="top bottom">{{ optional($statement->created_at)->format('m/d/y') }}</flux:badge>
                            <flux:badge color="zinc" size="sm" inset="top bottom">${{ number_format((float) $statement->this_payment, 2) }}</flux:badge>
                        </div>
                        <div class="flex items-center gap-1 shrink-0">
                            {{-- Actions belong to the open draw: collapsed, the row is
                                 just a label. .stop so the buttons act without
                                 toggling the accordion. --}}
                            <div class="flex items-center gap-1" x-show="open" x-cloak @click.stop>
                            <flux:button.group>
                                <flux:button size="xs" variant="ghost" icon="arrow-down-tray" href="{{ route('sworn-statements.download-package', $statement) }}">
                                    Download all
                                </flux:button>
                                <flux:dropdown position="bottom" align="end">
                                    <flux:button size="xs" variant="ghost" icon-trailing="chevron-down"></flux:button>

                                    <flux:menu>
                                        <flux:menu.item icon="trash" variant="danger" wire:click="confirmDeleteDraw({{ $statement->id }})">
                                            Delete
                                        </flux:menu.item>
                                    </flux:menu>
                                </flux:dropdown>
                            </flux:button.group>
                            </div>
                            <flux:icon.chevron-up class="size-4 text-zinc-400 transition-transform" x-bind:class="open ? '' : 'rotate-180'" />
                        </div>
                    </div>
                    <div x-show="open" x-collapse x-cloak>
                        @include('livewire.lien-waivers._waivers-table', [
                            'columns' => $columns,
                            'statement' => $statement,
                            'waivers' => $group['waivers'],
                        ])
                    </div>
                </div>
            @endforeach

            @if($groups['other']->isNotEmpty())
                <div wire:key="draw-other" x-data="{ open: @js($groups['draws']->isEmpty()) }">
                    <div class="flex items-center justify-between gap-2 py-1 cursor-pointer select-none" role="button" @click="open = !open">
                        <flux:heading size="sm" class="mb-0 text-zinc-700 dark:text-zinc-300">Other waivers</flux:heading>
                        <flux:icon.chevron-up class="size-4 text-zinc-400 transition-transform" x-bind:class="open ? '' : 'rotate-180'" />
                    </div>
                    <div x-show="open" x-collapse @unless($groups['draws']->isEmpty()) x-cloak @endunless>
                        @include('livewire.lien-waivers._waivers-table', [
                            'columns' => $columns,
                            'waivers' => $groups['other'],
                        ])
                    </div>
                </div>
            @endif
        </div>
    @endif
@else
    @if($this->waivers->isNotEmpty() || $this->swornStatements->isNotEmpty())
        <flux:table class="index-table [:where(&)]:p-0 [:where(&)]:space-y-0">
            <flux:table.columns>
                @foreach(\App\Livewire\LienWaivers\Index::columnDefs() as $column)
                    <flux:table.column class="{{ $column['width'] }}">{{ $column['label'] }}</flux:table.column>
                @endforeach
            </flux:table.columns>
            <flux:table.rows>
                @foreach($this->swornStatements as $statement)
                    @include('livewire.lien-waivers._statement-row', ['statement' => $statement])
                @endforeach
                @foreach($this->waivers as $waiver)
                    @include('livewire.lien-waivers._waiver-row', ['waiver' => $waiver])
                @endforeach
            </flux:table.rows>
        </flux:table>

        <div class="mt-4">
            {{ $this->waivers->links() }}
        </div>
    @endif
@endif
