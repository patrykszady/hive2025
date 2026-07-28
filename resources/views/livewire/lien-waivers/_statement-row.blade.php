<flux:table.row wire:key="sworn-statement-{{ $statement->id }}">
    @unless($isProjectScoped)
        <flux:table.cell>
            <div class="font-medium">{{ $statement->project?->project_name ?? '—' }}</div>
            <div class="text-xs text-zinc-500">{{ $statement->project?->address }}</div>
        </flux:table.cell>
    @endunless
    {{-- Same shared link + action affordance as the waiver rows, so the GCSS
         row reads as part of the same table. --}}
    <flux:table.cell class="!px-2 whitespace-nowrap min-w-0">
        <div class="flex items-center gap-1 min-w-0">
            <x-table-link :label="$this->contractorVendor?->short_name ?? $this->contractorVendor?->business_name ?? '—'" />

            <flux:dropdown position="bottom" align="start">
                <flux:button variant="ghost" size="xs" icon="pencil-square" inset="top bottom" class="shrink-0" aria-label="Statement actions" />

                <flux:menu>
                    {{-- Project card has "Download all" on the draw header; only the
                         standalone page needs the package link here. --}}
                    @unless($isProjectScoped)
                        <flux:menu.item icon="arrow-down-tray" href="{{ route('sworn-statements.download-package', $statement) }}">
                            Download Draw Package
                        </flux:menu.item>
                    @endunless
                    <flux:menu.item icon="document-text" href="{{ route('sworn-statements.download', $statement) }}">
                        Download GCSS
                    </flux:menu.item>
                    {{-- On the project card, Delete lives under the draw
                         header's Download all dropdown instead. --}}
                    @unless($isProjectScoped)
                        <flux:menu.separator />
                        <flux:menu.item icon="trash" variant="danger" wire:click="confirmDeleteDraw({{ $statement->id }})">
                            Delete Draw
                        </flux:menu.item>
                    @endunless
                </flux:menu>
            </flux:dropdown>
        </div>
    </flux:table.cell>
    <flux:table.cell class="!px-2 whitespace-nowrap">${{ number_format((float) $statement->this_payment, 2) }}</flux:table.cell>
    @unless($isProjectScoped)
        <flux:table.cell>{{ optional($statement->created_at)->format('m/d/y') }}</flux:table.cell>
    @endunless
    {{-- GCSS is an abbreviation, so its tooltip earns its place. --}}
    <flux:table.cell class="!px-2 whitespace-nowrap">
        <flux:tooltip content="General Contractor Sworn Statement" position="top">
            <flux:badge size="sm" color="indigo" inset="top bottom">GCSS</flux:badge>
        </flux:tooltip>
    </flux:table.cell>
    <flux:table.cell class="!px-2 whitespace-nowrap">
        @if($statement->status)
            @php($statusText = $isProjectScoped ? $statement->status->name : $statement->status->label())
            @if($statusText !== $statement->status->label())
                <flux:tooltip :content="$statement->status->label()" position="top">
                    <flux:badge size="sm" :color="$statement->status->color()" inset="top bottom">{{ $statusText }}</flux:badge>
                </flux:tooltip>
            @else
                <flux:badge size="sm" :color="$statement->status->color()" inset="top bottom">{{ $statusText }}</flux:badge>
            @endif
        @endif
    </flux:table.cell>
</flux:table.row>
