<div class="max-w-3xl space-y-2" wire:transition>
    @if($view === NULL)
        {{-- Mobile: accordion collapsed by default --}}
        <flux:card class="!px-5 !py-2 sm:hidden">
            <flux:accordion transition>
                <flux:accordion.item>
                    <flux:accordion.heading>
                        <flux:heading size="lg">Filters</flux:heading>
                    </flux:accordion.heading>
                    <flux:accordion.content>
                        <div class="flex flex-col gap-4">
                            <div class="min-w-0 w-full">
                                <flux:input wire:model.live.debounce.300ms="amount" label="Amount" icon="magnifying-glass" placeholder="Search Amount" />
                            </div>
                            <div class="min-w-0 w-full">
                                <flux:select wire:model.live="expense_vendor" label="Vendor" variant="listbox" searchable clearable placeholder="Choose Vendor...">
                                    <x-slot name="search">
                                        <flux:select.search placeholder="Search..." />
                                    </x-slot>
                                    <flux:select.option value="0">NO VENDOR</flux:select.option>
                                    <flux:select.option disabled>---------</flux:select.option>
                                    @foreach ($vendors as $vendor)
                                        <flux:select.option value="{{$vendor->id}}">{{ $vendor->name }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                            </div>
                            <div class="min-w-0 w-full">
                                <flux:select wire:model.live="project_id" label="Project" variant="listbox" searchable clearable placeholder="Choose Project...">
                                    <x-slot name="search">
                                        <flux:select.search placeholder="Search..." />
                                    </x-slot>
                                    <flux:select.option value="NO_PROJECT">NO PROJECT</flux:select.option>
                                    <flux:select.option value="SPLIT">SPLIT</flux:select.option>
                                    <flux:select.option disabled>---------</flux:select.option>
                                    @foreach ($distributions as $distribution)
                                        <flux:select.option value="D:{{$distribution->id}}">{{ $distribution->name }}</flux:select.option>
                                    @endforeach
                                    <flux:select.option disabled>---------</flux:select.option>
                                    @foreach ($projects as $project)
                                        <flux:select.option value="{{$project->id}}"><div>{{ $project->short_address }} <br> <i class="font-normal">{{$project->project_name}}</i></div></flux:select.option>
                                    @endforeach
                                </flux:select>
                            </div>
                            <div class="min-w-0 w-full">
                                <flux:select variant="listbox" label="Status" multiple placeholder="Choose status..." wire:model.live="expense_statuses">
                                    <flux:select.option value="Complete"><flux:badge size="md" inset="top bottom" color="green">Complete</flux:badge></flux:select.option>
                                    <flux:select.option value="No Transaction"><flux:badge size="md" inset="top bottom" color="yellow">No Transaction</flux:badge></flux:select.option>
                                    <flux:select.option value="No Project"><flux:badge size="md" inset="top bottom" color="red">No Project</flux:badge></flux:select.option>
                                    <flux:select.option value="Missing Info"><flux:badge size="md" inset="top bottom" color="amber">Missing Info</flux:badge></flux:select.option>
                                </flux:select>
                            </div>
                            <div class="min-w-0 w-full">
                                <flux:date-picker
                                    wire:model.live="date_range"
                                    mode="range"
                                    with-presets
                                    presets="last30Days last3Months last6Months thisMonth lastMonth thisYear lastYear custom"
                                    clearable
                                    placeholder="All time"
                                    label="Date Range"
                                />
                            </div>
                            <div class="min-w-0 w-full">
                                <flux:input wire:model.live.debounce.400ms="receipt_search" icon="magnifying-glass" placeholder="Search items, SKU, barcode..." clearable label="Receipt Items">
                                    <x-slot name="iconTrailing">
                                        <flux:button size="sm" variant="subtle" icon="scan-barcode" class="-mr-1" x-on:click="$flux.modal('barcode-scanner').show()" title="Scan barcode" />
                                    </x-slot>
                                </flux:input>
                            </div>
                            @can('create', App\Models\Expense::class)
                                @if($amount && $view == NULL)
                                    <flux:button wire:click="$dispatchTo('expenses.expense-create', 'newExpense', { amount: {{$amount}}})">Add New Expense</flux:button>
                                @endif
                            @endcan
                        </div>
                    </flux:accordion.content>
                </flux:accordion.item>
            </flux:accordion>
        </flux:card>

        {{-- Desktop: always expanded --}}
        <x-island-card heading="Filters" :separator="true" class="hidden sm:block">
            <x-slot:actions>
                @can('create', App\Models\Expense::class)
                    @if($amount && $view == NULL)
                        <flux:button wire:click="$dispatchTo('expenses.expense-create', 'newExpense', { amount: {{$amount}}})">Add New Expense</flux:button>
                    @endif
                @endcan
            </x-slot:actions>

            <div class="flex flex-col sm:flex-row items-end gap-4">
                <div class="flex-1 min-w-0 w-full">
                    <flux:input wire:model.live.debounce.300ms="amount" label="Amount" icon="magnifying-glass" placeholder="Search Amount" />
                </div>

                <div class="flex-1 min-w-0 w-full">
                    <flux:select wire:model.live="expense_vendor" label="Vendor" variant="listbox" searchable clearable placeholder="Choose Vendor...">
                        <x-slot name="search">
                            <flux:select.search placeholder="Search..." />
                        </x-slot>

                        <flux:select.option value="0">NO VENDOR</flux:select.option>
                        <flux:select.option disabled>---------</flux:select.option>
                        @foreach ($vendors as $vendor)
                            <flux:select.option value="{{$vendor->id}}">{{ $vendor->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>

                <div class="flex-1 min-w-0 w-full">
                    <flux:select wire:model.live="project_id" label="Project" variant="listbox" searchable clearable placeholder="Choose Project...">
                        <x-slot name="search">
                            <flux:select.search placeholder="Search..." />
                        </x-slot>

                        <flux:select.option value="NO_PROJECT">NO PROJECT</flux:select.option>
                        <flux:select.option value="SPLIT">SPLIT</flux:select.option>
                        <flux:select.option disabled>---------</flux:select.option>
                        @foreach ($distributions as $distribution)
                            <flux:select.option value="D:{{$distribution->id}}">{{ $distribution->name }}</flux:select.option>
                        @endforeach
                        <flux:select.option disabled>---------</flux:select.option>
                        @foreach ($projects as $project)
                            <flux:select.option value="{{$project->id}}"><div>{{ $project->short_address }} <br> <i class="font-normal">{{$project->project_name}}</i></div></flux:select.option>
                        @endforeach
                    </flux:select>
                </div>

                <div class="flex-1 min-w-0 w-full">
                    <flux:select variant="listbox" label="Status" multiple placeholder="Choose status..." wire:model.live="expense_statuses">
                        <flux:select.option value="Complete"><flux:badge size="md" inset="top bottom" color="green">Complete</flux:badge></flux:select.option>
                        <flux:select.option value="No Transaction"><flux:badge size="md" inset="top bottom" color="yellow">No Transaction</flux:badge></flux:select.option>
                        <flux:select.option value="No Project"><flux:badge size="md" inset="top bottom" color="red">No Project</flux:badge></flux:select.option>
                        <flux:select.option value="Missing Info"><flux:badge size="md" inset="top bottom" color="amber">Missing Info</flux:badge></flux:select.option>
                    </flux:select>
                </div>

                <div class="flex-1 min-w-0 w-full">
                    <flux:date-picker
                        wire:model.live="date_range"
                        mode="range"
                        with-presets
                        presets="last30Days last3Months last6Months thisMonth lastMonth thisYear lastYear custom"
                        clearable
                        placeholder="All time"
                        label="Date Range"
                    />
                </div>
            </div>

            <div class="flex flex-col sm:flex-row items-end gap-4 mt-4">
                <div class="flex-1 min-w-0 w-full sm:max-w-xs">
                    <flux:input wire:model.live.debounce.400ms="receipt_search" icon="magnifying-glass" placeholder="Search items, SKU, barcode..." clearable label="Receipt Items">
                        <x-slot name="iconTrailing">
                            <flux:button size="sm" variant="subtle" icon="scan-barcode" class="-mr-1" x-on:click="$flux.modal('barcode-scanner').show()" title="Scan barcode" />
                        </x-slot>
                    </flux:input>
                </div>
            </div>
        </x-island-card>
    @endif

    {{-- Hide expenses card on project page when there are no expenses --}}
    @if($view !== 'projects.show' || $this->expenses->isNotEmpty())
    <x-island-card heading="Expenses" class="overflow-hidden" x-data="{
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
            });
        }
    }">
        <div class="space-y-4">
            <div class="-mx-5 -mb-2 overflow-x-hidden">
                <flux:table
                    wire:loading.class="opacity-50 text-opacity-50"
                    class="table-fixed w-full [:where(&)]:p-0 [:where(&)]:space-y-0 [&_th]:!px-4 [&_td]:!px-3 [&_th:first-child]:!ps-6 [&_th:last-child]:!pe-6 [&_td:first-child]:!ps-6 [&_td:last-child]:!pe-6"
                >
                <flux:table.columns>
                    <flux:table.column class="w-[14%] min-w-[5.5rem] !pe-8">
                        <div class="pe-4">Amount</div>
                    </flux:table.column>
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
                            <flux:table.cell variant="strong" class="w-[14%] min-w-[5.5rem] !pe-8">
                                <div class="pe-4 flex items-center gap-1">
                                    <a href="{{ route('expenses.show', $expense->id) }}" class="hover:underline" wire:navigate>{{ display_money($expense->amount) }}</a>
                                    @can('create', App\Models\Expense::class)
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
                                    <a href="{{isset($expense->vendor->id) ? route('vendors.show', $expense->vendor->id) : ''}}">
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
                                            <a href="{{ route('projects.show', $expense->project->id) }}" class="truncate whitespace-nowrap overflow-hidden text-ellipsis font-semibold block hover:underline" title="{{ $expense->project->name }}" wire:navigate>{{ $expense->project->name }}</a>
                                        @else
                                            <div class="truncate whitespace-nowrap overflow-hidden text-ellipsis font-semibold" title="No Project">No Project</div>
                                        @endif
                                        @php
                                            $po = $expense->receipts->first()?->receipt_items['purchase_order'] ?? null;
                                        @endphp
                                        @if($po && $po !== 'null')
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
                            @foreach($expense->splits as $split)
                                @if($view === 'projects.show' && (string)$split->project_id !== (string)$project_id)
                                    @continue
                                @endif
                                <flux:table.row :key="'split-' . $split->id" class="bg-gray-50 dark:bg-gray-800/50 [&_td]:!py-2" data-expense-split-parent="{{ $expense->id }}">
                                    <flux:table.cell class="text-sm text-gray-600 dark:text-gray-400 tabular-nums w-[14%] min-w-[5.5rem] !pl-10 !pe-8">
                                        <div class="pe-4">{{ display_money($split->amount) }}</div>
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
                                                <a href="{{ route('projects.show', $split->project->id) }}" class="truncate whitespace-nowrap overflow-hidden text-ellipsis block hover:underline" title="{{ $splitProjectName }}" wire:navigate>{{ $splitProjectName }}</a>
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
                                                    + 1;
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

                <div class="px-6 pb-6 pt-4">
                    <flux:pagination :paginator="$this->expenses" />
                </div>
            </div>
        </div>
    </x-island-card>
    @endif

    @if($view === NULL && auth()->user()->can('create', App\Models\Expense::class))
        <x-island-card heading="Transactions" wire:init="loadTransactions">
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
                this.stopScanning();
                if (this._handler) document.removeEventListener('modal-show', this._handler);
            },
            async startScanning() {
                this.error = null;
                this.scanning = false;

                const readerEl = document.getElementById('barcode-reader');
                if (!readerEl) {
                    this.error = 'Scanner element not found. Please try again.';
                    return;
                }
                readerEl.innerHTML = '';

                try {
                    if (this.useNative) {
                        await this.startNativeScanner(readerEl);
                    } else {
                        await this.startFallbackScanner();
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
                const detector = new BarcodeDetector({ formats: ['ean_13', 'ean_8', 'upc_a', 'upc_e', 'code_128', 'code_39', 'qr_code'] });
                this.stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' }, audio: false });
                const video = document.createElement('video');
                video.srcObject = this.stream;
                video.setAttribute('playsinline', '');
                video.style.cssText = 'width:100%;border-radius:0.5rem;';
                container.appendChild(video);
                await video.play();

                const scan = async () => {
                    if (!this.scanning && !this._raf) return;
                    try {
                        const barcodes = await detector.detect(video);
                        if (barcodes.length > 0) {
                            const value = barcodes[0].rawValue;
                            this.stopScanning();
                            $wire.set('receipt_search', value);
                            $flux.modal('barcode-scanner').close();
                            return;
                        }
                    } catch (e) { }
                    this._raf = requestAnimationFrame(scan);
                };
                this._raf = requestAnimationFrame(scan);
            },
            async startFallbackScanner() {
                if (typeof Html5Qrcode === 'undefined') {
                    throw new Error('Scanner library not loaded. Please refresh and try again.');
                }
                this.scanner = new Html5Qrcode('barcode-reader');
                await this.scanner.start(
                    { facingMode: 'environment' },
                    { fps: 10, qrbox: { width: 250, height: 150 } },
                    (decodedText) => {
                        this.stopScanning();
                        $wire.set('receipt_search', decodedText);
                        $flux.modal('barcode-scanner').close();
                    },
                    () => { }
                );
            },
            async stopScanning() {
                this.scanning = false;
                if (this._raf) { cancelAnimationFrame(this._raf); this._raf = null; }
                if (this.stream) {
                    this.stream.getTracks().forEach(t => t.stop());
                    this.stream = null;
                }
                if (this.scanner) {
                    try { await this.scanner.stop(); } catch (e) { }
                    try { this.scanner.clear(); } catch (e) { }
                    this.scanner = null;
                }
            }
        }" x-on:close="stopScanning()" class="space-y-4">
            <flux:heading size="lg">Scan Barcode</flux:heading>

            <div x-show="error" class="text-sm text-red-600 dark:text-red-400" x-text="error"></div>

            <div id="barcode-reader" class="overflow-hidden rounded-lg" style="min-height: 280px;"></div>

            <flux:text class="text-xs text-center">Point the camera at a barcode or SKU number</flux:text>

            <div class="flex justify-end">
                <flux:button variant="ghost" x-on:click="stopScanning(); $flux.modal('barcode-scanner').close()">Cancel</flux:button>
            </div>
        </div>
    </flux:modal>
</div>