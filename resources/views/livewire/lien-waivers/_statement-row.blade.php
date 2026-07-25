<flux:table.row wire:key="sworn-statement-{{ $statement->id }}">
    @unless($isProjectScoped)
        <flux:table.cell>
            <div class="font-medium">{{ $statement->project?->project_name ?? '—' }}</div>
            <div class="text-xs text-zinc-500">{{ $statement->project?->address }}</div>
        </flux:table.cell>
    @endunless
    <flux:table.cell :class="$cell">
        <div class="flex items-center gap-1">
            <flux:tooltip :content="$this->contractorVendor?->business_name ?? ''" position="top">
                <span>{{ Str::limit($this->contractorVendor?->short_name ?? $this->contractorVendor?->business_name ?? '—', $nameLimit) }}</span>
            </flux:tooltip>

            <flux:dropdown>
                <button type="button" class="text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200" title="Statement actions">
                    <flux:icon.pencil-square variant="micro" />
                </button>

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
    <flux:table.cell :class="$cell">${{ number_format((float) $statement->this_payment, 2) }}</flux:table.cell>
    @unless($isProjectScoped)
        <flux:table.cell :class="$cell">{{ optional($statement->created_at)->format('m/d/y') }}</flux:table.cell>
    @endunless
    <flux:table.cell :class="$cell">
        <flux:tooltip content="General Contractor Sworn Statement" position="top">
            <flux:badge size="sm" color="indigo">GCSS</flux:badge>
        </flux:tooltip>
    </flux:table.cell>
    <flux:table.cell :class="$cell">
        @if($statement->status)
            <flux:tooltip :content="$statement->status->label()" position="top">
                <flux:badge size="sm" :color="$statement->status->color()">{{ $isProjectScoped ? $statement->status->name : $statement->status->label() }}</flux:badge>
            </flux:tooltip>
        @endif
    </flux:table.cell>
</flux:table.row>
