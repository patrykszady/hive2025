<div class="max-w-lg space-y-4">
    {{-- class="!print:hidden" --}}
    <x-details.card title="Audit" :accordion="false">
        <x-slot:header_buttons>
            <flux:button.group>
                {{-- Downloads wait until EWCCV has answered for every sub; the
                     package is emailed the moment it has (SendAuditWhenReady). --}}
                <flux:button
                    wire:click="download_documents"
                    size="sm"
                    :disabled="! $this->canDownload"
                >
                    Download Certificates
                </flux:button>
                <flux:dropdown position="bottom" align="end">
                    <flux:button icon-trailing="chevron-down" size="sm" :disabled="! $this->canDownload"></flux:button>

                    <flux:menu>
                        <flux:menu.item
                            wire:click="export_xlsx"
                            size="sm"
                        >
                            Export Excel
                        </flux:menu.item>
                        <flux:menu.item
                            wire:click="download_bank_statements"
                            size="sm"
                        >
                            Download Bank Statements
                        </flux:menu.item>
                    </flux:menu>
                </flux:dropdown>
            </flux:button.group>
        </x-slot:header_buttons>

        {{-- A workers comp audit also asks EWCCV whether each paid sub was
             covered on the audit's end date (ewccv_coverage_checks); the
             tracking badge on each policy only says the policy is in force
             today. --}}
        @if($audit_type === 'workers' && $end_date)
            <x-slot name="details">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="text-sm space-y-1">
                        <div class="font-medium">EWCCV coverage as of {{ $this->coverageDate?->format('m/d/Y') }} ({{ \App\Models\AuditRequest::coverageDateIsClamped($end_date) ? 'the period has not ended; checked as of today' : 'two days before the period ends' }})</div>
                        @if($this->coverage_check_counts['total'] === 0)
                            <div class="text-zinc-500">no paid sub has a workers comp policy on file for this period</div>
                        @elseif($this->coverage_check_counts['covered'] + $this->coverage_check_counts['not_found'] + $this->coverage_check_counts['pending'] === 0)
                            <div class="text-zinc-500">not checked yet ({{ $this->coverage_check_counts['total'] }} policies)</div>
                        @else
                            <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                <span class="text-emerald-700 dark:text-emerald-400">{{ $this->coverage_check_counts['covered'] }} covered</span>
                                <span class="text-zinc-400">·</span>
                                @if($this->coverage_check_counts['not_found'] > 0)
                                    {{-- The ones to question: a click lists them with what EWCCV was asked. --}}
                                    <flux:modal.trigger name="audit-not-found">
                                        <flux:button size="xs" variant="ghost" class="!px-1.5 !text-red-600 dark:!text-red-400" icon="magnifying-glass">{{ $this->coverage_check_counts['not_found'] }} not found</flux:button>
                                    </flux:modal.trigger>
                                @else
                                    <span class="text-zinc-500">0 not found</span>
                                @endif
                                <span class="text-zinc-400">·</span>
                                {{-- Unanswered checks are not waited on: the certificate on file stands. --}}
                                <span class="text-zinc-500">{{ $this->coverage_check_counts['pending'] + $this->coverage_check_counts['unchecked'] }} not checked</span>
                            </div>
                        @endif
                    </div>
                    <flux:button wire:click="verify_coverage_on_ewccv" size="sm" icon="shield-check">
                        Check EWCCV as of {{ $this->coverageDate?->format('m/d/Y') }}
                    </flux:button>
                </div>
                @if($this->auditRequest)
                    <div class="mt-2 text-sm text-zinc-600 dark:text-zinc-300">
                        @if($this->auditRequest->status === 'sent')
                            Package emailed to {{ $this->auditRequest->emailed_to }} on {{ $this->auditRequest->emailed_at?->timezone(auth()->user()->vendor->timezone ?: 'America/Chicago')->format('m/d/Y g:i A') }}.
                        @elseif($this->auditRequest->status === 'ready')
                            Every answer is in; the downloads are open.
                        @elseif($this->auditRequest->status === 'failed')
                            The package could not be built: {{ $this->auditRequest->error }}
                        @else
                            The package (certificates PDF + Excel) is being built and is emailed to you in a few minutes.
                        @endif
                    </div>
                @endif
            </x-slot>
        @endif
    </x-details.card>

    {{-- TRANSACTIONS NO CHECKS --}}
    <x-details.card title="Transactions" :expanded="false" details_text="Missing Expenses">
        <x-slot name="subheading">
            <span class="text-sm italic">
                These Check Transactions have not beed added to Vendors or Projects. Please add checks before comleting this Audit.
            </span>
        </x-slot>

        <x-slot name="details">
            <flux:table class="w-full">
                <flux:table.columns>
                    <flux:table.column 
                        sortable 
                        :sorted="true" 
                        {{-- :direction="'asc'" --}}
                        class="w-1/4"
                    >
                        Date
                    </flux:table.column>
                    <flux:table.column>Payment</flux:table.column>
                    <flux:table.column>Amount</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach($this->transactions_no_check as $transaction)
                        <flux:table.row :key="$transaction->id" class="border-b-0">
                            <flux:table.cell class="pb-0">
                                {{ $transaction->transaction_date->format('m/d/Y') }}
                            </flux:table.cell>
                            <flux:table.cell class="pb-0">
                                {{ $transaction->check_number == '1010101' ? 'Transfer' : ($transaction->check_number == '2020202' ? 'Cash' : $transaction->check_number) }}
                            </flux:table.cell>
                            <flux:table.cell class="pb-0">
                                <flux:button
                                    variant="ghost"
                                    size="sm"
                                    wire:click="$dispatchTo('expenses.expense-create', 'createExpenseFromTransaction', { transaction: {{ $transaction->id }} })"
                                >
                                    {{ money($transaction->amount) }}
                                </flux:button>
                            </flux:table.cell>
                        </flux:table.row>
                        @if(!empty($transaction->plaid_merchant_description))
                            <flux:table.row :key="'desc-' . $transaction->id" class="border-t-0">
                                <flux:table.cell class="pt-0"></flux:table.cell>
                                <flux:table.cell class="pt-0 border-t border-gray-200" colspan="2">
                                    <div class="italic whitespace-normal break-words leading-tight">
                                        {{ $transaction->plaid_merchant_description }}
                                    </div>
                                </flux:table.cell>
                            </flux:table.row>
                        @endif
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </x-slot>
    </x-details.card>

    {{-- VENDOR CHECKS --}}
    @foreach($this->vendors_grouped_checks as $group)
        <x-details.card 
            :title="$group['vendor']->business_name"
            :title_href="($group['is_user'] ?? false) ? route('users.show', $group['user']->id) : route('vendors.show', $group['vendor']->id)"
            :accordion="false"
        >
            <x-slot:title_extras>
                @if(!empty($group['vendor']->business_type))
                    <flux:badge size="sm" color="zinc" inset="top bottom">{{ $group['vendor']->business_type }}</flux:badge>
                @endif
            </x-slot:title_extras>

            <x-slot name="subheading">
                <span class="text-sm italic">
                    @if(($group['is_user'] ?? false) === true)
                        @php($role = $group['user']?->getRoleForVendor(auth()->user()->vendor->id) ?? 'No Role')
                        @if($role === 'Admin')
                            Stakeholder in the business and is excluded.
                        @else
                            Employee payment subject to audit review.
                        @endif
                    @elseif(!empty($group['vendor']->category_id))
                        Vendor is {{ $group['vendor']->category?->friendly_detailed }} and doesn't require coverage.
                    @elseif($group['vendor']->business_type == 'Retail')
                        Retail and doesn't require coverage.
                    @elseif(isset($group['professional_doc']))
                        Professional policy active ({{ $group['professional_doc']->effective_date->format('m/d/Y') }}–{{ $group['professional_doc']->expiration_date->format('m/d/Y') }})
                    @endif
                </span>

                @if(isset($group['applicable_docs']) && ($group['applicable_docs']?->count() ?? 0) > 0)
                    <div class="space-y-2">
                        <flux:heading size="sm">Policies</flux:heading>
                        <div class="rounded-lg border border-zinc-200 dark:border-white/20 divide-y divide-zinc-200 dark:divide-white/10 overflow-hidden">
                            @foreach($group['applicable_docs'] as $doc)
                                <div class="p-3 flex items-center justify-between">
                                    <div class="space-y-0.5">
                                        <div class="text-sm font-medium">{{ ucfirst($doc->type) }} #{{ $doc->number }}</div>
                                        <div class="text-xs text-zinc-600 dark:text-zinc-300 flex flex-wrap items-center gap-x-2 gap-y-0.5">
                                            <span>{{ $doc->effective_date->format('m/d/Y') }} – {{ $doc->expiration_date->format('m/d/Y') }}</span>
                                            {{-- EWCCV's answer for this policy's own day in the audit
                                                 (the checked date, or the policy's last day); the vendor
                                                 docs page's verification only when the audit has none. --}}
                                            @if(($doc->audit_check ?? null) && $doc->audit_check->status === 'covered')
                                                <flux:tooltip :content="$doc->audit_check->label()" position="top">
                                                    <span class="inline-flex items-center gap-1 text-emerald-700 dark:text-emerald-400">
                                                        <flux:icon.shield-check variant="micro" class="size-3.5 shrink-0" />
                                                        WC verified {{ $doc->audit_check->coverage_date->format('m/d/Y') }}
                                                    </span>
                                                </flux:tooltip>
                                            @elseif(($doc->audit_check ?? null) && $doc->audit_check->status === 'not_found')
                                                <flux:tooltip :content="$doc->audit_check->label()" position="top">
                                                    <span class="inline-flex items-center gap-1 text-red-600 dark:text-red-400">
                                                        <flux:icon.shield-exclamation variant="micro" class="size-3.5 shrink-0" />
                                                        not found on EWCCV for {{ $doc->audit_check->coverage_date->format('m/d/Y') }}
                                                    </span>
                                                </flux:tooltip>
                                            @elseif($doc->audit_check ?? null)
                                                <flux:tooltip :content="$doc->audit_check->label()" position="top">
                                                    <span class="inline-flex items-center gap-1 text-amber-600 dark:text-amber-400">
                                                        <flux:icon.shield-exclamation variant="micro" class="size-3.5 shrink-0" />
                                                        not checked on EWCCV as of {{ $doc->audit_check->coverage_date->format('m/d/Y') }}
                                                    </span>
                                                </flux:tooltip>
                                            @elseif($doc->audit_check_date ?? null)
                                                <span class="inline-flex items-center gap-1 text-zinc-500">
                                                    <flux:icon.shield-exclamation variant="micro" class="size-3.5 shrink-0" />
                                                    not checked yet as of {{ $doc->audit_check_date->format('m/d/Y') }}
                                                </span>
                                            @elseif($doc->verification_summary && $doc->verification_summary['verified_at'])
                                                <flux:tooltip :content="$doc->verification_summary['tip']" position="top">
                                                    <span class="inline-flex items-center gap-1 text-emerald-700 dark:text-emerald-400">
                                                        <flux:icon.shield-check variant="micro" class="size-3.5 shrink-0" />
                                                        WC verified {{ $doc->verification_summary['verified_at'] }}
                                                    </span>
                                                </flux:tooltip>
                                            @elseif($doc->type === 'workers')
                                                <span class="inline-flex items-center gap-1 text-amber-600 dark:text-amber-400">
                                                    <flux:icon.shield-exclamation variant="micro" class="size-3.5 shrink-0" />
                                                    not verified
                                                </span>
                                            @endif
                                        </div>
                                    </div>
                                    @if(!empty($doc->doc_filename))
                                        <flux:link href="{{ route('vendor_docs.show', $doc->doc_filename) }}" external variant="subtle"><flux:icon.eye variant="mini" /> </flux:link>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

            </x-slot>

            <x-slot name="details">
                <flux:table class="w-full">
                    <flux:table.columns>
                        <flux:table.column 
                            sortable 
                            :sorted="true" 
                            {{-- :direction="!($group['is_user'] ?? false) ? ($this->vendorSortDir[$group['vendor']->id] ?? 'asc') : 'asc'" --}}
                        >
                            Date
                        </flux:table.column>
                        <flux:table.column>Payment</flux:table.column>
                        <flux:table.column>Amount</flux:table.column>
                        <flux:table.column>Coverage</flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @foreach($group['checks'] as $check)
                            <flux:table.row :key="$check->id">
                                <flux:table.cell>{{ $check->date->format('m/d/Y') }}</flux:table.cell>
                                <flux:table.cell>
                                    <flux:link href="{{ route('checks.show', $check->id) }}" external variant="ghost" :accent="false" class="!text-gray-500 !font-normal">
                                        {{ $check->payment_label . ' ' . $check->check_number }}
                                    </flux:link>
                                </flux:table.cell>
                                <flux:table.cell>{{ money($check->amount) }}</flux:table.cell>
                                <flux:table.cell>
                                    @if(($group['is_user'] ?? false) === true)
                                        @php($role = $group['user']?->getRoleForVendor(auth()->user()->vendor->id) ?? 'No Role')
                                        @if($role === 'Admin')
                                            <flux:badge size="sm" color="yellow" inset="top bottom">Not Applicable</flux:badge>
                                        @else
                                            <flux:badge size="sm" color="red" inset="top bottom">Not Covered</flux:badge>
                                        @endif
                                    @else
                                        <flux:badge size="sm" :color="$check->covered ? 'green' : ((isset($group['professional_doc']) || $group['vendor']->business_type == 'Retail' || !empty($group['vendor']->category_id)) ? 'yellow' : 'red')" inset="top bottom">
                                            {{ $check->covered ? 'Covered' : ((isset($group['professional_doc']) || $group['vendor']->business_type == 'Retail' || !empty($group['vendor']->category_id)) ? 'Not Applicable' : 'Not Covered') }}
                                        </flux:badge>
                                    @endif
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </x-slot>
        </x-details.card>
    @endforeach
    <livewire:expenses.expense-create />

    {{-- The policies EWCCV did not find for their day, with what it was asked. --}}
    <flux:modal name="audit-not-found" class="md:w-[46rem]">
        <div class="space-y-4">
            <div>
                <flux:heading size="lg">Not found on EWCCV</flux:heading>
                <flux:text class="mt-1">Policies EWCCV did not list for the day asked about. Check the certificate's policy number and the business name, then press "Check EWCCV" to ask again.</flux:text>
            </div>
            @forelse($this->not_found_policies as $row)
                <div class="space-y-1 border-t border-zinc-200 dark:border-white/10 pt-3" wire:key="not-found-{{ $row['vendor_id'] }}-{{ $row['number'] }}">
                    <div class="flex flex-wrap items-center gap-2 text-sm">
                        <flux:link :href="route('vendors.show', $row['vendor_id'])" class="font-medium">{{ $row['vendor'] }}</flux:link>
                        <span class="text-zinc-600 dark:text-zinc-300">policy {{ $row['number'] }} · {{ $row['term'] }}</span>
                    </div>
                    <div class="text-xs text-red-600 dark:text-red-400">Not found for {{ $row['as_of'] }} ({{ $row['reason'] }})</div>
                    @if($row['searched'] !== [])
                        <div class="text-xs text-zinc-600 dark:text-zinc-300">Searched: {{ implode(' · ', $row['searched']) }}</div>
                    @endif
                    @if($row['candidates'] !== [])
                        <div class="text-xs text-zinc-600 dark:text-zinc-300">EWCCV listed instead: {{ implode(' · ', $row['candidates']) }}</div>
                    @endif
                </div>
            @empty
                <flux:text>Every policy was found.</flux:text>
            @endforelse
        </div>
    </flux:modal>
</div>


