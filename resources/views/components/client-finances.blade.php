@props([
    'project',
    'title' => 'Project Finances',
    'showReimbursementDownload' => false,
    'reimbursementDownloadAction' => 'print_reimbursements',
    'reimbursementDownloadTooltip' => 'Download',
])

@php
    $finances = $project->finances;
@endphp

<flux:card class="space-y-2">
    <flux:heading size="lg" class="mb-0">{{ $title }}</flux:heading>
    <flux:separator variant="subtle" />
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
                        @if($showReimbursementDownload)
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
</flux:card>
