<div>
<x-form-modal name="expenses_form_modal" :title="$view_text['card_title']">
    <x-slot name="headerActions">
        @if(isset($expense->id))
            <flux:button wire:navigate.hover href="{{route('expenses.show', $expense->id)}}">Show Expense</flux:button>
        @endif
    </x-slot>

    <form
        id="expenses_form_modal_form"
        wire:submit="{{$view_text['form_submit']}}"
        class="space-y-4"
    >
        {{-- AMOUNT --}}
        <div
            x-data="{ amount: @entangle('form.amount'), save_form: @entangle('view_text.form_submit'), expense_transactions: @entangle('form.expense_transactions_sum') }"
            >
            <flux:input
                wire:model.live.debounce.500ms="form.amount"
                {{--  || expense_transactions --}}
                x-bind:disabled="save_form == 'save'"
                label="Amount"
                type="number"
                size="lg"
                inputmode="decimal"
                pattern="[0-9]*"
                step="0.01"
                placeholder="123.45"
            />
        </div>

        {{-- DATE --}}
        <flux:input
            wire:model.live.debounce.500ms="form.date"
            label="Date"
            type="date"
        />

        {{-- VENDOR --}}
        <flux:field>
            <flux:select label="Vendor" wire:model.live="form.vendor_id" variant="listbox" placeholder="Choose vendor..." searchable>
                @foreach($this->vendors as $vendor)
                    <flux:select.option value="{{$vendor->id}}">{{$vendor->name}}</flux:select.option>
                @endforeach
            </flux:select>
            
            @if($this->shouldShowMerchantName)
                <flux:description><i class="text-sky-800">{{$form->merchant_name}}</i></flux:description>
            @endif

            @if($expense || $form->transaction)
                @if((is_null($expense->vendor_id) AND isset($form->transaction->plaid_merchant_description)) OR isset($expense->note))
                    @if(isset($form->transaction->plaid_merchant_name))
                        <flux:description><i class="text-sky-800">Name: {{$form->transaction->plaid_merchant_name}}</i></flux:description>
                    @endif
                    @if(isset($form->transaction->plaid_merchant_description) && $form->transaction->plaid_merchant_description != $form->transaction->plaid_merchant_name)
                        <flux:description><i class="text-sky-800">Desc: {{$form->transaction->plaid_merchant_description}}</i></flux:description>
                    @endif
                @endif
            @endif
        </flux:field>

        {{-- PROJECT --}}
        <div
            x-data="{ open: @entangle('form.vendor_id'), split: @entangle('split') }"
            x-show="open"
            x-transition
            >
            <flux:field>
                <div x-show="!split" x-transition>
                    <flux:label>Project</flux:label>
                    <flux:select 
                        wire:model.live="form.project_id" 
                        variant="listbox" 
                        placeholder="Choose project..."
                        searchable
                        class="[&_[data-flux-select-trigger]]:min-h-[4rem] [&_[data-flux-select-trigger]]:py-3"
                    >
                        @foreach($this->projects as $project)
                            <flux:select.option wire:key="{{$project->id}}" value="{{$project->id}}">
                                <div class="flex flex-col gap-0 w-full">
                                    <div class="flex items-center w-full">
                                        <span class="flex-1 min-w-0">{{ $project->short_address }}</span>

                                        @if($project->latestStatus)
                                            <flux:badge size="sm" :color="$project->latestStatus->badge_color" inset="top bottom" class="shrink-0 ml-2">
                                                {{ $project->latestStatus->title }}
                                            </flux:badge>
                                        @endif
                                    </div>

                                    <i class="font-normal block w-full leading-tight">{{$project->project_name}}</i>
                                </div>
                            </flux:select.option>
                        @endforeach

                        <flux:select.option disabled>--------------</flux:select.option>

                        @foreach($this->distributions as $distribution)
                            <flux:select.option wire:key="D:{{$distribution->id}}" value="D:{{$distribution->id}}">{{$distribution->name}}</flux:select.option>
                        @endforeach
                    </flux:select>

                    @if($expense)
                        @if($expense->note)
                            <flux:description><i class="text-sky-800">{{$expense->note}}</i></flux:description>
                        @endif
                        @if($this->notesSummary)
                            <flux:description><i class="text-sky-800">{{$this->notesSummary}}</i></flux:description>
                        @endif
                    @endif
                </div>

                @if(! ($form->transaction && ! $expense->exists))
                <div class="mt-2 flex items-center gap-2">
                    <flux:switch wire:model.live="split" align="left" />

                    <span class="text-sm text-zinc-600" x-text="split ? 'Project Split' : 'Split'"></span>
                </div>
                @endif
            </flux:field>
        </div>

        {{-- SPLITS --}}
        <div
            x-data="{ open: @entangle('split'), splits: @entangle('splits'), total: @entangle('form.amount')}"
            x-show="open && !{{ ($form->transaction && ! $expense->exists) ? 'true' : 'false' }}"
            x-transition
            >
            <flux:button
                wire:click="openSplits"
                x-text="splits == true ? 'Edit Splits' : 'Add Splits'"
                variant="primary"
                class="w-full"
                >
            </flux:button>
            <flux:error name="no_splits" />
        </div>

        {{-- PAID BY --}}
        <div
            x-data="{ open: @entangle('form.project_id'), splits: @entangle('splits'), split: @entangle('split') }"
            x-show="splits && split || open"
            x-transition
            >
            <flux:select label="Paid By" wire:model.live="form.paid_by" placeholder="Choose who paid...">
                <flux:select.option value="">{{auth()->user()->vendor->name}}</flux:select.option>
                @foreach($this->employees as $employee)
                    <flux:select.option value="{{$employee->id}}">{{$employee->first_name}}</flux:select.option>
                @endforeach
            </flux:select>
        </div>

        {{-- ATTACH TO EXISTING CHECK (Optional) --}}
        <div
            x-data="{ open: @entangle('form.paid_by'), existing_check: @entangle('existing_check_id') }"
            x-show="true"
            x-transition
            >
            <div class="flex flex-wrap items-end gap-3">
                <div class="grow min-w-[16rem]">
                    <flux:select 
                        wire:model.live="existing_check_id" 
                        label="Attach to Existing Check (Optional)"
                        placeholder="Create new check or select existing..."
                        searchable
                        x-bind:disabled="open"
                    >
                        <flux:select.option value="">Create New Check</flux:select.option>
                        @foreach($this->available_checks as $check)
                            <flux:select.option value="{{$check['id']}}">{{$check['label']}}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:description>Select an existing check to add this expense to it, or leave blank to create a new check.</flux:description>
                </div>
                @if(isset($expense->id) && ($expense->check_id || $expense->checks->isNotEmpty()))
                    <flux:button
                        size="sm"
                        variant="danger"
                        x-on:click.stop="if (confirm('Remove this expense from all checks?')) { $wire.removeCheckAssociation() }"
                    >
                        Remove from Check
                    </flux:button>
                @endif
            </div>
        </div>

        {{-- CHECK --}}
        {{-- SHOULD Be a component here --}}
        <div
            x-data="{ open: @entangle('form.paid_by'), project_id: @entangle('form.project_id'), splits: @entangle('splits'), existing_check: @entangle('existing_check_id'), has_check: {{ isset($expense->id) && ($expense->check_id || $expense->checks->isNotEmpty()) ? 'true' : 'false' }} }"
            x-show="(project_id || splits || has_check) && !existing_check"
            x-transition
            >
            @include('livewire.checks._payment_form', [
                'hideBasicFields' => true,
                'disableChecks' => (bool) $form->paid_by,
                'showWhenPaidBy' => true,
            ])
        </div>


        {{-- RECEIPT --}}
        <div
            x-data="{ open: @entangle('form.project_id'), splits: @entangle('splits'), split: @entangle('split') }"
            x-show="splits && split || open"
            x-transition
            >

            <flux:input
                wire:model="form.receipt_file"
                type="file"
                {{-- x-bind:disabled="save_form == 'save' || expense_transactions" --}}
                label="Receipt File"
            />
            {{-- LOADING STATES --}}
            <div>
                <div x-data="{ receipts: @entangle('form.receipts')}" x-show="receipts">
                    <flux:description><i>Receipt Existing</i></flux:description>
                </div>
                <div x-data="{ receipt: @entangle('form.receipt_file')}" x-show="receipt" wire:loading.remove wire:target="form.receipt_file">
                    <flux:description wire:loaded wire:target="form.receipt_file"><i>Receipt Uploaded</i></flux:description>
                </div>
                <flux:description wire:loading wire:target="form.receipt_file"><i>Uploading...</i></flux:description>
            </div>

            <div class="mt-2">
                <flux:switch wire:model.live="form.is_material_order" label="Material Order" />
            </div>

            <div x-data="{ isMaterialOrder: @entangle('form.is_material_order') }" x-show="isMaterialOrder" x-transition class="mt-2">
                <flux:select wire:model="form.belongs_to_vendor_id" variant="listbox" placeholder="Belongs to vendor..." searchable label="Belongs To Vendor">
                    @foreach($this->vendors as $vendor)
                        <flux:select.option value="{{ $vendor->id }}">{{ $vendor->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
        </div>

        {{-- REIMBURSEMNT --}}
        <div
            x-data="{ open: @entangle('form.project_id'), project_completed: @entangle('form.project_completed') }"
            x-show="open"
            x-transition
            >
            <flux:field>
                <flux:label>Reimbursment</flux:label>

                <flux:select wire:model.live="form.reimbursment" placeholder="Choose reimbursment...">
                    {{--  x-bind:selected="split == true ? true : false" --}}
                    <flux:select.option value="">None</flux:select.option>
                    <flux:select.option x-bind:disabled="project_completed" value="Client">Client</flux:select.option>
                    @foreach ($this->via_vendor_employees as $employee)
                        <flux:select.option value="{{$employee->id}}">{{$employee->first_name}}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:error name="form.reimbursment" />
            </flux:field>
        </div>

        {{-- PO/INVOICE --}}
        <div
            x-data="{ open: @entangle('form.project_id'), splits: @entangle('splits'), split: @entangle('split') }"
            x-show="splits && split || open"
            x-transition
            >

            <flux:input
                wire:model.live.debounce.500ms="form.invoice"
                label="Invoice"
                type="text"
                placeholder="Invoice/PO"
            />
        </div>

        {{-- NOTES --}}
        <div
            x-data="{ open: @entangle('form.project_id'), splits: @entangle('splits'), split: @entangle('split') }"
            x-show="splits && split || open"
            x-transition
            >
            <flux:textarea
                wire:model.live.debounce.500ms="form.note"
                label="Notes"
                rows="auto"
                resize="none"
                placeholder="Notes"
            />
        </div>
    </form>

    <x-slot name="footer">
        @if($expense->exists && !$expense->transactions()->exists())
            <flux:button wire:click="remove" variant="danger">Remove</flux:button>
        @endif

        <flux:spacer />

        <flux:button type="submit" form="expenses_form_modal_form" variant="primary">{{$view_text['button_text']}}</flux:button>
    </x-slot>
</x-form-modal>

{{-- SPLITS MODAL --}}
<livewire:expenses.expense-splits-create :projects="$this->projects" :distributions="$this->distributions" />

{{-- UPLOAD RECEIPT MODAL --}}
<flux:modal name="upload_receipt_modal" class="sm:max-w-md space-y-4">
    <flux:heading size="lg">Upload Receipt</flux:heading>

    <form wire:submit="uploadReceipt" class="space-y-4">
        <flux:input
            wire:model="upload_file"
            type="file"
            label="Receipt / Material Order"
        />
        <flux:error name="upload_file" />

        <div>
            <flux:switch wire:model.live="upload_is_material_order" label="Material Order" />
        </div>

        <div x-data="{ isMaterialOrder: @entangle('upload_is_material_order') }" x-show="isMaterialOrder" x-transition>
            <flux:select wire:model="upload_belongs_to_vendor_id" variant="listbox" placeholder="Belongs to vendor..." searchable label="Belongs To Vendor">
                @foreach($this->vendors as $vendor)
                    <flux:select.option value="{{ $vendor->id }}">{{ $vendor->name }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>

        <div class="flex justify-end gap-2">
            <flux:button variant="ghost" x-on:click="$flux.modal('upload_receipt_modal').close()">Cancel</flux:button>
            <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="uploadReceipt, upload_file">
                <span wire:loading.remove wire:target="uploadReceipt">Process</span>
                <span wire:loading wire:target="uploadReceipt">Processing...</span>
            </flux:button>
        </div>
    </form>
</flux:modal>
</div>
