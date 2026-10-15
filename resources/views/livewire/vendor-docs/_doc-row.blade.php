{{-- One document row of the vendor docs card: current and previous tables share it. --}}
<flux:table.row :key="$doc->id">
    <flux:table.cell variant="strong" class="w-[28%] min-w-0">
        <x-truncate-tooltip :content="$doc->type_label">
        <a
            href="{{ route('expenses.original_receipt', ['vendor_docs', $doc->doc_filename]) }}"
            target="_blank"
            class="block truncate"
        >
            {{$doc->type_label}}
        </a>
        </x-truncate-tooltip>
    </flux:table.cell>
    <flux:table.cell class="w-[22%]">
        <flux:badge size="sm" :color="$doc->expiration_date > today() ? 'green' : 'red'" inset="top bottom">
            {{$doc->expiration_date->format('m/d/y')}}
        </flux:badge>
    </flux:table.cell>
    <flux:table.cell class="w-[26%] min-w-0">
        <x-truncate-tooltip :content="(string) $doc->number"><div class="truncate">{{ $doc->number }}</div></x-truncate-tooltip>
    </flux:table.cell>
    {{-- Coverage verification: the date a provider last
         confirmed this policy. EWCCV reports the carrier's
         filing with the state rating bureau; TrustLayer
         reports the certificate the sub's agent provided —
         either counts, and the tooltip names both.
         Logic lives on the model ($doc->verification_summary)
         because @php blocks miscompile in Blaze slots. --}}
    <flux:table.cell class="w-[24%] min-w-0">
        @if($doc->verification_summary)
            <flux:tooltip :content="$doc->verification_summary['tip']" position="top">
                <div class="flex items-center gap-1 min-w-0">
                    @if($doc->verification_summary['verified_at'])
                        <flux:icon.shield-check variant="micro" class="size-3.5 shrink-0 text-emerald-500" />
                        <span class="truncate tabular-nums">{{ $doc->verification_summary['verified_at_short'] }}</span>
                    @elseif(! empty($doc->verification_summary['alert']))
                        {{-- EWCCV or the insurer reported the coverage cancelled, cancelling or not renewed. --}}
                        <flux:icon.shield-exclamation variant="micro" class="size-3.5 shrink-0 text-red-600" />
                        <span class="text-red-600 dark:text-red-400">{{ $doc->verification_summary['alert_label'] ?? 'cancelled' }}</span>
                    @else
                        <flux:icon.shield-exclamation variant="micro" class="size-3.5 shrink-0 text-amber-500" />
                    @endif
                </div>
            </flux:tooltip>
        @endif
    </flux:table.cell>
</flux:table.row>
