<div class="max-w-3xl space-y-2" wire:transition>
    @if($view === NULL)
        {{-- Mobile: accordion collapsed by default --}}
        <flux:card class="!px-5 !py-2 sm:hidden">
            <flux:accordion transition>
                <flux:accordion.item>
                    <div class="flex items-center">
                        <div class="flex-1 min-w-0">
                            <flux:accordion.heading>
                                <flux:heading size="lg">Filters</flux:heading>
                            </flux:accordion.heading>
                        </div>
                        <flux:button size="sm" variant="ghost" icon="scan-barcode" x-on:click.stop="$flux.modal('barcode-scanner').show()" title="Scan barcode" class="shrink-0 text-zinc-400" />
                        <flux:button size="sm" variant="ghost" icon="arrow-up-tray" wire:click="$dispatchTo('expenses.expense-create', 'openUploadReceipt')" title="Upload receipt" class="shrink-0 text-zinc-400" />
                    </div>
                    <flux:accordion.content>
                        @include('livewire.expenses.partials.filter-fields', ['layout' => 'stacked'])
                    </flux:accordion.content>
                </flux:accordion.item>
            </flux:accordion>
        </flux:card>

        {{-- Desktop: always expanded --}}
        <x-island-card heading="Filters" :separator="true" class="hidden sm:block">
            <x-slot:actions>
                @can('create', App\Models\Expense::class)
                    @if($view == NULL)
                        <flux:button size="sm" icon="arrow-up-tray" wire:click="$dispatchTo('expenses.expense-create', 'openUploadReceipt')">Upload Receipt</flux:button>
                    @endif
                    @if($amount && $view == NULL)
                        <flux:button wire:click="$dispatchTo('expenses.expense-create', 'newExpense', { amount: {{$amount}}})">Add New Expense</flux:button>
                    @endif
                @endcan
            </x-slot:actions>

            @include('livewire.expenses.partials.filter-fields', ['layout' => 'inline'])
        </x-island-card>
    @endif

    {{-- Hide expenses card on project page when there are no expenses --}}
    @if($view !== 'projects.show' || $this->expenses->isNotEmpty())
    <x-island-card :heading="!in_array($view, ['projects.show', 'vendors.show']) ? 'Expenses' : null" class="overflow-hidden" x-data="{
        filtersOpen: false,
        bulkMode: false,
        refreshAfterDeleteTimer: null,
        exitBulkMode() {
            this.bulkMode = false;
            $wire.clearSelection();
        },
        refreshAfterDelete() {
            clearTimeout(this.refreshAfterDeleteTimer);
            this.refreshAfterDeleteTimer = setTimeout(() => {
                $wire.$refresh();
            }, 350);
        },
        init() {
            window.addEventListener('remove-expense-row', (event) => {
                const expenseId = event.detail.id;
                // Remove main expense row
                const row = document.querySelector(`[data-expense-row='${expenseId}']`);
                if (row) {
                    row.style.transition = 'opacity 0.3s ease-out';
                    row.style.opacity = '0';
                    setTimeout(() => row.remove(), 300);
                }
                // Remove any split rows for this expense
                document.querySelectorAll(`[data-expense-split-parent='${expenseId}']`).forEach(splitRow => {
                    splitRow.style.transition = 'opacity 0.3s ease-out';
                    splitRow.style.opacity = '0';
                    setTimeout(() => splitRow.remove(), 300);
                });

                this.refreshAfterDelete();
            });
        }
    }">
        @if($view === null)
            <x-slot:badge>
                <flux:badge as="button" size="sm" color="zinc" icon="cursor-arrow-rays" x-show="!bulkMode" x-on:click="bulkMode = true">Select Items</flux:badge>
                <div class="flex items-center gap-2" x-show="bulkMode" x-cloak>
                    <flux:badge color="indigo" size="sm">
                        <span x-text="$wire.selected.length"></span>&nbsp;Selected
                    </flux:badge>
                    <flux:button size="xs" variant="ghost" x-on:click="exitBulkMode()">Clear</flux:button>
                    <flux:dropdown align="end">
                        <flux:button size="xs" icon-trailing="chevron-down">Bulk actions</flux:button>
                        <flux:menu>
                            <flux:menu.item
                                icon="trash"
                                variant="danger"
                                wire:click="bulkDelete"
                                x-bind:disabled="$wire.selected.length === 0"
                            >
                                Delete selected
                            </flux:menu.item>
                        </flux:menu>
                    </flux:dropdown>
                </div>
            </x-slot:badge>
        @endif
        @if(in_array($view, ['projects.show', 'vendors.show']))
            <button type="button" @click="filtersOpen = !filtersOpen" class="flex w-full items-center justify-between">
                <flux:heading size="lg" class="mb-0">Expenses</flux:heading>
                <div class="flex items-center gap-2">
                    <span class="text-sm text-zinc-500 dark:text-zinc-400">Filters</span>
                    <flux:icon.chevron-down variant="mini" class="text-gray-400 transition-transform duration-200" ::class="filtersOpen && 'rotate-180'" />
                </div>
            </button>

            <div x-show="filtersOpen" x-collapse x-cloak class="mt-4">
                @include('livewire.expenses.partials.filter-fields', ['layout' => 'stacked'])
            </div>
        @endif

        <div class="space-y-4">
            <div class="-mb-2">
                <flux:table
                    wire:loading.class.delay.shortest="opacity-50 pointer-events-none"
                    wire:target="bulkDelete"
                    class="transition-opacity duration-150 table-fixed {{ in_array($view, ['projects.show', 'vendors.show']) ? 'min-w-0' : 'min-w-[640px]' }} w-full [:where(&)]:p-0 [:where(&)]:space-y-0"
                >
                <flux:table.columns>
                    @if($view === null)
                        <flux:table.column class="w-10 !px-3" x-show="bulkMode" x-cloak>
                            <span class="sr-only">Select</span>
                        </flux:table.column>
                    @endif
                    <flux:table.column class="w-[14%] min-w-[5.5rem]">Amount</flux:table.column>
                    <flux:table.column
                        sortable
                        :sorted="$sortBy === 'date'"
                        :direction="$sortDirection"
                        wire:click="sort('date')"
                        class="w-[14%] min-w-[6rem] !ps-8 !pe-3"
                        >
                        <div class="ps-4">Date</div>
                    </flux:table.column>

                    @if(!in_array($view, ['checks.show', 'vendors.show']))
                        <flux:table.column class="w-[25%] min-w-0 !ps-3">Vendor</flux:table.column>
                    @endif

                    @if($view != 'projects.show')
                        <flux:table.column class="w-[30%] min-w-0">Project</flux:table.column>
                    @endif
                    <flux:table.column align="end" class="w-[17%] min-w-[5rem] shrink-0">Status</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->expenses as $expense)
                        <flux:table.row :key="$expense->id" data-expense-row="{{ $expense->id }}">
                            @if($view === null)
                                <flux:table.cell class="w-10 !px-3" x-show="bulkMode" x-cloak>
                                    <flux:checkbox size="sm" wire:model="selected" value="{{ $expense->id }}" />
                                </flux:table.cell>
                            @endif
                        <flux:table.cell variant="strong" class="w-[14%] min-w-[5.5rem]">
                                <div class="pe-4 flex items-center gap-1">
                                    <a href="{{ route('expenses.show', $expense->id) }}" wire:navigate.hover>{{ display_money($expense->amount) }}</a>
                                    @if($expense->reimbursment)
                                        <flux:badge size="sm" variant="outline" inset="top bottom" color="zinc" title="{{ $expense->reimbursment }}">R</flux:badge>
                                    @endif
                                    @if($expense->trashed())
                                        <button type="button" wire:click="restoreExpense({{ $expense->id }})" wire:confirm="Restore this expense?" class="text-emerald-500 hover:text-emerald-700 dark:hover:text-emerald-300" title="Restore expense">
                                            <flux:icon.arrow-uturn-left variant="micro" />
                                        </button>
                                    @elsecan('create', App\Models\Expense::class)
                                        <button type="button" wire:click="$dispatchTo('expenses.expense-create', 'editExpense', { expense: {{ $expense->id }} })" class="text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200" title="Edit expense">
                                            <flux:icon.pencil-square variant="micro" />
                                        </button>
                                    @endcan
                                </div>
                            </flux:table.cell>
                            <flux:table.cell class="w-[14%] min-w-[6rem] !ps-8 !pe-3">
                                <div class="ps-4">{{ $expense->date->format('m/d/y') }}</div>
                            </flux:table.cell>
                            @if(!in_array($view, ['checks.show', 'vendors.show']))
                                <flux:table.cell class="w-[25%] min-w-0 !ps-3">
                                    <a href="{{isset($expense->vendor->id) ? route('vendors.show', $expense->vendor->id) : ''}}" wire:navigate.hover>
                                        <div class="truncate whitespace-nowrap overflow-hidden text-ellipsis" title="{{$expense->vendor->name}}">{{$expense->vendor->name}}</div>
                                    </a>
                                </flux:table.cell>
                            @endif

                            @if($view != 'projects.show')
                                <flux:table.cell class="w-[30%] min-w-0">
                                    @if($expense->splits->count() > 0)
                                        SPLIT
                                    @else
                                        @if($expense->project?->id)
                                            <a href="{{ route('projects.show', $expense->project->id) }}" class="truncate whitespace-nowrap overflow-hidden text-ellipsis font-semibold block" title="{{ $expense->project->name }}" wire:navigate.hover>{{ $expense->project->name }}</a>
                                        @elseif($expense->distribution_id && $expense->distribution?->name)
                                            <div class="truncate whitespace-nowrap overflow-hidden text-ellipsis font-semibold" title="{{ $expense->distribution->name }}">{{ $expense->distribution->name }}</div>
                                        @else
                                            <div class="truncate whitespace-nowrap overflow-hidden text-ellipsis font-semibold" title="No Project">No Project</div>
                                        @endif
                                        @php
                                            $po = $expense->receipts->first()?->notes;
                                        @endphp
                                        @if($po)
                                            <div class="text-xs italic text-zinc-500 dark:text-zinc-400 truncate" title="{{ $po }}">{{ $po }}</div>
                                        @endif
                                    @endif
                                </flux:table.cell>
                            @endif
                            <flux:table.cell align="end" class="w-[17%] min-w-[5rem] shrink-0">
                                {{-- Just use status directly, no fallback needed if coming from search --}}
                                <div class="flex justify-end">
                                    <flux:badge size="sm" inset="top bottom" color="{{$expense->status_color}}" class="max-w-[8rem] overflow-hidden text-ellipsis whitespace-nowrap">
                                        {{$expense->status}}
                                    </flux:badge>
                                </div>
                            </flux:table.cell>
                        </flux:table.row>

                        {{-- Show split rows if expense has splits --}}
                        @if($expense->splits->count() > 0)
                            @php
                                $filteredSplits = $expense->splits;
                                if ($view === 'projects.show') {
                                    $filteredSplits = $filteredSplits->where('project_id', $project_id);
                                }
                                if ($reimbursement_filter) {
                                    $filteredSplits = $filteredSplits->filter(fn ($s) => ($s->reimbursment ?? 'None') === $reimbursement_filter);
                                }
                            @endphp
                            @foreach($filteredSplits as $split)
                                <flux:table.row :key="'split-' . $split->id" class="bg-gray-50 dark:bg-gray-800/50 [&_td]:!py-2" data-expense-split-parent="{{ $expense->id }}">
                                    @if($view === null)
                                        <flux:table.cell class="w-10 !px-3" x-show="bulkMode" x-cloak></flux:table.cell>
                                    @endif
                                    <flux:table.cell class="text-sm text-gray-600 dark:text-gray-400 tabular-nums w-[14%] min-w-[5.5rem] !pl-10 !pe-8">
                                        <div class="pe-4 flex items-center gap-1">
                                            {{ display_money($split->amount) }}
                                            @if($split->reimbursment && $split->reimbursment !== 'None')
                                                <flux:badge size="sm" variant="outline" inset="top bottom" color="zinc" title="{{ $split->reimbursment }}">R</flux:badge>
                                            @endif
                                        </div>
                                    </flux:table.cell>
                                    {{-- Preserve column alignment: empty date cell --}}
                                    <flux:table.cell class="w-[14%] min-w-[6rem] !ps-8 !pe-3">
                                        <div class="ps-4"></div>
                                    </flux:table.cell>
                                    @if(!in_array($view, ['checks.show', 'vendors.show']))
                                        {{-- Empty vendor cell for split rows --}}
                                        <flux:table.cell class="w-[25%] min-w-0 !ps-3"></flux:table.cell>
                                    @endif
                                    @if($view != 'projects.show')
                                        <flux:table.cell class="text-sm text-gray-600 dark:text-gray-400 w-[30%] min-w-0">
                                            {{-- Prefer distribution name, then project accessor; link if project exists --}}
                                            @php
                                                $splitProjectName = '';
                                                if(!is_null($split->distribution_id) && isset($split->distribution->name)) {
                                                    $splitProjectName = $split->distribution->name;
                                                } elseif(isset($split->project->id)) {
                                                    $splitProjectName = $split->project->name;
                                                } else {
                                                    $splitProjectName = 'No Project';
                                                }
                                            @endphp
                                            @if(isset($split->project->id))
                                                <a href="{{ route('projects.show', $split->project->id) }}" class="truncate whitespace-nowrap overflow-hidden text-ellipsis block" title="{{ $splitProjectName }}" wire:navigate.hover>{{ $splitProjectName }}</a>
                                            @else
                                                <div class="truncate whitespace-nowrap overflow-hidden text-ellipsis" title="{{ $splitProjectName }}">{{ $splitProjectName }}</div>
                                            @endif
                                        </flux:table.cell>
                                    @endif
                                    <flux:table.cell align="end" class="text-sm text-gray-600 dark:text-gray-400 w-[17%] min-w-[5rem] shrink-0">
                                        <flux:badge size="sm" variant="outline" color="gray">Split</flux:badge>
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        @endif

                        {{-- Show matched receipt items when receipt search is active --}}
                        @if($receipt_search && isset($matchedReceiptItems[$expense->id]))
                            @foreach($matchedReceiptItems[$expense->id] as $receiptItem)
                                @php
                                    $descs = explode(' | ', $receiptItem['descriptions']);
                                    $codes = explode(' ', $receiptItem['product_codes']);
                                @endphp
                                @foreach($descs as $di => $desc)
                                    @php
                                        $code = $codes[$di] ?? '';
                                        $descMatches = str_contains($desc, '<mark>');
                                        $codeMatches = $code && str_contains($code, '<mark>');
                                    @endphp
                                    @if($descMatches || $codeMatches)
                                        <flux:table.row :key="'receipt-' . $expense->id . '-' . $loop->parent->index . '-' . $di" class="bg-amber-50/50 dark:bg-amber-900/10 [&_td]:!py-1 !border-none">
                                            @php
                                                $totalCols = 2
                                                    + (!in_array($view, ['checks.show', 'vendors.show']) ? 1 : 0)
                                                    + ($view != 'projects.show' ? 1 : 0)
                                                    + 1
                                                    + ($view === null ? 1 : 0);
                                            @endphp
                                            <flux:table.cell :colspan="$totalCols" class="!pl-10 !pr-5 !pb-0">
                                                <div class="truncate text-xs text-gray-600 dark:text-gray-400" title="{{ strip_tags($desc) }}">{!! strip_tags($desc, '<mark>') !!}</div>
                                                @if($code)
                                                    <div class="text-xs text-gray-400 dark:text-gray-500 italic" title="{{ strip_tags($code) }}">
                                                        @if(isset($expense->vendor) && $expense->vendor->sku_search_url)
                                                            <flux:link href="{{ $expense->vendor->sku_search_url . strip_tags($code) }}" external variant="subtle">{!! strip_tags($code, '<mark>') !!}</flux:link>
                                                        @else
                                                            {!! strip_tags($code, '<mark>') !!}
                                                        @endif
                                                    </div>
                                                @endif
                                            </flux:table.cell>
                                        </flux:table.row>
                                    @endif
                                @endforeach
                            @endforeach
                        @endif
                    @endforeach
                </flux:table.rows>
                </flux:table>

                @if($this->expenses->hasPages())
                <div class="px-6 pb-6 pt-4">
                    <flux:pagination :paginator="$this->expenses" />
                </div>
                @endif
            </div>
        </div>
    </x-island-card>
    @endif

    @if($view === NULL && auth()->user()->can('create', App\Models\Expense::class))
        <x-island-card heading="Transactions" x-init="if (!$wire.transactionsReady) { $wire.loadTransactions() }">
            <x-slot:actions>
                @if($this->transactionsReady && $this->transactions->total() > 0)
                    <flux:badge size="sm" color="yellow">{{ $this->transactions->total() }} unmatched</flux:badge>
                @endif
            </x-slot:actions>
            <div class="px-6 pt-4 pb-2">
                <flux:input wire:model.live.debounce.300ms="transaction_search" placeholder="Search vendor (e.g. ZELLE)..." icon="magnifying-glass" clearable />
            </div>
            <div>
                @if($this->transactionsReady)
                    <flux:table :paginate="$this->transactions->hasPages() ? $this->transactions : null" wire:loading.class="opacity-50 text-opacity-50">
                        <flux:table.columns>
                            <flux:table.column>Amount</flux:table.column>
                            <flux:table.column>Date</flux:table.column>
                            <flux:table.column>Vendor</flux:table.column>
                            <flux:table.column>Bank</flux:table.column>
                            <flux:table.column>Account</flux:table.column>
                        </flux:table.columns>

                        <flux:table.rows>
                            @foreach ($this->transactions as $transaction)
                                <flux:table.row :key="$transaction->id" wire:transition>
                                    <flux:table.cell
                                        wire:click="$dispatchTo('expenses.expense-create', 'createExpenseFromTransaction', { transaction: {{$transaction->id}}})"
                                        variant="strong"
                                        class="cursor-pointer"
                                        >
                                        {{ money($transaction->amount) }}
                                    </flux:table.cell>
                                    <flux:table.cell>{{ $transaction->transaction_date->format('m/d/Y') }}</flux:table.cell>
                                    @if($transaction->vendor->name != 'No Vendor')
                                        <flux:table.cell class="max-w-[150px] truncate" title="{{ $transaction->vendor->name }}">
                                            {{ $transaction->vendor->name }}
                                        </flux:table.cell>
                                    @else
                                        <flux:table.cell class="whitespace-normal break-words" title="{{ $transaction->plaid_merchant_description }}">
                                            {{ $transaction->plaid_merchant_description }}
                                        </flux:table.cell>
                                    @endif
                                    <flux:table.cell>{{ $transaction->bank_account->bank->name }}</flux:table.cell>
                                    <flux:table.cell>{{ isset($transaction->owner) ? $transaction->owner : $transaction->bank_account->account_number }}</flux:table.cell>
                                    {{--
                                    @if(!in_array($view, ['checks.show', 'vendors.show']))
                                        <flux:table.cell><a href="{{isset($expense->vendor->id) ? route('vendors.show', $expense->vendor->id) : ''}}">{{Str::limit($expense->vendor->name, 20)}}</a></flux:table.cell>
                                    @endif

                                    @if($view != 'projects.show')
                                        <flux:table.cell>
                                            @if($expense->project_id)
                                                <a wire:navigate.hover href="{{route('projects.show', $expense->project->id)}}">{{ Str::limit($expense->project->name, 25) }}</a>
                                            @else
                                                {{ Str::limit($expense->project->name, 25) }}
                                            @endif
                                        </flux:table.cell>
                                    @endif
                                    <flux:table.cell>
                                        <flux:badge size="sm" :color="'sky'" inset="top bottom">Status</flux:badge>
                                    </flux:table.cell> --}}
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>
                @else
                    <div class="p-4 text-sm text-zinc-500">Loading transactions…</div>
                @endif
            </div>
        </x-island-card>
    @endif

    <livewire:expenses.expense-create />

    {{-- Barcode Scanner Modal --}}
    <flux:modal name="barcode-scanner" class="sm:max-w-md">
        <div x-data="{
            scanning: false,
            scanner: null,
            stream: null,
            video: null,
            detector: null,
            useNative: false,
            error: null,
            _raf: null,
            init() {
                this.useNative = 'BarcodeDetector' in window;
                this._handler = (e) => {
                    if (e.detail === 'barcode-scanner' || e.detail?.name === 'barcode-scanner') {
                        setTimeout(() => this.startScanning(), 400);
                    }
                };
                document.addEventListener('modal-show', this._handler);
            },
            destroy() {
                this.releaseCamera();
                if (this._handler) document.removeEventListener('modal-show', this._handler);
            },
            async startScanning() {
                this.error = null;

                const readerEl = document.getElementById('barcode-reader');
                if (!readerEl) {
                    this.error = 'Scanner element not found. Please try again.';
                    return;
                }

                try {
                    if (this.useNative) {
                        await this.startNativeScanner(readerEl);
                    } else {
                        await this.startFallbackScanner(readerEl);
                    }
                    this.scanning = true;
                } catch (e) {
                    console.error('Barcode scanner error:', e);
                    let msg = e?.message || e?.toString?.() || '';
                    if (msg.includes('NotAllowedError') || msg.includes('Permission')) {
                        this.error = 'Camera permission was denied. Please allow camera access in your browser settings and try again.';
                    } else if (msg.includes('NotFoundError') || msg.includes('not found')) {
                        this.error = 'No camera found on this device.';
                    } else {
                        this.error = msg || 'Could not start camera. Please try again.';
                    }
                }
            },
            async startNativeScanner(container) {
                if (!this.detector) {
                    this.detector = new BarcodeDetector({ formats: ['ean_13', 'ean_8', 'upc_a', 'upc_e', 'code_128', 'code_39', 'qr_code'] });
                }

                // Reuse existing stream if still active
                if (this.stream && this.stream.active && this.video) {
                    if (!this.video.parentElement) {
                        container.innerHTML = '';
                        container.appendChild(this.video);
                    }
                    await this.video.play();
                } else {
                    // Request camera (triggers permission prompt only on first call per session)
                    this.stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' }, audio: false });
                    this.video = document.createElement('video');
                    this.video.srcObject = this.stream;
                    this.video.setAttribute('playsinline', '');
                    this.video.style.cssText = 'width:100%;border-radius:0.5rem;';
                    container.innerHTML = '';
                    container.appendChild(this.video);
                    await this.video.play();
                }

                const scan = async () => {
                    if (!this.scanning && !this._raf) return;
                    try {
                        const barcodes = await this.detector.detect(this.video);
                        if (barcodes.length > 0) {
                            const value = barcodes[0].rawValue;
                            this.pauseScanning();
                            $wire.set('receipt_search', value);
                            $flux.modal('barcode-scanner').close();
                            return;
                        }
                    } catch (e) { }
                    this._raf = requestAnimationFrame(scan);
                };
                this._raf = requestAnimationFrame(scan);
            },
            async startFallbackScanner(container) {
                if (typeof Html5Qrcode === 'undefined') {
                    throw new Error('Scanner library not loaded. Please refresh and try again.');
                }
                // Html5Qrcode manages its own stream, so stop previous instance first
                if (this.scanner) {
                    try { await this.scanner.stop(); } catch (e) { }
                    try { this.scanner.clear(); } catch (e) { }
                }
                container.innerHTML = '';
                this.scanner = new Html5Qrcode('barcode-reader');
                await this.scanner.start(
                    { facingMode: 'environment' },
                    { fps: 10, qrbox: { width: 250, height: 150 } },
                    (decodedText) => {
                        this.pauseScanning();
                        $wire.set('receipt_search', decodedText);
                        $flux.modal('barcode-scanner').close();
                    },
                    () => { }
                );
            },
            pauseScanning() {
                this.scanning = false;
                if (this._raf) { cancelAnimationFrame(this._raf); this._raf = null; }
                // Pause video but keep stream alive (no permission re-prompt)
                if (this.video) {
                    try { this.video.pause(); } catch (e) { }
                }
                // For fallback scanner, stop it (it manages its own stream)
                if (this.scanner) {
                    try { this.scanner.stop(); } catch (e) { }
                }
            },
            releaseCamera() {
                this.scanning = false;
                if (this._raf) { cancelAnimationFrame(this._raf); this._raf = null; }
                if (this.stream) {
                    this.stream.getTracks().forEach(t => t.stop());
                    this.stream = null;
                }
                this.video = null;
                this.detector = null;
                if (this.scanner) {
                    try { this.scanner.stop(); } catch (e) { }
                    try { this.scanner.clear(); } catch (e) { }
                    this.scanner = null;
                }
            }
        }" x-on:close="pauseScanning()" class="space-y-4">
            <flux:heading size="lg">Scan Barcode</flux:heading>

            <div x-show="error" class="text-sm text-red-600 dark:text-red-400" x-text="error"></div>

            <div id="barcode-reader" class="overflow-hidden rounded-lg" style="min-height: 280px;"></div>

            <flux:text class="text-xs text-center">Point the camera at a barcode or SKU number</flux:text>

            <div class="flex justify-end">
                <flux:button variant="ghost" x-on:click="pauseScanning(); $flux.modal('barcode-scanner').close()">Cancel</flux:button>
            </div>
        </div>
    </flux:modal>

</div>