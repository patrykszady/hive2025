@if($this->waivers->isEmpty())
    <div class="py-10 text-center text-zinc-500 dark:text-zinc-400">
        @if($isProjectScoped)
            No lien waivers yet. They are generated automatically whenever a payment is recorded — or use
            <button type="button" wire:click="openCreate" class="text-blue-600 underline">Create Waiver</button>
            to issue one to the project client (e.g. for an upcoming payment).
        @else
            No lien waivers yet. They are generated automatically whenever a payment is recorded.
        @endif
    </div>
@else
    <flux:table>
        <flux:table.columns>
            @unless($isProjectScoped)
                <flux:table.column>Project</flux:table.column>
            @endunless
            <flux:table.column>Vendor</flux:table.column>
            <flux:table.column>Type</flux:table.column>
            <flux:table.column>Amount</flux:table.column>
            <flux:table.column>Through</flux:table.column>
            <flux:table.column>Status</flux:table.column>
            <flux:table.column>&nbsp;</flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @foreach($this->waivers as $waiver)
                <flux:table.row wire:key="waiver-{{ $waiver->id }}">
                    @unless($isProjectScoped)
                        <flux:table.cell>
                            <div class="font-medium">{{ $waiver->project?->project_name ?? '—' }}</div>
                            <div class="text-xs text-zinc-500">{{ $waiver->project?->address }}</div>
                        </flux:table.cell>
                    @endunless
                    <flux:table.cell>{{ $waiver->vendor?->business_name ?? '—' }}</flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm" color="zinc">{{ $waiver->type?->shortLabel() }}</flux:badge>
                    </flux:table.cell>
                    <flux:table.cell>@if($waiver->type === \App\Enums\LienWaiverType::UnconditionalFinal)<span class="font-semibold">PAID IN FULL</span>@else${{ number_format((float) $waiver->amount, 2) }}@endif</flux:table.cell>
                    <flux:table.cell>{{ optional($waiver->through_date)->format('M j, Y') }}</flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm" :color="$waiver->status?->color() ?? 'zinc'">
                            {{ $waiver->status?->label() }}
                        </flux:badge>
                    </flux:table.cell>
                    <flux:table.cell>
                        <flux:dropdown>
                            <flux:button size="xs" icon-trailing="chevron-down">Actions</flux:button>
                            <flux:menu>
                                <flux:menu.item icon="eye" href="{{ route('lien-waivers.show', $waiver) }}" wire:navigate>
                                    View
                                </flux:menu.item>
                                <flux:menu.item icon="arrow-down-tray" href="{{ route('lien-waivers.download', $waiver) }}">
                                    Download PDF
                                </flux:menu.item>
                                @if(! $waiver->isSigned() && $waiver->status !== \App\Enums\LienWaiverStatus::Cancelled)
                                    <flux:menu.item icon="paper-airplane" wire:click="sendForSignature({{ $waiver->id }})">
                                        Send for signature
                                    </flux:menu.item>
                                    <flux:menu.separator />
                                    <flux:menu.item icon="trash" variant="danger" wire:click="delete({{ $waiver->id }})"
                                        wire:confirm="Delete this lien waiver?">
                                        Delete
                                    </flux:menu.item>
                                @endif
                            </flux:menu>
                        </flux:dropdown>
                    </flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>

    <div class="mt-4">
        {{ $this->waivers->links() }}
    </div>
@endif
