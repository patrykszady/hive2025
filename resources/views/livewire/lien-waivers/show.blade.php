<div class="max-w-4xl py-8 space-y-6" wire:transition>
    @php($waiver = $this->waiver)

    <div class="flex items-start justify-between">
        <div>
            <flux:heading size="xl">{{ $waiver->typeLabel() }}</flux:heading>
            <p class="text-sm text-zinc-500">
                Project:
                @if($waiver->project)
                    <a wire:navigate.hover href="{{ route('projects.show', $waiver->project) }}" class="font-semibold text-zinc-700 no-underline hover:no-underline hover:text-indigo-600 dark:text-zinc-300 dark:hover:text-indigo-400">
                        {{ $waiver->project->project_name ?? '—' }}
                        @if($waiver->project->address)
                            &middot; {{ $waiver->project->address }}
                        @endif
                    </a>
                @else
                    <strong>—</strong>
                @endif
            </p>
        </div>
        <flux:badge :color="$waiver->status?->color() ?? 'zinc'">
            {{ $waiver->status?->label() }}
        </flux:badge>
    </div>

    <x-details.card title="Waiver Details">
        <x-slot:details>
            @php($payerOverrideName = data_get(json_decode((string) $waiver->notes, true), 'payer.name'))
            <x-details.row 
                title="{{ $payerOverrideName ? 'Property Owner (Payer)' : 'Contractor (Payer)' }}"
                :content="$payerOverrideName ?: ($waiver->payerVendor?->business_name ?? '—')"
            />

            @php($payerOverrideAddress = data_get(json_decode((string) $waiver->notes, true), 'payer.address'))
            @php($payerOverrideCityStateZip = data_get(json_decode((string) $waiver->notes, true), 'payer.city_state_zip'))
            @php($payerAddressLine1 = $payerOverrideName ? trim((string) $payerOverrideAddress) : trim(implode(', ', array_filter([$waiver->payerVendor?->address]))) )
            @php($payerAddressLine2 = $payerOverrideName ? trim((string) $payerOverrideCityStateZip) : trim(implode(', ', array_filter([$waiver->payerVendor?->city, trim(implode(' ', array_filter([$waiver->payerVendor?->state, $waiver->payerVendor?->zip_code])))]))))
            <x-details.row title="Payer Address">
                <div>{{ $payerAddressLine1 !== '' ? $payerAddressLine1 : '—' }}</div>
                <div class="text-zinc-500 dark:text-zinc-400 text-sm">{{ $payerAddressLine2 !== '' ? $payerAddressLine2 : '—' }}</div>
            </x-details.row>

            <x-details.row 
                title="Owner / Customer"
                :content="$waiver->project?->client?->name ?? '—'"
            />

            @php($projectAddressLine1 = trim(implode(', ', array_filter([$waiver->project?->address, $waiver->project?->address_2]))))
            @php($projectAddressLine2 = trim(implode(', ', array_filter([$waiver->project?->city, trim(implode(' ', array_filter([$waiver->project?->state, $waiver->project?->zip_code])))]))))
            <x-details.row title="Project Address">
                <div>{{ $projectAddressLine1 !== '' ? $projectAddressLine1 : '—' }}</div>
                <div class="text-zinc-500 dark:text-zinc-400 text-sm">{{ $projectAddressLine2 !== '' ? $projectAddressLine2 : '—' }}</div>
            </x-details.row>

            @php($estimateNumbers = $waiver->project?->estimates?->pluck('number')->filter()->values() ?? collect())
            <x-details.row 
                title="Estimate Number(s)"
                :content="$estimateNumbers->isNotEmpty()
                    ? (($waiver->project?->project_name ? $waiver->project->project_name . ' — ' : '') . '#' . $estimateNumbers->implode(', #'))
                    : ($waiver->project?->project_name ?? '—')"
            />

            <x-details.row 
                title="Vendor (Claimant)"
                :content="$waiver->vendor?->business_name ?? '—'"
            />

            @php($claimantHasAddress = trim((string) $waiver->vendor?->address) !== '' || trim((string) $waiver->vendor?->city) !== '' || trim((string) $waiver->vendor?->zip_code) !== '')
            @php($claimantAddressLine1 = $claimantHasAddress ? trim((string) $waiver->vendor?->address) : '')
            @php($claimantAddressLine2 = $claimantHasAddress ? trim(implode(', ', array_filter([$waiver->vendor?->city, trim(implode(' ', array_filter([$waiver->vendor?->state, $waiver->vendor?->zip_code])))]))) : '')
            <x-details.row title="Claimant Address">
                <div>{{ $claimantAddressLine1 }}</div>
                <div class="text-zinc-500 dark:text-zinc-400 text-sm">{{ $claimantAddressLine2 }}</div>
            </x-details.row>

            <x-details.row title="Payment Amount">
                @if($waiver->type === \App\Enums\LienWaiverType::UnconditionalFinal)
                    PAID IN FULL
                @elseif($waiver->amount !== null)
                    ${{ number_format((float) $waiver->amount, 2) }}
                @elseif($waiver->payment?->amount !== null)
                    ${{ number_format((float) $waiver->payment->amount, 2) }}
                @else
                    —
                @endif
            </x-details.row>

            <x-details.row 
                title="Through Date"
                :content="optional($waiver->through_date)->format('F j, Y')"
            />

            @if((float) $waiver->exceptions_amount > 0)
                <x-details.row 
                    title="Exceptions / Disputed Amount"
                    :content="'$' . number_format((float) $waiver->exceptions_amount, 2)"
                />
            @endif

            @if($waiver->check)
                <x-details.row title="Check / Reference">
                    {{ $waiver->check->check_type }}
                    @if($waiver->check->check_number) #{{ $waiver->check->check_number }} @endif
                    @if($waiver->check->date)
                        · {{ optional($waiver->check->date)->format('M j, Y') }}
                    @endif
                </x-details.row>
            @endif

            <x-details.row
                title="Jurisdiction"
                :content="$waiver->jurisdiction"
            />
        </x-slot:details>

        <x-slot:header_buttons>
            <x-download-button size="sm" variant="filled" :href="route('lien-waivers.download', $waiver)" label="Download PDF" />
        </x-slot:header_buttons>
    </x-details.card>

</div>
