{{-- Add a window and door supplier's quote to this estimate as a section
     (App\Livewire\Estimates\EstimateSupplierQuote). --}}
<div>
<flux:modal name="estimate-supplier-quote-modal" class="w-full max-w-6xl">
    <div class="space-y-5" data-supplier-quote>
        <div>
            <flux:heading size="lg">Add a supplier's window &amp; door quote</flux:heading>
            <flux:text class="mt-1">Upload the supplier's quote or order acknowledgement. Hive reads every unit, draws it, and adds one section to this estimate at the supplier's list price plus your markup. The supplier's discount is never shown and its sales tax is folded into each line.</flux:text>
        </div>

        {{-- UPLOAD --}}
        @if ($step === 'upload' || $step === 'failed')
            <div class="grid gap-4 lg:grid-cols-3">
                <div class="lg:col-span-2">
                    <flux:label>The supplier's quote</flux:label>
                    <flux:text size="sm" class="mt-1">A PDF or a photo. Up to 15 MB.</flux:text>
                    <div class="mt-2">
                        <input
                            type="file"
                            wire:model="upload"
                            accept=".pdf,.jpg,.jpeg,.png,.webp,.heic"
                            class="block w-full text-sm text-zinc-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-zinc-100 file:text-zinc-700 hover:file:bg-zinc-200 dark:text-zinc-400 dark:file:bg-white/10 dark:file:text-zinc-200"
                        />
                    </div>
                    <flux:text wire:loading wire:target="upload" size="sm" class="mt-2">Uploading…</flux:text>
                    <flux:error name="upload" />
                </div>
                <div>
                    <x-vendor-picker wire:model.live="supplierVendorId" label="Supplier (optional)" placeholder="Hive reads it off the quote" clearable />
                </div>
            </div>

            @if ($step === 'failed')
                <flux:callout variant="danger" icon="exclamation-triangle" heading="The quote could not be read">
                    <flux:callout.text>{{ $error }}</flux:callout.text>
                </flux:callout>
            @elseif ($error)
                <flux:text size="sm" class="text-red-600 dark:text-red-400">{{ $error }}</flux:text>
            @endif

            <div class="flex items-center gap-2">
                <flux:button variant="primary" icon="sparkles" wire:click="read" wire:loading.attr="disabled" wire:target="read,upload" x-bind:disabled="! $wire.upload">Read the quote</flux:button>
                <flux:button variant="ghost" x-on:click="$flux.modal('estimate-supplier-quote-modal').close()">Cancel</flux:button>
            </div>

            @if ($this->documents->isNotEmpty())
                <div data-supplier-quote-history>
                    <flux:heading size="sm" class="mb-2">Earlier quotes</flux:heading>
                    <div class="divide-y divide-zinc-100 rounded-lg border border-zinc-200 dark:divide-white/5 dark:border-white/10">
                        @foreach ($this->documents as $earlier)
                            <div wire:key="supplier-quote-{{ $earlier->id }}" class="flex flex-wrap items-center justify-between gap-2 px-3 py-2">
                                <div class="min-w-0">
                                    <div class="truncate text-sm font-medium text-zinc-900 dark:text-zinc-100">{{ $earlier->label() }}</div>
                                    <div class="text-xs text-zinc-500">{{ $earlier->original_name }} · {{ $earlier->created_at->timezone('America/Chicago')->format('M j, Y g:i A') }}</div>
                                </div>
                                <div class="flex items-center gap-2">
                                    @switch ($earlier->status)
                                        @case (\App\Models\EstimateSubDocument::STATUS_APPLIED)
                                            <flux:badge size="sm" color="green">Added to the estimate — {{ count(data_get($earlier->applied, 'lines', [])) }} {{ Str::plural('line', count(data_get($earlier->applied, 'lines', []))) }}</flux:badge>
                                            @break
                                        @case (\App\Models\EstimateSubDocument::STATUS_MATCHED)
                                            <flux:badge size="sm" color="amber">Read, not added</flux:badge>
                                            @break
                                        @case (\App\Models\EstimateSubDocument::STATUS_FAILED)
                                            <flux:badge size="sm" color="red" :title="$earlier->failure_reason">Failed</flux:badge>
                                            @break
                                        @default
                                            <flux:badge size="sm" color="zinc">Reading…</flux:badge>
                                    @endswitch
                                    @if (in_array($earlier->status, [\App\Models\EstimateSubDocument::STATUS_MATCHED, \App\Models\EstimateSubDocument::STATUS_APPLIED], true))
                                        <flux:button size="xs" variant="ghost" wire:click="openDocument({{ $earlier->id }})">Open</flux:button>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        @endif

        {{-- READING --}}
        @if ($step === 'reading')
            <div wire:poll.3s="checkReading" class="flex items-center gap-3 rounded-lg border border-zinc-200 p-4 dark:border-white/10" data-supplier-quote-reading>
                <flux:icon.arrow-path class="size-5 animate-spin text-zinc-500" />
                <div>
                    <flux:heading size="sm">Reading the quote and drawing each unit…</flux:heading>
                    <flux:text size="sm">Usually under a minute. You can close this and come back; it will be under "Earlier quotes".</flux:text>
                </div>
            </div>
        @endif

        {{-- REVIEW --}}
        @if ($step === 'review' && $this->document)
            @php
                $document = $this->document;
                $applied = $document->status === \App\Models\EstimateSubDocument::STATUS_APPLIED;
                $read = (array) $document->document;
                $priced = $this->priced;
                $qty = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
            @endphp

            <div class="grid gap-4 rounded-lg bg-zinc-50 p-4 dark:bg-white/5 lg:grid-cols-4" data-supplier-quote-summary>
                <div class="lg:col-span-2">
                    <flux:text size="sm">Quote</flux:text>
                    <flux:heading>{{ $document->label() }}</flux:heading>
                    <flux:text size="sm">{{ $document->original_name }}@if ($read['customer_po'] ?? null) · PO {{ $read['customer_po'] }}@endif</flux:text>
                    <div class="mt-2 max-w-sm">
                        <x-vendor-picker wire:model.live="supplierVendorId" label="Supplier" placeholder="Who sent it?" clearable :disabled="$applied" />
                    </div>
                </div>
                <div>
                    <flux:text size="sm">Supplier's list prices</flux:text>
                    <flux:heading>{{ money($priced['list_subtotal']) }}</flux:heading>
                    <flux:text size="sm">
                        @if (($read['discount_amount'] ?? 0) > 0)
                            They give us {{ money($read['discount_amount']) }} off{{ ($read['discount_percent'] ?? null) ? ' ('.$qty($read['discount_percent']).'%)' : '' }}; not shown.
                        @endif
                        Tax {{ money($priced['tax_total']) }} folded into the lines.
                    </flux:text>
                </div>
                <div>
                    <flux:text size="sm">{{ $applied ? 'Added to the estimate as' : 'Goes on the estimate as' }}</flux:text>
                    <flux:heading>{{ money($priced['client_total']) }}</flux:heading>
                    <flux:text size="sm">{{ count($priced['rows']) }} {{ Str::plural('line', count($priced['rows'])) }} · markup {{ money($priced['markup_total']) }}</flux:text>
                </div>
            </div>

            @if ($applied)
                <flux:callout icon="check-circle" color="green" heading="Added on {{ $document->applied_at?->timezone('America/Chicago')->format('M j, Y g:i A') }}">
                    <flux:callout.text>Section "{{ data_get($document->applied, 'section_name') }}" with {{ count(data_get($document->applied, 'lines', [])) }} {{ Str::plural('line', count(data_get($document->applied, 'lines', []))) }} at {{ $qty($document->markup_percent) }}% markup.</flux:callout.text>
                </flux:callout>
            @else
                <div class="grid gap-4 sm:grid-cols-3 items-end" data-supplier-quote-options>
                    <flux:input wire:model.live.debounce.400ms="markupPercent" type="number" step="0.5" min="0" max="100" label="Markup on the supplier's list price" description="Remembered for this supplier" icon:trailing="percent-badge" />
                    <flux:input wire:model="sectionName" label="Section name" class="sm:col-span-2" />
                </div>
                <flux:error name="markupPercent" />
                <flux:error name="sectionName" />
            @endif

            <div class="overflow-x-auto">
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column class="w-20"></flux:table.column>
                        <flux:table.column>Unit</flux:table.column>
                        <flux:table.column class="text-right">Qty</flux:table.column>
                        <flux:table.column class="text-right">List</flux:table.column>
                        <flux:table.column class="text-right">Markup</flux:table.column>
                        <flux:table.column class="text-right">Tax</flux:table.column>
                        <flux:table.column class="text-right">Price each</flux:table.column>
                        <flux:table.column class="text-right">Total</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach (($read['items'] ?? []) as $item)
                            @php
                                $row = $priced['rows'][$item['index']] ?? null;
                                $spec = \App\Services\Estimates\SupplierQuoteSectionWriter::spec($item);
                            @endphp
                            <flux:table.row wire:key="quote-item-{{ $document->id }}-{{ $item['index'] }}" data-quote-item="{{ $spec['type'] }}">
                                <flux:table.cell class="align-top">
                                    <x-window-diagram :spec="$spec" :width="56" />
                                </flux:table.cell>
                                <flux:table.cell class="align-top !whitespace-normal">
                                    <div class="min-w-56 max-w-md">
                                        <div class="font-medium text-zinc-900 dark:text-zinc-100">{{ \App\Services\Estimates\SupplierQuoteSectionWriter::lineName($item) }}</div>
                                        <div class="mt-0.5 text-xs text-zinc-500">{{ \App\Services\Estimates\SupplierQuoteSectionWriter::description($item) }}</div>
                                        @if (! empty($item['notes']))
                                            <div class="mt-0.5 text-xs italic text-zinc-400">{{ $item['notes'] }}</div>
                                        @endif
                                    </div>
                                </flux:table.cell>
                                <flux:table.cell class="align-top text-right tabular-nums">{{ $qty($item['quantity']) }}</flux:table.cell>
                                <flux:table.cell class="align-top text-right tabular-nums text-zinc-500">{{ $row ? money($row['list_unit_price']) : '—' }}</flux:table.cell>
                                <flux:table.cell class="align-top text-right tabular-nums text-zinc-500">{{ $row ? money($row['markup_unit']) : '—' }}</flux:table.cell>
                                <flux:table.cell class="align-top text-right tabular-nums text-zinc-500">{{ $row ? money($row['tax_unit']) : '—' }}</flux:table.cell>
                                <flux:table.cell class="align-top text-right tabular-nums">{{ $row ? money($row['client_unit_price']) : '—' }}</flux:table.cell>
                                <flux:table.cell class="align-top text-right tabular-nums font-medium">{{ $row ? money($row['client_total']) : '—' }}</flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </div>

            @if ($error)
                <flux:text size="sm" class="text-red-600 dark:text-red-400">{{ $error }}</flux:text>
            @endif

            <div class="flex flex-wrap items-center gap-2">
                @unless ($applied)
                    <flux:button variant="primary" icon="plus" wire:click="addToEstimate" wire:loading.attr="disabled" wire:target="addToEstimate">Add to the estimate</flux:button>
                @endunless
                <flux:button variant="ghost" wire:click="startOver">{{ $applied ? 'Back' : 'Start over' }}</flux:button>
                <flux:button variant="ghost" x-on:click="$flux.modal('estimate-supplier-quote-modal').close()">Close</flux:button>
            </div>
        @endif
    </div>
</flux:modal>
</div>
