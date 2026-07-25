<flux:table.row wire:key="waiver-{{ $waiver->id }}">
    @unless($isProjectScoped)
        <flux:table.cell>
            <div class="font-medium">{{ $waiver->project?->project_name ?? '—' }}</div>
            <div class="text-xs text-zinc-500">{{ $waiver->project?->address }}</div>
        </flux:table.cell>
    @endunless
    <flux:table.cell :class="$cell">
        <div class="flex items-center gap-1">
            @if($waiver->vendor)
                <flux:tooltip :content="$waiver->vendor->business_name" position="top">
                    <a href="{{ route('vendors.show', $waiver->vendor->id) }}" target="_blank">{{ Str::limit($waiver->vendor->short_name ?? $waiver->vendor->business_name, $nameLimit) }}</a>
                </flux:tooltip>
            @else
                —
            @endif

            <flux:dropdown>
                <button type="button" class="text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200" title="Waiver actions">
                    <flux:icon.pencil-square variant="micro" />
                </button>

                <flux:menu>
                    <flux:menu.item icon="eye" href="{{ route('lien-waivers.show', $waiver) }}" wire:navigate>
                        View
                    </flux:menu.item>
                    <flux:menu.item icon="arrow-down-tray" href="{{ route('lien-waivers.download', $waiver) }}">
                        Download PDF
                    </flux:menu.item>
                    {{-- No per-waiver Delete: waivers are removed with their
                         draw (the Delete under the draw's Download all menu). --}}
                    @if(! $waiver->isSigned() && $waiver->status !== \App\Enums\LienWaiverStatus::Cancelled)
                        <flux:menu.item icon="paper-airplane" wire:click="sendForSignature({{ $waiver->id }})">
                            Resend request
                        </flux:menu.item>
                    @endif
                </flux:menu>
            </flux:dropdown>
        </div>
    </flux:table.cell>
    <flux:table.cell :class="$cell">${{ number_format((float) $waiver->amount, 2) }}</flux:table.cell>
    @unless($isProjectScoped)
        <flux:table.cell :class="$cell">{{ optional($waiver->through_date)->format('m/d/y') }}</flux:table.cell>
    @endunless
    <flux:table.cell :class="$cell">
        @if($waiver->type?->isFinal())
            <flux:tooltip content="Final Lien Waiver" position="top">
                <flux:badge size="sm" color="purple">FLW</flux:badge>
            </flux:tooltip>
        @else
            <flux:tooltip content="Lien Waiver to Date" position="top">
                <flux:badge size="sm" color="zinc">LWTD</flux:badge>
            </flux:tooltip>
        @endif
    </flux:table.cell>
    <flux:table.cell :class="$cell">
        @if($isProjectScoped && $waiver->status === \App\Enums\LienWaiverStatus::Sent && $this->openedWaiverIds->contains($waiver->id))
            <flux:tooltip content="Sent — the email has been opened" position="top">
                <flux:badge size="sm" color="indigo">Opened</flux:badge>
            </flux:tooltip>
        @elseif($waiver->status)
            <flux:tooltip :content="$waiver->status->label()" position="top">
                <flux:badge size="sm" :color="$waiver->status->color()">{{ $isProjectScoped ? $waiver->status->name : $waiver->status->label() }}</flux:badge>
            </flux:tooltip>
        @endif
    </flux:table.cell>
</flux:table.row>
