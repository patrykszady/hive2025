@php($cell = $isProjectScoped ? '!px-2 whitespace-nowrap' : '')
@php($nameLimit = $isProjectScoped ? 14 : 20)

@if($isProjectScoped)
    @php($groups = $this->drawGroups)
    @if($groups['draws']->isNotEmpty() || $groups['other']->isNotEmpty())
        <div class="space-y-2">
            {{-- One collapsible section per draw: the GCSS statement IS the header; its waivers table under it --}}
            @foreach($groups['draws'] as $group)
                @php($statement = $group['statement'])
                <div wire:key="draw-{{ $statement->id }}" x-data="{ open: @js($loop->first) }">
                    <div class="flex items-center justify-between gap-2 rounded-lg bg-zinc-50 dark:bg-zinc-800/60 !py-2 !px-2 cursor-pointer select-none" role="button" @click="open = !open">
                        <div class="flex items-center gap-2 min-w-0">
                            <flux:icon.chevron-down variant="mini" class="text-zinc-400 transition-transform duration-200" ::class="open && 'rotate-180'" />
                            <span class="text-sm font-semibold text-zinc-800 dark:text-zinc-100">Draw {{ $groups['draws']->count() - $loop->index }}</span>
                            <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ optional($statement->created_at)->format('m/d/y') }} &middot; ${{ number_format((float) $statement->this_payment, 2) }}</span>
                        </div>
                        {{-- .stop: the buttons act without toggling the accordion --}}
                        <div class="flex items-center gap-1 shrink-0" @click.stop>
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
                    </div>
                    <div x-show="open" x-collapse @unless($loop->first) x-cloak @endunless>
                        <flux:table>
                            <flux:table.columns>
                                <flux:table.column :class="$cell">Vendor</flux:table.column>
                                <flux:table.column :class="$cell">Amount</flux:table.column>
                                <flux:table.column :class="$cell">Type</flux:table.column>
                                <flux:table.column :class="$cell">Status</flux:table.column>
                            </flux:table.columns>
                            <flux:table.rows>
                                @include('livewire.lien-waivers._statement-row', ['statement' => $statement])
                                @foreach($group['waivers'] as $waiver)
                                    @include('livewire.lien-waivers._waiver-row', ['waiver' => $waiver])
                                @endforeach
                            </flux:table.rows>
                        </flux:table>
                    </div>
                </div>
            @endforeach

            @if($groups['other']->isNotEmpty())
                <div wire:key="draw-other" x-data="{ open: @js($groups['draws']->isEmpty()) }">
                    <div class="flex items-center gap-2 rounded-lg bg-zinc-50 dark:bg-zinc-800/60 !py-2 !px-2 cursor-pointer select-none" role="button" @click="open = !open">
                        <flux:icon.chevron-down variant="mini" class="text-zinc-400 transition-transform duration-200" ::class="open && 'rotate-180'" />
                        <span class="text-sm font-semibold text-zinc-800 dark:text-zinc-100">Other waivers</span>
                    </div>
                    <div x-show="open" x-collapse @unless($groups['draws']->isEmpty()) x-cloak @endunless>
                        <flux:table>
                            <flux:table.columns>
                                <flux:table.column :class="$cell">Vendor</flux:table.column>
                                <flux:table.column :class="$cell">Amount</flux:table.column>
                                <flux:table.column :class="$cell">Type</flux:table.column>
                                <flux:table.column :class="$cell">Status</flux:table.column>
                            </flux:table.columns>
                            <flux:table.rows>
                                @foreach($groups['other'] as $waiver)
                                    @include('livewire.lien-waivers._waiver-row', ['waiver' => $waiver])
                                @endforeach
                            </flux:table.rows>
                        </flux:table>
                    </div>
                </div>
            @endif
        </div>
    @endif
@else
    @if($this->waivers->isNotEmpty() || $this->swornStatements->isNotEmpty())
        <flux:table>
            <flux:table.columns>
                <flux:table.column>Project</flux:table.column>
                <flux:table.column>Vendor</flux:table.column>
                <flux:table.column>Amount</flux:table.column>
                <flux:table.column>Through</flux:table.column>
                <flux:table.column>Type</flux:table.column>
                <flux:table.column>Status</flux:table.column>
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
