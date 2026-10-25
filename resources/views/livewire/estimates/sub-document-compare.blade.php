{{-- Compare a sub's updated estimate/invoice against this estimate
     (App\Livewire\Estimates\EstimateSubDocumentCompare). --}}
<div>
<flux:modal name="estimate-sub-document-modal" class="w-full max-w-6xl">
    <div class="space-y-5" data-sub-document-compare>
        <div>
            <flux:heading size="lg">Compare a sub's estimate or invoice</flux:heading>
            <flux:text class="mt-1">Upload what the sub sent. Hive reads its lines, lines them up with this estimate, and proposes what to add to the change order and what to credit — at our prices.</flux:text>
        </div>

        {{-- UPLOAD --}}
        @if ($step === 'upload' || $step === 'failed')
            <div class="grid gap-4 lg:grid-cols-3">
                <div class="lg:col-span-2">
                    <flux:label>The sub's document</flux:label>
                    <flux:text size="sm" class="mt-1">A PDF, a photo, or an Excel/CSV export. Up to 15 MB.</flux:text>
                    <div class="mt-2">
                        <input
                            type="file"
                            wire:model="upload"
                            accept=".pdf,.jpg,.jpeg,.png,.webp,.heic,.xlsx,.xls,.csv"
                            class="block w-full text-sm text-zinc-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-zinc-100 file:text-zinc-700 hover:file:bg-zinc-200 dark:file:bg-zinc-800 dark:file:text-zinc-300 dark:hover:file:bg-zinc-700"
                        />
                    </div>
                    <flux:text wire:loading wire:target="upload" size="sm" class="mt-2">Uploading…</flux:text>
                    <flux:error name="upload" />
                </div>
                <div>
                    <x-vendor-picker wire:model.live="subVendorId" label="Subcontractor (optional)" placeholder="Hive reads it off the document" clearable />
                </div>
            </div>

            @if ($step === 'failed')
                <flux:callout variant="danger" icon="exclamation-triangle" heading="The document could not be read">
                    <flux:callout.text>{{ $error }}</flux:callout.text>
                </flux:callout>
            @elseif ($error)
                <flux:text size="sm" class="text-red-600 dark:text-red-400">{{ $error }}</flux:text>
            @endif

            <div class="flex items-center gap-2">
                <flux:button variant="primary" icon="sparkles" wire:click="analyze" wire:loading.attr="disabled" wire:target="analyze,upload" x-bind:disabled="! $wire.upload">Read and compare</flux:button>
                <flux:button variant="ghost" x-on:click="$flux.modal('estimate-sub-document-modal').close()">Cancel</flux:button>
            </div>

            @if ($this->documents->isNotEmpty())
                <div data-sub-document-history>
                    <flux:heading size="sm" class="mb-2">Earlier comparisons</flux:heading>
                    <div class="divide-y divide-zinc-100 rounded-lg border border-zinc-200 dark:divide-white/5 dark:border-white/10">
                        @foreach ($this->documents as $earlier)
                            <div wire:key="sub-document-{{ $earlier->id }}" class="flex flex-wrap items-center justify-between gap-2 px-3 py-2">
                                <div class="min-w-0">
                                    <div class="truncate text-sm font-medium text-zinc-900 dark:text-zinc-100">{{ $earlier->label() }}</div>
                                    <div class="text-xs text-zinc-500">{{ $earlier->original_name }} · {{ $earlier->created_at->timezone('America/Chicago')->format('M j, Y g:i A') }}</div>
                                </div>
                                <div class="flex items-center gap-2">
                                    @switch ($earlier->status)
                                        @case (\App\Models\EstimateSubDocument::STATUS_APPLIED)
                                            <flux:badge size="sm" color="green">Applied — {{ count(data_get($earlier->applied, 'added', [])) }} added, {{ count(data_get($earlier->applied, 'credited', [])) }} credited</flux:badge>
                                            @break
                                        @case (\App\Models\EstimateSubDocument::STATUS_MATCHED)
                                            <flux:badge size="sm" color="amber">Reviewed, not applied</flux:badge>
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

        {{-- ANALYZING --}}
        @if ($step === 'analyzing')
            <div wire:poll.3s="checkAnalysis" class="flex items-center gap-3 rounded-lg border border-zinc-200 p-4 dark:border-white/10" data-sub-document-analyzing>
                <flux:icon.arrow-path class="size-5 animate-spin text-zinc-500" />
                <div>
                    <flux:heading size="sm">Reading the document and comparing it with the estimate…</flux:heading>
                    <flux:text size="sm">Usually under a minute. You can close this and come back; it will be under "Earlier comparisons".</flux:text>
                </div>
            </div>
        @endif

        {{-- REVIEW --}}
        @if ($step === 'review' && $this->document)
            @php
                $document = $this->document;
                $applied = $document->status === \App\Models\EstimateSubDocument::STATUS_APPLIED;
                $kindBadge = ['increase' => ['green', 'Add'], 'new' => ['green', 'New'], 'decrease' => ['orange', 'Credit'], 'missing' => ['zinc', 'Not listed'], 'same' => ['zinc', 'Same'], 'review' => ['amber', 'Review']];
                $qty = fn ($v) => $v === null ? '—' : rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
            @endphp

            <div class="grid gap-4 rounded-lg bg-zinc-50 p-4 dark:bg-white/5 lg:grid-cols-4" data-sub-document-summary>
                <div class="lg:col-span-2">
                    <flux:text size="sm">Document</flux:text>
                    <flux:heading>{{ $document->label() }}</flux:heading>
                    <flux:text size="sm">{{ $document->original_name }}@if ($trades = data_get($document->proposals, 'trades')) · {{ implode(', ', $trades) }}@endif</flux:text>
                    <div class="mt-2 max-w-sm">
                        <x-vendor-picker wire:model.live="subVendorId" label="Subcontractor" placeholder="Who sent it?" clearable />
                    </div>
                </div>
                <div>
                    <flux:text size="sm">Sub's total on this document</flux:text>
                    <flux:heading>{{ data_get($document->document, 'total') !== null ? money(data_get($document->document, 'total')) : '—' }}</flux:heading>
                    @if ($this->subBidsTotal !== null)
                        <flux:text size="sm">Sub's bids on file: {{ money($this->subBidsTotal) }}{{ abs($this->subBidsTotal - (float) data_get($document->document, 'total', 0)) < 0.01 ? ' (matches)' : '' }}</flux:text>
                    @endif
                </div>
                <div>
                    <flux:text size="sm">{{ $applied ? 'Applied to the change order' : 'Ticked for the change order' }}</flux:text>
                    <flux:heading>{{ money($this->totals['net']) }}</flux:heading>
                    <flux:text size="sm">{{ $this->totals['count'] }} {{ Str::plural('change', $this->totals['count']) }} · {{ money($this->totals['adds']) }} added · {{ money($this->totals['credits']) }} credited</flux:text>
                </div>
            </div>

            @if ($applied)
                <flux:callout icon="check-circle" color="green" heading="Applied on {{ $document->applied_at?->timezone('America/Chicago')->format('M j, Y g:i A') }}">
                    <flux:callout.text>{{ count(data_get($document->applied, 'added', [])) }} line items added, {{ count(data_get($document->applied, 'adjusted', [])) }} adjusted and {{ count(data_get($document->applied, 'credited', [])) }} credited on the change order. Rows marked "Not applied" were left unticked.</flux:callout.text>
                </flux:callout>
            @endif

            <div class="overflow-x-auto">
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column class="w-8">
                            @unless ($applied)
                                <flux:dropdown>
                                    <flux:button size="xs" variant="ghost" icon="check" title="Tick all changes" />
                                    <flux:menu>
                                        <flux:menu.item wire:click="setAll(true)">Tick all changes</flux:menu.item>
                                        <flux:menu.item wire:click="setAll(false)">Untick all</flux:menu.item>
                                    </flux:menu>
                                </flux:dropdown>
                            @endunless
                        </flux:table.column>
                        <flux:table.column>Change</flux:table.column>
                        <flux:table.column>Item</flux:table.column>
                        <flux:table.column class="text-right">Estimated</flux:table.column>
                        <flux:table.column class="text-right">Sub lists</flux:table.column>
                        <flux:table.column class="text-right">Qty</flux:table.column>
                        <flux:table.column class="text-right">Our cost</flux:table.column>
                        <flux:table.column class="text-right">Amount</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($rows as $i => $row)
                            @php
                                $applicable = in_array($row['kind'], \App\Livewire\Estimates\EstimateSubDocumentCompare::KINDS_APPLICABLE, true);
                                [$color, $label] = $kindBadge[$row['kind']] ?? ['zinc', $row['kind']];
                                $outcome = $applied ? ($this->appliedOutcomes[$row['key']] ?? null) : null;
                                $leftOut = $applied && $applicable && ! $outcome;
                            @endphp
                            <flux:table.row wire:key="row-{{ $row['key'] }}" data-row-kind="{{ $row['kind'] }}" data-row-outcome="{{ $outcome['outcome'] ?? ($leftOut ? 'not_applied' : '') }}" :class="(! $applied && empty($row['include'])) || $leftOut ? 'opacity-60' : ''">
                                <flux:table.cell class="align-top">
                                    @if ($applicable && ! $applied)
                                        <flux:checkbox wire:model.live="rows.{{ $i }}.include" :disabled="empty($row['line_item_id'])" />
                                    @endif
                                </flux:table.cell>
                                @php
                                    $adjust = ! empty($row['adjust']) && in_array($row['kind'], ['increase', 'decrease', 'missing'], true) && abs((float) $row['cost'] - (float) $row['adjust']['cost']) < 0.005 ? $row['adjust'] : null;
                                    $adjustTo = $adjust ? ($row['kind'] === 'increase' ? $adjust['quantity'] + (float) $row['quantity'] : max(0, $adjust['quantity'] - (float) $row['quantity'])) : null;
                                @endphp
                                <flux:table.cell class="align-top">
                                    @if ($outcome)
                                        <flux:badge size="sm" color="green" icon="check">Applied</flux:badge>
                                        <div class="mt-1 text-xs text-zinc-500 whitespace-nowrap">{{ $outcome['detail'] }}</div>
                                    @elseif ($leftOut)
                                        <flux:badge size="sm" color="zinc">Not applied</flux:badge>
                                        <div class="mt-1 text-xs text-zinc-400 whitespace-nowrap">was proposed: {{ $adjust ? 'adjust' : strtolower($label) }}</div>
                                    @elseif ($adjust)
                                        <flux:badge size="sm" color="sky">Adjust</flux:badge>
                                        <div class="mt-1 text-xs text-zinc-500 whitespace-nowrap" data-adjust>line {{ $adjust['number'] }}: {{ $qty($adjust['quantity']) }} → {{ $qty($adjustTo) }}</div>
                                        @if ($row['kind'] !== 'increase' && (float) $row['quantity'] > $adjust['quantity'] + 0.005)
                                            <div class="text-xs text-orange-600 whitespace-nowrap">+ credit {{ $qty((float) $row['quantity'] - $adjust['quantity']) }}</div>
                                        @endif
                                    @else
                                        <flux:badge size="sm" :color="$color">{{ $label }}</flux:badge>
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell class="align-top !whitespace-normal">
                                    <div class="min-w-56 max-w-md">
                                        @if ($row['kind'] === 'review' && empty($row['line_item_id']) && ! $applied)
                                            <div class="font-medium text-zinc-900 dark:text-zinc-100">{{ $row['name'] }}</div>
                                            <div class="mt-1 flex items-start gap-2">
                                                <flux:select wire:model.live="rows.{{ $i }}.line_item_id" variant="listbox" searchable size="sm" placeholder="Match to a catalog item…" class="flex-1">
                                                    @foreach ($this->catalog as $item)
                                                        <flux:select.option :value="$item->id">{{ $item->name }} — {{ $item->category }}{{ $item->sub_category ? '/'.$item->sub_category : '' }} ({{ money($item->cost) }}/{{ $item->unit_type }})</flux:select.option>
                                                    @endforeach
                                                </flux:select>
                                                @if ($newItemRow !== $i)
                                                    <flux:button size="sm" variant="filled" icon="plus" wire:click="startNewItem({{ $i }})" title="Create a catalog item for this line">New item</flux:button>
                                                @endif
                                            </div>

                                            @if ($newItemRow === $i)
                                                {{-- A new catalog item for the sub's line, optionally from a template. --}}
                                                <div class="mt-2 space-y-3 rounded-lg border border-sky-200 bg-sky-50/50 p-3 dark:border-sky-500/30 dark:bg-sky-500/10" data-new-item-form>
                                                    <div class="flex items-center justify-between gap-2">
                                                        <flux:heading size="sm">New catalog item</flux:heading>
                                                        @if ($row['sub_amount'] !== null || $row['document_quantity'] !== null)
                                                            <flux:text size="sm">Sub charges {{ $row['sub_amount'] !== null ? money($row['sub_amount']) : '' }}{{ $row['document_quantity'] !== null ? ' for '.rtrim(rtrim(number_format((float) $row['document_quantity'], 2, '.', ''), '0'), '.') : '' }}</flux:text>
                                                        @endif
                                                    </div>
                                                    <flux:select wire:model.live="newItem.template_id" variant="listbox" searchable size="sm" label="Start from an existing item (optional)" placeholder="Template…">
                                                        @foreach ($this->catalog as $item)
                                                            <flux:select.option :value="$item->id">{{ $item->name }} — {{ $item->category }}{{ $item->sub_category ? '/'.$item->sub_category : '' }} ({{ money($item->cost) }}/{{ $item->unit_type }})</flux:select.option>
                                                        @endforeach
                                                    </flux:select>
                                                    <flux:input wire:model="newItem.name" size="sm" label="Name" />
                                                    <div class="grid gap-3 sm:grid-cols-2">
                                                        <flux:input wire:model="newItem.category" size="sm" label="Category" />
                                                        <flux:input wire:model="newItem.sub_category" size="sm" label="Sub category" />
                                                        <flux:select wire:model="newItem.unit_type" size="sm" label="Unit type">
                                                            @include('livewire.line-items._unit_type_options')
                                                        </flux:select>
                                                        <flux:input wire:model="newItem.cost" size="sm" type="number" step="0.01" min="0" label="Our cost (what the homeowner pays per unit)" />
                                                    </div>
                                                    <flux:textarea wire:model="newItem.desc" rows="2" label="Description (shown on the estimate)" />
                                                    <div class="flex items-center gap-2">
                                                        <flux:button size="sm" variant="primary" wire:click="saveNewItem">Create and match</flux:button>
                                                        <flux:button size="sm" variant="ghost" wire:click="cancelNewItem">Cancel</flux:button>
                                                    </div>
                                                </div>
                                            @endif
                                        @else
                                            <div class="font-medium text-zinc-900 dark:text-zinc-100">{{ $row['name'] }}</div>
                                            <div class="text-xs text-zinc-500">{{ $row['category'] }}{{ $row['sub_category'] ? '/'.$row['sub_category'] : '' }}@if ($row['estimate_numbers']) · estimate {{ implode(', ', $row['estimate_numbers']) }}@endif</div>
                                        @endif
                                        @if ($row['document_descriptions'])
                                            <div class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Sub's line{{ count($row['document_descriptions']) > 1 ? 's' : '' }}: {{ implode(' · ', $row['document_descriptions']) }}</div>
                                        @endif
                                        @if (! empty($row['learned']))
                                            <flux:badge size="sm" color="sky" icon="academic-cap" class="mt-1" title="Matched from an earlier comparison; it will be again next time.">Remembered</flux:badge>
                                        @endif
                                        @if ($row['note'])
                                            <div class="mt-1 text-xs italic text-amber-700 dark:text-amber-400">{{ $row['note'] }}</div>
                                        @endif
                                    </div>
                                </flux:table.cell>
                                <flux:table.cell class="align-top text-right tabular-nums">{{ $row['unit_type'] === 'no_unit' ? '—' : $qty($row['estimated_quantity']) }}</flux:table.cell>
                                <flux:table.cell class="align-top text-right tabular-nums">
                                    {{ $qty($row['document_quantity']) }}
                                    @if ($row['sub_amount'] !== null)
                                        <div class="text-xs text-zinc-400">{{ money($row['sub_amount']) }}</div>
                                    @endif
                                </flux:table.cell>
                                @if ($applicable && ! $applied)
                                    <flux:table.cell class="align-top text-right"><flux:input wire:model.live.debounce.400ms="rows.{{ $i }}.quantity" type="number" step="0.01" min="0" size="sm" class="w-20 text-right" /></flux:table.cell>
                                    <flux:table.cell class="align-top text-right"><flux:input wire:model.live.debounce.400ms="rows.{{ $i }}.cost" type="number" step="0.01" min="0" size="sm" class="w-24 text-right" /></flux:table.cell>
                                @else
                                    <flux:table.cell class="align-top text-right tabular-nums">{{ $row['quantity'] !== null && $row['kind'] !== 'same' ? $qty($row['quantity']) : '' }}</flux:table.cell>
                                    <flux:table.cell class="align-top text-right tabular-nums">{{ $row['cost'] ? money($row['cost']) : '' }}</flux:table.cell>
                                @endif
                                <flux:table.cell class="align-top text-right font-medium tabular-nums {{ (($outcome['amount'] ?? $row['amount']) ?? 0) < 0 ? 'text-orange-600' : '' }}">
                                    @if ($outcome)
                                        {{ money($outcome['amount']) }}
                                    @elseif ($leftOut)
                                        <span class="font-normal text-zinc-400 line-through">{{ $row['amount'] !== null ? money($row['amount']) : '' }}</span>
                                    @else
                                        {{ $row['amount'] !== null && $row['kind'] !== 'same' ? money($row['amount']) : '' }}
                                    @endif
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </div>

            @if ($error)
                <flux:text size="sm" class="text-red-600 dark:text-red-400">{{ $error }}</flux:text>
            @endif

            <div class="flex flex-wrap items-center justify-between gap-2">
                <flux:button variant="ghost" icon="arrow-uturn-left" wire:click="startOver">Compare another document</flux:button>
                <div class="flex items-center gap-2">
                    <flux:button variant="ghost" x-on:click="$flux.modal('estimate-sub-document-modal').close()">Close</flux:button>
                    @unless ($applied)
                        <flux:button variant="primary" icon="plus" wire:click="apply" wire:loading.attr="disabled" wire:target="apply">
                            Add {{ $this->totals['count'] }} {{ Str::plural('change', $this->totals['count']) }} to the change order
                        </flux:button>
                    @endunless
                </div>
            </div>
        @endif
    </div>
</flux:modal>
</div>
