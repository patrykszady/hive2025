@php
    $isProjectScoped = $this->scoped;
    // Project-scoped = embedded in a show-page column: inherit that column's
    // width and rhythm instead of imposing this page's own.
@endphp
{{-- Embedded: NO spacing utility. This root also holds modal hosts after the
     card, and space-y-* would give those zero-height elements a margin that
     shows up as an extra gap under the card. --}}
<div class="{{ $isProjectScoped ? '' : 'max-w-3xl space-y-2' }}" wire:transition>

    {{-- STANDALONE PAGE (NON-PROJECT SCOPED) --}}
    @if(!$isProjectScoped)
        <x-filter-card>
            <x-slot:actions>
                <flux:button size="sm" variant="primary" icon="plus" wire:click="openProjectSelector">
                    Create Waiver
                </flux:button>
            </x-slot:actions>
            {{-- single copy: the inline layout stacks below sm on its own --}}
            @include('livewire.lien-waivers.partials.filter-fields', ['layout' => 'inline'])
        </x-filter-card>

        {{-- MAIN TABLE CARD — standalone flat table (the project-scoped
             accordion variant stays in _table.blade.php). Lazy island: the card
             paints from the shared skeleton first, then the real table swaps
             in — same loading treatment as the Leads/Checks cards. --}}
        @island(name: 'lien-waivers-table', lazy: island_lazy(), always: true)
            @placeholder
                <x-index-table.placeholder
                    heading="Lien Waivers"
                    :columns="\App\Livewire\LienWaivers\Index::columnDefs()"
                    :rows="\App\Livewire\LienWaivers\Index::placeholderRows()"
                    :page-size="\App\Livewire\LienWaivers\Index::placeholderRows()"
                    :compact="false"
                />
            @endplaceholder
        <x-index-table heading="Lien Waivers" :paginator="$this->waivers">
            {{-- Islands render in their own scope (public properties only), so the
                 row partials get their own copy of the outer flag — always false
                 here, since this branch IS the standalone page. --}}
            @php($isProjectScoped = $this->scoped)
            {{-- Header labels AND widths come from columnDefs(), same as the
                 project card, so the two variants can't drift. --}}
            @php($columns = \App\Livewire\LienWaivers\Index::columnDefs())
            @if($this->waivers->isNotEmpty() || $this->swornStatements->isNotEmpty())
                <flux:table class="index-table [:where(&)]:p-0 [:where(&)]:space-y-0">
                    <flux:table.columns>
                        @foreach($columns as $column)
                            <flux:table.column class="{{ $column['width'] }}">{{ $column['label'] }}</flux:table.column>
                        @endforeach
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach($this->swornStatements as $statement)
                            @include('livewire.lien-waivers._statement-row', ['statement' => $statement, 'columns' => $columns])
                        @endforeach
                        @foreach($this->waivers as $waiver)
                            @include('livewire.lien-waivers._waiver-row', ['waiver' => $waiver, 'columns' => $columns])
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            @endif
        </x-index-table>
        @endisland
    @elseif($this->hasSignedContract)
        {{-- PROJECT-SCOPED VARIANT — hidden until the contract is signed
             (the base bid created at estimate acceptance). --}}
        {{-- No waivers yet = no accordion, same as every other card: a toggle
             that only opens onto an empty body still changed the card height.
             (x-details.card drops the chevron and the clickable header when
             :accordion is false.) --}}
        @php($hasWaivers = $this->drawGroups['draws']->isNotEmpty() || $this->drawGroups['other']->isNotEmpty())
        {{-- Expanded on load when there's something to show: the draws and their
             waivers are the point of the card. --}}
        <x-details.card title="Lien Waivers" :expanded="$hasWaivers" :details_text="false" :separator="false" :accordion="$hasWaivers">
            <x-slot:header_buttons>
                <flux:button size="sm" variant="primary" icon="document-text" wire:click="openSwornStatement"
                    class="!bg-indigo-500 hover:!bg-indigo-600 !text-white">
                    Create Draw
                </flux:button>
            </x-slot:header_buttons>
            <x-slot:details>
                @include('livewire.lien-waivers._table')
            </x-slot:details>
        </x-details.card>
    @endif

    {{-- DELETE DRAW CONFIRMATION --}}
    <flux:modal wire:model.self="showDrawDelete" name="draw-delete-confirm" class="max-w-md">
        @if($this->deletingDraw)
            @php($draw = $this->deletingDraw)
            <div class="space-y-4">
                <flux:heading size="lg">Delete Draw {{ $draw['number'] }}?</flux:heading>

                <flux:text>This removes the whole draw from this project:</flux:text>

                <ul class="list-disc pl-5 space-y-1 text-sm text-zinc-600 dark:text-zinc-300">
                    <li>The sworn statement (GCSS) and its download links.</li>
                    <li>
                        All {{ $draw['total'] }} {{ Str::plural('waiver', $draw['total']) }} created with this draw
                        @if($draw['sent'] > 0 || $draw['signed'] > 0)
                            — including
                            {{ collect([
                                $draw['sent'] > 0 ? $draw['sent'] . ' already emailed to vendors' : null,
                                $draw['signed'] > 0 ? $draw['signed'] . ' already signed & notarized' : null,
                            ])->filter()->implode(' and ') }}.
                        @else
                            .
                        @endif
                    </li>
                    <li>Vendors are <strong>not</strong> notified — but copies they already received will no longer match anything when scanned back.</li>
                    <li>Nothing is destroyed: records are recoverable and stored PDFs stay on file.</li>
                </ul>

                <div class="flex justify-end gap-2 pt-2">
                    <flux:button variant="ghost" wire:click="$set('showDrawDelete', false)">Cancel</flux:button>
                    <flux:button variant="danger" icon="trash" wire:click="deleteConfirmedDraw">Delete Draw</flux:button>
                </div>
            </div>
        @endif
    </flux:modal>

    {{-- PROJECT SELECTOR MODAL (STANDALONE PAGE ONLY) --}}
    @if(!$this->project && !$isProjectScoped)
        <flux:modal wire:model.self="showProjectSelector" name="lien-waiver-project-select" class="max-w-lg">
            {{-- Heavy option list (every selectable project) renders only once
                 the modal is opened — the open click's round trip brings it. --}}
            @if($showProjectSelector)
            <div class="space-y-4">
                <div>
                    <flux:heading size="lg">Create Lien Waiver</flux:heading>
                    <flux:subheading>Select a project to get started.</flux:subheading>
                </div>

                <flux:field>
                    <flux:label>Project</flux:label>
                    <flux:select wire:model.live="selectedProjectForCreate" searchable placeholder="Search projects..." variant="listbox">
                        @foreach($this->availableProjects as $proj)
                            <flux:select.option value="{{ $proj['id'] }}">
                                <div class="flex items-center w-full gap-2">
                                    <div class="flex-1 min-w-0">
                                        <div class="font-medium">{{ $proj['project_name'] }}</div>
                                        <div class="text-xs text-zinc-500">{{ $proj['short_address'] }}</div>
                                    </div>
                                    @if($proj['status'])
                                        <flux:badge size="sm" :color="$proj['status']->badge_color" inset="top bottom" class="shrink-0">
                                            {{ $proj['status']->title }}
                                        </flux:badge>
                                    @endif
                                </div>
                            </flux:select.option>
                        @endforeach
                    </flux:select>
                </flux:field>

                <div class="flex justify-end gap-2">
                    <flux:button type="button" variant="ghost" wire:click="$set('showProjectSelector', false)">Cancel</flux:button>
                    @if($selectedProjectForCreate)
                        <flux:button type="button" variant="primary" wire:click="selectProjectAndCreate({{ $selectedProjectForCreate }})">
                            Continue
                        </flux:button>
                    @else
                        <flux:button type="button" variant="primary" disabled>
                            Continue
                        </flux:button>
                    @endif
                </div>
            </div>
        @endif
        </flux:modal>
    @endif

    {{-- SWORN STATEMENT (GCSS) MODAL --}}
    @if($this->project)
        <flux:modal wire:model.self="showSwornStatement" name="lien-waiver-sworn-statement" class="max-w-2xl">
            <div class="space-y-4">
                <div>
                    <flux:heading size="lg">Sworn Statement + Sub Waivers</flux:heading>
                    <flux:subheading>
                        Creates the notarized statement for
                        <strong>{{ trim(implode(' — ', array_filter([$this->project->address, $this->project->project_name]))) }}</strong>{{ $this->project->client?->name ? ' (' . $this->project->client->name . ')' : '' }}
                        listing everyone paid on this job, and emails each checked vendor below their
                        pre-filled lien waiver to sign and notarize. Vendors that already received one are skipped.
                    </flux:subheading>
                </div>

                <flux:field>
                    <flux:label>Net amount of this payment</flux:label>
                    <flux:description class="italic">The draw the owner is paying you now.</flux:description>
                    {{-- Plain Flux sizing: the old "XL emulation" only ever hit
                         the prefix (flux:input inside a group doesn't take
                         !h-14/!text-2xl), leaving a 56px "$" beside a 40px
                         input. --}}
                    <flux:input.group>
                        <flux:input.group.prefix>$</flux:input.group.prefix>
                        <flux:input
                            wire:model="ssThisPayment"
                            mask:dynamic="$money($input)"
                            inputmode="decimal"
                            placeholder="0.00"
                            autocomplete="off"
                        />
                    </flux:input.group>
                    <flux:error name="ssThisPayment" />
                </flux:field>

                @if(count($ssRows))
                    <div class="space-y-2">
                        <flux:description>
                            Check each vendor you've paid on this job.
                        </flux:description>

                        @foreach($ssRows as $i => $row)
                            <div class="space-y-2 rounded-lg border border-zinc-200 px-3 py-2.5 dark:border-zinc-700 {{ empty($row['include']) ? 'opacity-50' : '' }}">
                                <div class="flex min-w-0 items-center gap-2">
                                    <flux:checkbox wire:model.live="ssRows.{{ $i }}.include" />
                                    <div class="min-w-0">
                                        <div class="truncate text-sm font-medium">
                                            <a href="{{ route('vendors.show', $row['vendor_id']) }}" target="_blank">{{ $row['name'] }}</a>
                                        </div>
                                        <div class="flex items-center gap-1.5 text-xs text-zinc-500 dark:text-zinc-400">
                                            <span>Paid to date: ${{ number_format((float) $row['paid'], 2) }}</span>
                                            {{-- Auto-decided outcome: real contract fully paid → FINAL waiver;
                                                 no contract on file → waiver to date marked OPEN. --}}
                                            @if(($row['contract'] ?? '') !== '' && (float) $row['paid'] + (float) str_replace(',', '', $row['this_payment'] !== '' ? $row['this_payment'] : '0') + 0.009 >= (float) str_replace(',', '', $row['contract']))
                                                <flux:tooltip content="Contract fully paid — generates a FINAL lien waiver" position="top">
                                                    <flux:badge size="sm" color="purple" inset="top bottom">Final</flux:badge>
                                                </flux:tooltip>
                                            @elseif(($row['contract'] ?? '') === '' && (float) $row['paid'] > 0 && empty($row['is_retail']))
                                                <flux:tooltip content="No contract on file — waiver to date, amounts marked OPEN" position="top">
                                                    <flux:badge size="sm" color="zinc" inset="top bottom">Open</flux:badge>
                                                </flux:tooltip>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                                {{-- One fixed line — identical cell structure keeps labels and
                                     inputs perfectly aligned across the three columns. --}}
                                <div class="grid grid-cols-3 items-start gap-2">
                                    <div class="min-w-0 space-y-1">
                                        <div class="text-xs text-zinc-500 dark:text-zinc-400">Kind of work</div>
                                        <flux:autocomplete size="sm" wire:model="ssRows.{{ $i }}.kind" placeholder="Select type of work">
                                            @foreach($this->kindOfWorkOptions as $kind)
                                                <flux:autocomplete.item wire:key="ss-kind-{{ $i }}-{{ $loop->index }}">{{ $kind }}</flux:autocomplete.item>
                                            @endforeach
                                        </flux:autocomplete>
                                        @error("ssRows.{$i}.kind")
                                            <div class="text-xs text-red-500">{{ $message }}</div>
                                        @enderror
                                    </div>
                                    <div class="min-w-0 space-y-1">
                                        <div class="text-xs text-zinc-500 dark:text-zinc-400">Contract</div>
                                        <flux:input.group>
                                            <flux:input.group.prefix>$</flux:input.group.prefix>
                                            {{-- Blank contract defaults to paid-to-date on the documents —
                                                 the placeholder shows exactly what will print. --}}
                                            <flux:input size="sm" wire:model.live.debounce.500ms="ssRows.{{ $i }}.contract" mask:dynamic="$money($input)" inputmode="decimal" placeholder="{{ (float) $row['paid'] > 0 ? number_format((float) $row['paid'], 2) : '0.00' }}" />
                                        </flux:input.group>
                                    </div>
                                    <div class="min-w-0 space-y-1">
                                        <div class="text-xs text-zinc-500 dark:text-zinc-400">This payment</div>
                                        <flux:input.group>
                                            <flux:input.group.prefix>$</flux:input.group.prefix>
                                            <flux:input size="sm" wire:model="ssRows.{{ $i }}.this_payment" mask:dynamic="$money($input)" inputmode="decimal" placeholder="0.00" />
                                        </flux:input.group>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <flux:description>No subs or suppliers with money on this project yet.</flux:description>
                @endif

                <div class="flex justify-end gap-2 pt-2">
                    <flux:button type="button" variant="ghost" wire:click="$set('showSwornStatement', false)">Cancel</flux:button>
                    <flux:button type="button" variant="primary" wire:click="generateSwornStatement"
                        wire:target="generateSwornStatement" wire:loading.attr="disabled" wire:loading.class="opacity-60">
                        Create
                    </flux:button>
                </div>
            </div>
        </flux:modal>
    @endif

    {{-- CREATE MODAL (PROJECT-SCOPED OR STANDALONE AFTER SELECTION) --}}
    @if($this->project)
        <flux:modal wire:model.self="showCreate" name="lien-waiver-create" class="max-w-xl">
            <div class="space-y-4">
                <div>
                    <flux:heading size="lg">Create Lien Waiver</flux:heading>
                    <flux:subheading>Lien waiver for {{ $this->project->project_name }}@if($this->project->client) for {{ $this->project->client->name }}@endif.</flux:subheading>
                </div>

                @if($this->projectSubVendors->isNotEmpty())
                    <div class="space-y-4">
                        <flux:select wire:model.live="newClaimantVendorId" label="Waiver Vendor" searchable variant="listbox" placeholder="Search vendor…">
                            <flux:select.option value="{{ $this->contractorVendor?->id }}">{{ $this->contractorVendor?->business_name }} — your company</flux:select.option>
                            @foreach($this->projectSubVendors as $sub)
                                <flux:select.option value="{{ $sub->id }}">{{ $sub->business_name }}</flux:select.option>
                            @endforeach
                        </flux:select>

                        {{-- Payments card for the selected vendor — OUTSIDE the form to avoid nested form issues.
                             Sub payouts live in expenses/checks, so a sub gets its own read-only table;
                             the GC's own waiver shows the project's payments (money from the client). --}}
                        @if($this->isSubClaimant)
                            @if($this->claimantTransactions->isNotEmpty())
                                <flux:card class="!p-4">
                                    <flux:heading size="lg" class="mb-3">Payments</flux:heading>
                                    <flux:table>
                                        <flux:table.columns>
                                            <flux:table.column>Amount</flux:table.column>
                                            <flux:table.column>Date</flux:table.column>
                                            <flux:table.column>Reference</flux:table.column>
                                        </flux:table.columns>
                                        <flux:table.rows>
                                            @foreach($this->claimantTransactions as $tx)
                                                <flux:table.row>
                                                    <flux:table.cell variant="strong">${{ number_format($tx->amount, 2) }}</flux:table.cell>
                                                    <flux:table.cell>{{ \Illuminate\Support\Carbon::parse($tx->date)->format('m/d/Y') }}</flux:table.cell>
                                                    <flux:table.cell>{{ $tx->reference }}</flux:table.cell>
                                                </flux:table.row>
                                            @endforeach
                                        </flux:table.rows>
                                    </flux:table>
                                </flux:card>
                            @endif
                        @elseif($this->newClaimantVendorId && $this->projectPayments->isNotEmpty())
                            <livewire:payments.payments-index
                                :project="$this->project"
                                view="projects.show"
                                :key="'lw-payments-vendor-'.$this->newClaimantVendorId" />
                        @endif
                    </div>
                @endif

                <form wire:submit="createWaiver" class="space-y-4">

                    <flux:select wire:model.live="newType" label="Waiver type">
                        @foreach($this->typeOptions as $opt)
                            <flux:select.option value="{{ $opt['value'] }}">{{ $opt['label'] }}</flux:select.option>
                        @endforeach
                    </flux:select>

                    <div class="flex flex-nowrap items-start gap-4">
                    <div class="min-w-0 flex-1">
                        @if($newType === \App\Enums\LienWaiverType::UnconditionalFinal->value)
                            <flux:field>
                                <flux:label>Amount</flux:label>
                                <div class="rounded-md border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm font-semibold text-zinc-900 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100">
                                    PAID IN FULL
                                </div>
                                <flux:description>Final unconditional waivers are issued for full payment, so no dollar figure is shown.</flux:description>
                            </flux:field>
                        @else
                            <flux:field>
                                <flux:label>Amount</flux:label>
                                <flux:input.group>
                                    <flux:input.group.prefix>$</flux:input.group.prefix>
                                    <flux:input
                                        wire:model="newAmount"
                                        mask:dynamic="$money($input)"
                                        inputmode="decimal"
                                        placeholder="0.00"
                                        autocomplete="off"
                                    />
                                </flux:input.group>
                            </flux:field>
                        @endif
                    </div>

                    <div class="min-w-0 flex-1">
                        <flux:input wire:model="newThroughDate" type="date" label="Through date" />
                    </div>
                </div>

                <flux:separator variant="subtle" />

                @if($this->isSubClaimant)
                    {{-- Sub waiver: the sub was employed by the contractor of record, so the
                         payer is the GC (used from the waiver's belongs_to_vendor). --}}
                    <flux:field>
                        <flux:label>Payer</flux:label>
                        <div class="rounded-lg border border-zinc-200 px-3 py-2 dark:border-zinc-700">
                            <span class="font-medium text-zinc-900 dark:text-zinc-100">{{ $this->contractorVendor?->business_name }}</span>
                            <span class="block text-sm text-zinc-500 dark:text-zinc-400">Contractor of record</span>
                        </div>
                    </flux:field>
                @elseif($this->project->client)
                    {{-- Name + address go in the slot (not the label/description props): the cards
                         variant renders those props through <flux:heading>, which double-escapes "&". --}}
                    <flux:radio.group wire:model="payerSource" label="Payer" variant="cards" class="flex-col" disabled>
                        <flux:radio value="client">
                            <span>{{ $this->project->client->name ?: 'Project client (owner)' }}</span>
                            <span class="mt-0.5 block text-sm font-normal text-zinc-500 dark:text-zinc-400">{{ $this->payerClientLine ?: 'Project client' }}</span>
                        </flux:radio>
                    </flux:radio.group>
                @else
                    <div class="space-y-3">
                        <flux:input wire:model="newPayerName" label="Payer name" placeholder="Project client / GC name" />
                        <flux:input wire:model="newPayerAddress" label="Payer address" placeholder="123 Main St" />
                        <flux:input wire:model="newPayerCityStateZip" label="City, State Zip" placeholder="Sacramento, CA 95814" />
                    </div>
                @endif

                    <div class="flex justify-end gap-2 pt-6">
                        <flux:button type="button" variant="ghost" wire:click="$set('showCreate', false)">Cancel</flux:button>
                        <flux:button type="submit" variant="primary"
                            wire:target="createWaiver" wire:loading.attr="disabled" wire:loading.class="opacity-60">
                            Create waiver
                        </flux:button>
                    </div>
                </form>
            </div>
        </flux:modal>
    @endif
</div>
