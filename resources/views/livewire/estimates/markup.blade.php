{{-- One markup on every line item of the estimate (App\Livewire\Estimates\EstimateMarkup). --}}
<div>
    <flux:modal name="estimate-markup-modal" class="w-full max-w-md">
        <form wire:submit="apply" class="space-y-5">
            <div>
                <flux:heading size="lg">{{ $allSections || $sectionName === '' ? 'Mark up every section' : 'Mark up '.$sectionName }}</flux:heading>
                <flux:text class="mt-1">Raises every line item's price by about this much: whole-dollar prices stay whole, other prices round to the nearest quarter. Use a negative number to mark down.</flux:text>
            </div>

            <flux:field>
                <flux:label>Markup</flux:label>
                <flux:input.group>
                    <flux:input wire:model.live.debounce.300ms="markupPercent" type="number" step="0.01" inputmode="decimal" placeholder="10" autofocus />
                    <flux:input.group.suffix>%</flux:input.group.suffix>
                </flux:input.group>
                <flux:error name="markupPercent" />
            </flux:field>

            @if ($sectionId)
                <flux:switch wire:model.live="allSections" label="Every section on this estimate" />
            @endif

            @if ($this->preview)
                <div class="rounded-lg border border-zinc-200 p-3 text-sm dark:border-white/10" data-markup-preview>
                    <div class="flex justify-between"><span class="text-zinc-500">Now</span><span>{{ money($this->preview['before']) }}</span></div>
                    <div class="flex justify-between font-medium"><span>After markup</span><span>{{ money($this->preview['after']) }}</span></div>
                    @php
                        $previewChange = $this->preview['after'] - $this->preview['before'];
                        $previewPercent = $this->preview['before'] != 0 ? $previewChange / abs($this->preview['before']) * 100 : 0;
                    @endphp
                    <div class="mt-1 text-zinc-500">{{ $this->preview['lines'] }} {{ \Illuminate\Support\Str::plural('line item', $this->preview['lines']) }}, {{ $previewChange >= 0 ? '+' : '−' }}{{ money(abs($previewChange)) }} (about {{ number_format(abs($previewPercent), 1) }}%)</div>
                </div>
            @endif

            @if ($this->lastMarkup)
                <div class="flex items-center justify-between gap-3 border-t border-zinc-200 pt-4 text-sm dark:border-white/10" data-markup-last>
                    <div class="min-w-0">
                        <div class="font-medium">Last markup: {{ $this->lastMarkup['percent'] > 0 ? '+' : '' }}{{ rtrim(rtrim(number_format($this->lastMarkup['percent'], 2), '0'), '.') }}% on {{ $this->lastMarkup['scope'] }}</div>
                        <div class="text-zinc-500">{{ \Illuminate\Support\Carbon::parse($this->lastMarkup['at'])->timezone('America/Chicago')->format('M j, g:i A') }} · {{ money($this->lastMarkup['before']) }} → {{ money($this->lastMarkup['after']) }}</div>
                    </div>
                    <flux:button size="sm" variant="ghost" icon="arrow-uturn-left" wire:click="revert" wire:confirm="Put these line items back to their prices before this markup?" wire:loading.attr="disabled" wire:target="revert">Revert</flux:button>
                </div>
            @endif

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="apply">Apply markup</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
