@php
    // Widths (and the header labels) come from the component, so the header,
    // the cells and the skeleton can't drift — same contract as Email Tracking.
    $columns = \App\Livewire\LienWaivers\Index::columnDefs(scoped: true);
@endphp

@if($isProjectScoped)
    @php($groups = $this->drawGroups)
    @if($groups['draws']->isNotEmpty() || $groups['other']->isNotEmpty())
        <div class="space-y-2">
            {{-- One collapsible group per draw: the GCSS statement IS the
                 header; its waivers table under it. --}}
            @foreach($groups['draws'] as $group)
                @php($statement = $group['statement'])
                {{-- Each draw starts collapsed: the card lists the draws, and
                     you open the one whose waivers you want. --}}
                <x-card-group heading="Draw {{ $groups['draws']->count() - $loop->index }}" wire:key="draw-{{ $statement->id }}">
                    <x-slot:badge>
                        <flux:badge color="zinc" size="sm" inset="top bottom">{{ optional($statement->created_at)->format('m/d/y') }}</flux:badge>
                        <flux:badge color="zinc" size="sm" inset="top bottom">${{ number_format((float) $statement->this_payment, 2) }}</flux:badge>
                    </x-slot:badge>

                    <x-slot:actions>
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
                    </x-slot:actions>

                    @include('livewire.lien-waivers._waivers-table', [
                        'columns' => $columns,
                        'statement' => $statement,
                        'waivers' => $group['waivers'],
                    ])
                </x-card-group>
            @endforeach

            @if($groups['other']->isNotEmpty())
                {{-- Open only when there are no draws to lead with. --}}
                <x-card-group heading="Other waivers" :open="$groups['draws']->isEmpty()" wire:key="draw-other">
                    @include('livewire.lien-waivers._waivers-table', [
                        'columns' => $columns,
                        'waivers' => $groups['other'],
                    ])
                </x-card-group>
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
