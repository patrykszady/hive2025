@props([
    'project',
    'finances' => null,
    'title' => 'Project Finances',
    'showReimbursementDownload' => false,
    'reimbursementDownloadAction' => 'print_reimbursements',
    'reimbursementDownloadTooltip' => 'Download',
    // A URL instead of a Livewire action: used by the estimate PDF, where the
    // reader has no session and a wire:click means nothing. Renders a link.
    'reimbursementDownloadUrl' => null,
])

@php
    $finances = $finances ?? $project->finances;
@endphp

<x-island-card heading="{{ $title }}" :separator="true">
    <flux:table>
        <flux:table.rows>
            <flux:table.row>
                <flux:table.cell>Estimate</flux:table.cell>
                <flux:table.cell>{{ money($finances['estimate']) }}</flux:table.cell>
            </flux:table.row>
            <flux:table.row>
                <flux:table.cell>Change Orders</flux:table.cell>
                <flux:table.cell>{{ money($finances['change_orders']) }}</flux:table.cell>
            </flux:table.row>
            <flux:table.row>
                <flux:table.cell>
                    <div class="flex items-center justify-between gap-2">
                        <span>Reimbursements</span>
                        @if($reimbursementDownloadUrl && (float) ($finances['reimbursments'] ?? 0) > 0)
                            <flux:button
                                :href="$reimbursementDownloadUrl"
                                icon="arrow-down-on-square"
                                size="xs"
                                variant="ghost"
                                class="shrink-0 !p-0"
                            >Download</flux:button>
                        @elseif($showReimbursementDownload && (float) ($finances['reimbursments'] ?? 0) > 0)
                            <flux:button
                                icon="arrow-down-on-square"
                                size="xs"
                                variant="ghost"
                                class="shrink-0 !p-0"
                                wire:click="{{ $reimbursementDownloadAction }}"
                                tooltip="{{ $reimbursementDownloadTooltip }}"
                            />
                        @endif
                    </div>
                </flux:table.cell>
                <flux:table.cell>{{ money($finances['reimbursments']) }}</flux:table.cell>
            </flux:table.row>
            <flux:table.row>
                <flux:table.cell variant="strong">Total Project</flux:table.cell>
                <flux:table.cell variant="strong">{{ money($finances['total_project']) }}</flux:table.cell>
            </flux:table.row>
            <flux:table.row>
                <flux:table.cell>Payments</flux:table.cell>
                <flux:table.cell>{{ money($finances['payments']) }}</flux:table.cell>
            </flux:table.row>
            <flux:table.row>
                <flux:table.cell variant="strong">Balance Due</flux:table.cell>
                <flux:table.cell variant="strong">{{ money($finances['balance']) }}</flux:table.cell>
            </flux:table.row>
        </flux:table.rows>
    </flux:table>
</x-island-card>
