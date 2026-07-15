<div class="max-w-4xl" x-data="{
    initDate() {
        // Only set date if it's empty (new payment)
        if (!$wire.form.date) {
            const now = new Date();
            const year = now.getFullYear();
            const month = String(now.getMonth() + 1).padStart(2, '0');
            const day = String(now.getDate()).padStart(2, '0');
            $wire.form.date = `${year}-${month}-${day}`;
        }
    }
}" x-init="initDate()">
    <form wire:submit="{{$view_text['form_submit']}}">
        <div class="grid max-w-xl grid-cols-4 gap-4 xl:relative lg:max-w-5xl sm:px-6">
            <div class="col-span-4 space-y-4 lg:col-span-2 lg:sticky lg:top-4 lg:self-start">
                <x-island-card heading="{{$vendor->name}} Payment" subheading="Choose Projects to add for {{$vendor->name}} in this Payment" :separator="true">
                    {{-- always: true — re-render on EVERY update (island keystrokes AND
                         outer project-amount changes) so the total and the submit
                         button's disabled state stay live in both directions. --}}
                    @island(defer: true, always: true)
                        @placeholder
                            <div class="space-y-2 my-2">
                                @foreach(['Date', 'Paid By', 'Bank'] as $label)
                                    <div class="py-1 grid grid-cols-3 gap-4">
                                        <dt class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ $label }}</dt>
                                        <dd class="col-start-2 col-span-2">
                                            <flux:skeleton class="h-9 w-full rounded-md" animate="shimmer" />
                                        </dd>
                                    </div>
                                @endforeach
                            </div>
                        @endplaceholder

                        <x-cards.body :class="'space-y-2 my-2'">
                            {{-- FORM --}}
                            @include('livewire.checks._payment_form', ['disablePaidBy' => $this->disablePaidBy])

                            {{-- Must live INSIDE this island: updates originating in the
                                 island (wire:model.live fields) re-render only the island. --}}
                            @include('livewire.checks._check_image_preview')
                        </x-cards.body>

                        <flux:separator variant="subtle" />

                        <div class="space-y-2 mt-2">
                            <flux:button class="w-full">Check Total | <b>{{money($this->vendor_check_sum)}}</b></flux:button>
                            {{-- Disabled on validation errors, missing required inputs, or a $0 total --}}
                            <flux:button type="submit" variant="primary" class="w-full" :disabled="! $this->canSubmitPayment">{{$view_text['button_text']}}</flux:button>
                        </div>

                        <flux:error name="check_total_min" />
                    @endisland
                </x-island-card>

                {{-- Payment Confirmation Modal --}}
                @include('livewire.checks._confirm_payment_modal', ['confirm_total' => (float) $this->vendor_check_sum])

                {{-- Check image lightbox (outside the island — see partial docblock) --}}
                @include('livewire.checks._check_image_lightbox')

                {{-- INSURANCE --}}
                <livewire:vendor-docs.vendor-docs-card :vendor="$vendor" :view="true" lazy wire:key="insurance-{{ $vendor->id }}" />

                {{-- SELECT PROJECT --}}
                <x-island-card heading="Choose Payment Projects">
                    <flux:input.group>
                        <x-forms.project-select 
                            :projects="$this->availableProjects" 
                            model="project_id"
                            :in-input-group="true"
                        />

                        <flux:button variant="primary" wire:click="addProject" icon="plus-circle">Add</flux:button>
                    </flux:input.group>

                    <flux:error name="project_id" />
                </x-island-card>
            </div>
            <div class="col-span-4 space-y-2 lg:col-span-2">
                {{-- PAYMENT PROJECTS --}}
                @foreach(collect($projects)->where('show', true)->sortBy('order') as $project_id => $project)
                    <x-island-card :heading="$project['address']" :href="route('projects.show', $project['id'])" :subheading="$project['project_name']" :separator="true" class="space-y-2" wire:key="{{$project_id}}" wire:transition>
                        <x-slot:actions>
                            <flux:button.group>
                                <flux:button size="sm" wire:click="$dispatchTo('bids.bid-create', 'addBids', { vendor: {{$vendor->id}}, project: {{$project['id']}}, context: 'payment' })">Edit Bids</flux:button>
                                <flux:button size="sm" wire:click="removeProject({{$project_id}})">Remove</flux:button>
                            </flux:button.group>
                        </x-slot:actions>

                        {{-- VENDOR BIDS --}}
                        <x-forms.one_line label="Total Bids">
                            <flux:input.group>
                                <flux:input.group.prefix>$</flux:input.group.prefix>
                                <flux:input wire:model="projects.{{$project_id}}.vendor_bids_sum" type="number" disabled />
                                <flux:error name="projects.{{$project_id}}.vendor_bids_sum" />
                            </flux:input.group>
                        </x-forms.one_line>

                        {{-- VENDOR PROJECT SUM --}}
                        <x-forms.one_line label="Total Paid">
                            <flux:input.group>
                                <flux:input.group.prefix>$</flux:input.group.prefix>
                                <flux:input wire:model="projects.{{$project_id}}.vendor_expenses_sum" type="number" disabled />
                                <flux:error name="projects.{{$project_id}}.vendor_expenses_sum" />
                            </flux:input.group>
                        </x-forms.one_line>

                        {{-- AMOUNT --}}
                        <x-forms.one_line label="Amount">
                            <flux:input.group>
                                <flux:input.group.prefix>$</flux:input.group.prefix>
                                <flux:input
                                    wire:model.live.debounce.500ms="projects.{{$project_id}}.amount"
                                    type="number"
                                    inputmode="decimal"
                                    step="0.01"
                                    min="0.00"
                                    pattern="[0-9]*"
                                    autofocus
                                />
                            </flux:input.group>
                            <flux:error name="projects.{{$project_id}}.amount" />
                        </x-forms.one_line>

                        <x-forms.one_line label="Balance">
                            <flux:input.group>
                                <flux:input.group.prefix>$</flux:input.group.prefix>
                                <flux:input wire:model="projects.{{$project_id}}.balance" type="number" disabled />
                                <flux:error name="projects.{{$project_id}}.balance" />
                            </flux:input.group>
                        </x-forms.one_line>
                    </x-island-card>
                @endforeach

                {{-- VENDOR REIMBURSEMENTS (vendor owes the company) — selected rows are deducted from the check --}}
                @if(!$vendor_reimbursement_expenses->isEmpty())
                    @php
                        $selectedReimbursementSum = $vendor_reimbursement_expenses
                            ->filter(fn($e) => ($selectedVendorReimbursementExpenses[$e->id] ?? false))
                            ->sum('amount');
                    @endphp
                    <x-island-card heading="{{ $vendor->name }} owes for Expenses">
                        <x-slot:actions>
                            <flux:button disabled>
                                {{ $selectedReimbursementSum > 0 ? '-'.money($selectedReimbursementSum) : money(0) }}
                            </flux:button>
                        </x-slot:actions>

                        <flux:table>
                            <flux:table.columns>
                                <flux:table.column></flux:table.column>
                                <flux:table.column>Amount</flux:table.column>
                                <flux:table.column>Date</flux:table.column>
                                <flux:table.column>Vendor</flux:table.column>
                            </flux:table.columns>

                            <flux:table.rows>
                                @foreach($vendor_reimbursement_expenses as $expense)
                                    <flux:table.row :key="$expense->id">
                                        <flux:table.cell>
                                            <flux:checkbox wire:model.live="selectedVendorReimbursementExpenses.{{$expense->id}}" />
                                        </flux:table.cell>
                                        <flux:table.cell variant="strong">
                                            <a wire:navigate.hover href="{{route('expenses.show', $expense->id)}}">-{{ money($expense->amount) }}</a>
                                        </flux:table.cell>
                                        <flux:table.cell>{{ $expense->date->format('m/d/Y') }}</flux:table.cell>
                                        <flux:table.cell>
                                            <a wire:navigate.hover href="{{route('vendors.show', $expense->vendor->id)}}">{{ Str::limit($expense->vendor->name, 25) }}</a>
                                        </flux:table.cell>
                                    </flux:table.row>
                                @endforeach
                            </flux:table.rows>
                        </flux:table>
                    </x-island-card>
                @endif

                <livewire:bids.bid-create />
                <livewire:vendor-docs.vendor-doc-create />
            </div>
        </div>
    </form>
</div>

