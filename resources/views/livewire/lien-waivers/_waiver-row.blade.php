<flux:table.row wire:key="waiver-{{ $waiver->id }}">
    @unless($isProjectScoped)
        <flux:table.cell>
            <div class="font-medium">{{ $waiver->project?->project_name ?? '—' }}</div>
            <div class="text-xs text-zinc-500">{{ $waiver->project?->address }}</div>
        </flux:table.cell>
    @endunless
    {{-- x-table-link is the shared row-link: it truncates to the column and
         only shows a tooltip when the name is actually clipped. --}}
    <flux:table.cell class="!px-2 whitespace-nowrap min-w-0">
        <div class="flex items-center gap-1 min-w-0">
            @if($waiver->vendor)
                <x-table-link
                    :href="route('vendors.show', $waiver->vendor->id)"
                    :label="$waiver->vendor->short_name ?? $waiver->vendor->business_name"
                />
            @else
                —
            @endif

            <flux:dropdown position="bottom" align="start">
                <flux:button variant="ghost" size="xs" icon="pencil-square" inset="top bottom" class="shrink-0" aria-label="Waiver actions" />

                <flux:menu>
                    <flux:menu.item icon="eye" href="{{ route('lien-waivers.show', $waiver) }}" wire:navigate.hover>
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
    <flux:table.cell class="!px-2 whitespace-nowrap">${{ number_format((float) $waiver->amount, 2) }}</flux:table.cell>
    @unless($isProjectScoped)
        <flux:table.cell>{{ optional($waiver->through_date)->format('m/d/y') }}</flux:table.cell>
    @endunless
    {{-- Tooltips stay here: LWTD/FLW are abbreviations, so the tooltip adds
         information rather than repeating the visible text. --}}
    <flux:table.cell class="!px-2 whitespace-nowrap">
        @if($waiver->type?->isFinal())
            <flux:tooltip content="Final Lien Waiver" position="top">
                <flux:badge size="sm" color="purple" inset="top bottom">FLW</flux:badge>
            </flux:tooltip>
        @else
            <flux:tooltip content="Lien Waiver to Date" position="top">
                <flux:badge size="sm" color="zinc" inset="top bottom">LWTD</flux:badge>
            </flux:tooltip>
        @endif
    </flux:table.cell>
    <flux:table.cell class="!px-2 whitespace-nowrap">
        @if($isProjectScoped && $waiver->status === \App\Enums\LienWaiverStatus::Sent && $this->openedWaiverIds->contains($waiver->id))
            <flux:tooltip content="Sent — the email has been opened" position="top">
                <flux:badge size="sm" color="indigo" inset="top bottom">Opened</flux:badge>
            </flux:tooltip>
        @elseif($waiver->status)
            @php($statusText = $isProjectScoped ? $waiver->status->name : $waiver->status->label())
            {{-- No tooltip when it would just repeat the badge (Draft, Signed,
                 Cancelled); "Sent" keeps one because the label says more. --}}
            @if($statusText !== $waiver->status->label())
                <flux:tooltip :content="$waiver->status->label()" position="top">
                    <flux:badge size="sm" :color="$waiver->status->color()" inset="top bottom">{{ $statusText }}</flux:badge>
                </flux:tooltip>
            @else
                <flux:badge size="sm" :color="$waiver->status->color()" inset="top bottom">{{ $statusText }}</flux:badge>
            @endif
        @endif
    </flux:table.cell>
</flux:table.row>
