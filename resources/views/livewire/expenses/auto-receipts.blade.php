<div class="px-4 sm:px-6 lg:px-8 py-4 h-full min-h-0 flex flex-col overflow-hidden w-full max-w-full min-w-0">
    @php
        $receipt = $this->currentReceipt;
        $total = $this->total;
        $batchInfo = $this->batchInfo;
        $expense = $receipt?->expense;
        $expenseReceipts = $expense
            ? collect([$receipt])->merge(
                $expense->receipts
                    ->reject(fn ($r) => $r->id === $receipt?->id)
                    ->sortByDesc('id')
                    ->values()
            )->values()
            : collect();
    @endphp

    {{-- Top bar: title + navigation --}}
    <div class="mb-4 flex items-center justify-between gap-3 shrink-0 min-w-0 max-w-full overflow-x-auto overflow-y-hidden whitespace-nowrap">
        <div class="flex items-center gap-3 min-w-0">
            <flux:heading size="lg" class="mb-0">Recent Auto Receipts</flux:heading>
            @if($batchInfo)
                <flux:badge color="blue" size="sm">
                    Batch {{ $batchInfo['batch'] }} | {{ $batchInfo['positionInBatch'] }}/{{ $batchInfo['batchSize'] }}
                </flux:badge>
            @endif
        </div>

        <div class="flex items-center gap-2 shrink-0 flex-nowrap">
            @php
                $prevPos = max(1, $position - 1);
                $nextPos = $total > 0 ? min($total, $position + 1) : 1;
                $prevUrl = $prevPos === 1
                    ? route('expenses.auto-receipts')
                    : route('expenses.auto-receipts', ['p' => $prevPos]);
                $nextUrl = route('expenses.auto-receipts', ['p' => $nextPos]);
            @endphp

            <flux:button
                size="sm"
                icon="chevron-left"
                :href="$prevUrl"
                wire:navigate
                :disabled="$position <= 1"
            >
                Previous
            </flux:button>

            <flux:input
                type="number"
                min="1"
                :max="$total ?: 1"
                wire:model.lazy="position"
                wire:change="goTo($event.target.value)"
                size="sm"
                class="w-20"
            />

            <flux:button
                size="sm"
                icon-trailing="chevron-right"
                :href="$nextUrl"
                wire:navigate
                :disabled="$position >= $total"
            >
                Next
            </flux:button>

            <flux:button
                size="sm"
                variant="ghost"
                href="{{ route('expenses.index') }}"
                wire:navigate
            >
                Back to Expenses
            </flux:button>
        </div>
    </div>

    @if(! $receipt)
        <flux:card>
            <flux:heading size="md">No auto-fetched receipts found</flux:heading>
            <flux:text class="mt-2">
                Receipts scanned from your Epson scanner and emailed in will appear here as soon as
                <code>fetch-auto-receipts</code> processes the next batch.
            </flux:text>
        </flux:card>
    @else
        <div class="grid grid-cols-1 lg:grid-cols-5 gap-4 flex-1 min-h-0 min-w-0">
            {{-- LEFT: receipt viewer — full viewport height, file scrolls inside --}}
            <div class="lg:col-span-3 h-full min-h-0 min-w-0">
                <flux:card
                    wire:key="auto-receipt-card-{{ $receipt->id }}"
                    class="!p-0 overflow-hidden h-full flex flex-col"
                >
                    @php
                        $hasFile = (bool) $receipt->receipt_filename;
                        $ext = $hasFile ? strtolower(pathinfo($receipt->receipt_filename, PATHINFO_EXTENSION)) : null;
                        $src = $hasFile ? route('expenses.original_receipt', ['receipts', $receipt->receipt_filename]) : null;
                        $hasOcr = !empty($receipt->receipt_items);
                    @endphp

                    @if($expenseReceipts->count() > 1)
                        <flux:tab.group class="h-full flex flex-col">
                            <div class="border-b border-zinc-200 dark:border-zinc-700 px-4 pt-2 shrink-0">
                                <flux:tabs>
                                    @foreach($expenseReceipts as $expenseReceipt)
                                        <flux:tab :name="'expense-receipt-' . $expenseReceipt->id" class="group" wire:click="selectExpenseReceiptTab({{ $expenseReceipt->id }})">
                                            <span class="inline-flex items-center gap-2">
                                                <span>Receipt {{ $loop->iteration }}</span>
                                                @if(!empty($expenseReceipt->receipt_filename))
                                                    <a
                                                        href="{{ route('expenses.original_receipt', ['receipts', $expenseReceipt->receipt_filename]) }}"
                                                        target="_blank"
                                                        title="View Receipt"
                                                        aria-label="View Receipt"
                                                        class="hidden group-aria-selected:inline-flex items-center text-zinc-500 hover:text-zinc-700"
                                                    >
                                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="h-4 w-4">
                                                            <path d="M12 5c-7 0-10 7-10 7s3 7 10 7 10-7 10-7-3-7-10-7zm0 12a5 5 0 1 1 0-10 5 5 0 0 1 0 10z"/>
                                                            <circle cx="12" cy="12" r="2.5"/>
                                                        </svg>
                                                    </a>
                                                @endif
                                            </span>
                                        </flux:tab>
                                    @endforeach
                                </flux:tabs>
                            </div>

                            @foreach($expenseReceipts as $expenseReceipt)
                                <flux:tab.panel :name="'expense-receipt-' . $expenseReceipt->id" class="flex-1 min-h-0 !p-0 flex flex-col">
                                    @php
                                        $panelHasFile = (bool) $expenseReceipt->receipt_filename;
                                        $panelExt = $panelHasFile ? strtolower(pathinfo($expenseReceipt->receipt_filename, PATHINFO_EXTENSION)) : null;
                                        $panelSrc = $panelHasFile ? route('expenses.original_receipt', ['receipts', $expenseReceipt->receipt_filename]) : null;
                                        $panelHasOcr = !empty($expenseReceipt->receipt_items);
                                    @endphp

                                    <flux:tab.group class="h-full flex flex-col" wire:model.live="activeTab">
                                        <div class="border-b border-zinc-200 dark:border-zinc-700 px-4 pt-2 shrink-0">
                                            <flux:tabs class="border-b-0">
                                                <flux:tab :name="'receipt-' . $expenseReceipt->id">Receipt</flux:tab>
                                                <flux:tab :name="'ocr-' . $expenseReceipt->id" :disabled="! $panelHasOcr">Processed Receipt</flux:tab>
                                            </flux:tabs>
                                        </div>

                                        <flux:tab.panel :name="'receipt-' . $expenseReceipt->id" class="flex-1 min-h-0 !p-0 flex flex-col">
                                            @if($panelHasFile)
                                                @if($panelExt === 'pdf')
                                                    <iframe
                                                        src="{{ $panelSrc }}#toolbar=0&navpanes=0&view=Fit"
                                                        class="w-full flex-1 min-h-0 bg-zinc-50 dark:bg-zinc-900"
                                                        title="Receipt {{ $expenseReceipt->id }}"
                                                    ></iframe>
                                                @else
                                                    <div class="flex-1 min-h-0 bg-zinc-50 dark:bg-zinc-900 flex justify-center items-start overflow-auto">
                                                        <img
                                                            src="{{ $panelSrc }}"
                                                            alt="Receipt {{ $expenseReceipt->id }}"
                                                            class="w-full h-auto object-contain"
                                                        />
                                                    </div>
                                                @endif
                                            @else
                                                <div class="p-6 text-sm text-zinc-500">No receipt file on disk.</div>
                                            @endif
                                        </flux:tab.panel>

                                        <flux:tab.panel :name="'ocr-' . $expenseReceipt->id" class="flex-1 min-h-0 overflow-y-auto bg-zinc-50 dark:bg-zinc-900">
                                            @if($panelHasOcr)
                                                <div class="mx-auto my-4 max-w-md bg-white dark:bg-zinc-800 rounded-lg shadow-sm ring-1 ring-zinc-200 dark:ring-zinc-700 p-4">
                                                    <x-expenses.receipt
                                                        :receipt="$expenseReceipt"
                                                        :selectedSplit="null"
                                                        :expenseMismatch="false"
                                                        :expenseAmount="(float) ($expense?->amount ?? 0)"
                                                        :compactNotes="false"
                                                        :showNotes="true"
                                                    />
                                                </div>
                                            @else
                                                <div class="p-6 text-sm text-zinc-500">No OCR / parsed details available for this receipt.</div>
                                            @endif
                                        </flux:tab.panel>
                                    </flux:tab.group>
                                </flux:tab.panel>
                            @endforeach
                        </flux:tab.group>
                    @else
                        <flux:tab.group class="h-full flex flex-col" wire:model.live="activeTab">
                            <div class="border-b border-zinc-200 dark:border-zinc-700 px-4 pt-2 shrink-0">
                                <flux:tabs class="border-b-0">
                                    <flux:tab :name="'receipt-' . $receipt->id">Receipt</flux:tab>
                                    <flux:tab :name="'ocr-' . $receipt->id" :disabled="! $hasOcr">Processed Receipt</flux:tab>
                                </flux:tabs>
                            </div>

                            <flux:tab.panel :name="'receipt-' . $receipt->id" class="flex-1 min-h-0 !p-0 flex flex-col">
                                @if($hasFile)
                                    @if($ext === 'pdf')
                                        <iframe
                                            src="{{ $src }}#toolbar=0&navpanes=0&view=Fit"
                                            class="w-full flex-1 min-h-0 bg-zinc-50 dark:bg-zinc-900"
                                            title="Receipt {{ $receipt->id }}"
                                        ></iframe>
                                    @else
                                        <div class="flex-1 min-h-0 bg-zinc-50 dark:bg-zinc-900 flex justify-center items-start overflow-auto">
                                            <img
                                                src="{{ $src }}"
                                                alt="Receipt {{ $receipt->id }}"
                                                class="w-full h-auto object-contain"
                                            />
                                        </div>
                                    @endif
                                @else
                                    <div class="p-6 text-sm text-zinc-500">No receipt file on disk.</div>
                                @endif
                            </flux:tab.panel>

                            <flux:tab.panel :name="'ocr-' . $receipt->id" class="flex-1 min-h-0 overflow-y-auto bg-zinc-50 dark:bg-zinc-900">
                                @if($hasOcr)
                                    <div class="mx-auto my-4 max-w-md bg-white dark:bg-zinc-800 rounded-lg shadow-sm ring-1 ring-zinc-200 dark:ring-zinc-700 p-4">
                                        <x-expenses.receipt
                                            :receipt="$receipt"
                                            :selectedSplit="null"
                                            :expenseMismatch="false"
                                            :expenseAmount="(float) ($expense?->amount ?? 0)"
                                            :compactNotes="false"
                                            :showNotes="true"
                                        />
                                    </div>
                                @else
                                    <div class="p-6 text-sm text-zinc-500">No OCR / parsed details available for this receipt.</div>
                                @endif
                            </flux:tab.panel>
                        </flux:tab.group>
                    @endif
                </flux:card>
            </div>

            {{-- RIGHT: inline editable expense form --}}
            <div class="lg:col-span-2 h-full min-h-0 min-w-0 max-w-full overflow-y-auto overflow-x-hidden pr-1 space-y-4">
                @if($expense)
                    <div class="h-full min-h-0 min-w-0 max-w-full overflow-x-hidden">
                        @can('update', $expense)
                            @livewire('expenses.expense-create', ['expenseId' => $expense->id, 'embedded' => true], key('auto-receipt-ec-'.$receipt->id))
                        @else
                            <flux:card>
                                <flux:text>You don't have permission to edit this expense.</flux:text>
                            </flux:card>
                        @endcan
                    </div>
                @endif
            </div>
        </div>
    @endif
</div>
