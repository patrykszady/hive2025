{{-- The estimates and invoices this sub sent, compared on our estimates
     (App\Livewire\Vendors\VendorSubDocuments). --}}
<div>
    @if ($this->documents->isNotEmpty())
        <x-island-card heading="Estimates & invoices from this sub" subheading="Every line they sent us, their price, and what it is in our catalog.">
            <div class="divide-y divide-zinc-100 dark:divide-white/5" data-vendor-sub-documents>
                @foreach ($this->documents as $document)
                    <details wire:key="vendor-sub-document-{{ $document->id }}" class="group py-2">
                        <summary class="flex cursor-pointer flex-wrap items-center justify-between gap-2 list-none">
                            <div class="min-w-0">
                                <div class="truncate text-sm font-medium text-zinc-900 dark:text-zinc-100">{{ $document->label() }}</div>
                                <div class="text-xs text-zinc-500">
                                    @if ($document->estimate?->project)
                                        <a href="{{ route('estimates.show', $document->estimate_id) }}" wire:navigate class="underline decoration-dotted underline-offset-2 hover:text-zinc-700">{{ $document->estimate->project->project_name }} — {{ $document->estimate->project->address }}</a> ·
                                    @endif
                                    {{ $document->items->count() }} {{ Str::plural('line', $document->items->count()) }} · compared {{ $document->created_at->timezone('America/Chicago')->format('M j, Y') }}
                                </div>
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="text-sm font-semibold tabular-nums">{{ data_get($document->document, 'total') !== null ? money(data_get($document->document, 'total')) : '' }}</span>
                                @if ($document->status === \App\Models\EstimateSubDocument::STATUS_APPLIED)
                                    <flux:badge size="sm" color="green">In change order</flux:badge>
                                @else
                                    <flux:badge size="sm" color="zinc">Compared</flux:badge>
                                @endif
                                <flux:icon.chevron-down variant="micro" class="size-4 text-zinc-400 transition group-open:rotate-180" />
                            </div>
                        </summary>
                        <div class="mt-2 overflow-x-auto">
                            <table class="w-full text-xs">
                                <thead class="text-left text-zinc-500">
                                    <tr><th class="py-1 pr-2 font-medium">Their line</th><th class="py-1 pr-2 text-right font-medium">Qty</th><th class="py-1 pr-2 text-right font-medium">Their price</th><th class="py-1 pr-2 text-right font-medium">Amount</th><th class="py-1 font-medium">Our item</th></tr>
                                </thead>
                                <tbody class="divide-y divide-zinc-50 dark:divide-white/5">
                                    @foreach ($document->items as $item)
                                        <tr wire:key="vendor-sub-document-item-{{ $item->id }}" @class(['text-zinc-700 dark:text-zinc-300', 'font-medium' => $item->applied])>
                                            <td class="py-1 pr-2">{{ $item->description }}</td>
                                            <td class="py-1 pr-2 text-right tabular-nums">{{ $item->quantity !== null ? rtrim(rtrim(number_format($item->quantity, 2, '.', ''), '0'), '.') : '' }}{{ $item->unit ? ' '.$item->unit : '' }}</td>
                                            <td class="py-1 pr-2 text-right tabular-nums">{{ $item->unit_price !== null ? money($item->unit_price) : '' }}</td>
                                            <td class="py-1 pr-2 text-right tabular-nums">{{ $item->amount !== null ? money($item->amount) : '' }}</td>
                                            <td class="py-1 text-zinc-500">{{ $item->lineItem?->name ?? '—' }}@if ($item->applied) <span class="text-green-600">· in change order</span>@endif</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </details>
                @endforeach
            </div>
        </x-island-card>
    @endif
</div>
