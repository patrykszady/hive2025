<flux:modal name="expenses_form_modal" class="space-y-2">
    <div class="flex justify-between">
        <flux:heading size="lg">{{$view_text['card_title']}}</flux:heading>
        @if(isset($expense->id))
            <flux:button wire:navigate.hover href="{{route('expenses.show', $expense->id)}}">Show Expense</flux:button>
        @endif
    </div>

    <flux:separator variant="subtle" />

    <form wire:submit="{{$view_text['form_submit']}}" class="grid gap-6">
        {{-- AMOUNT --}}
        <div
            x-data="{ amount: @entangle('form.amount'), save_form: @entangle('view_text.form_submit'), expense_transactions: @entangle('form.expense_transactions_sum') }"
            >
            <flux:input
                wire:model.live.debounce.500ms="form.amount"
                x-bind:disabled="save_form == 'save' || expense_transactions"
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
            <flux:select label="Vendor" wire:model.live="form.vendor_id" variant="listbox" searchable placeholder="Choose vendor...">
                <x-slot name="search">
                    <flux:select.search placeholder="Search..." />
                </x-slot>
                @foreach($this->vendors as $vendor)
                    <flux:option value="{{$vendor->id}}">{{$vendor->name}}</flux:option>
                @endforeach
            </flux:select>
            @if(isset($form->merchant_name))
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
                <flux:label>Project</flux:label>
                <flux:input.group>
                    <flux:select wire:model.live="form.project_id" variant="listbox" searchable x-bind:disabled="split" placeholder="Choose project..." >
                        {{-- <flux:option value="" readonly x-text="split ? 'Expense is Split' : 'Select Project'"></flux:option> --}}

                        @foreach($this->projects as $project)
                            <flux:option wire:key="{{$project->id}}" value="{{$project->id}}"><div>{{$project->address}} <br> <i class="font-normal">{{$project->project_name}}</i></div></flux:option>
                        @endforeach

                        <flux:option disabled>--------------</flux:option>

                        @foreach($distributions as $distribution)
                            <flux:option wire:key="D:{{$distribution->id}}" value="D:{{$distribution->id}}">{{$distribution->name}}</flux:option>
                        @endforeach
                    </flux:select>

                    <flux:button wire:click="$toggle('split')" icon="receipt-percent">Split</flux:button>
                </flux:input.group>
                @if($expense)
                    @if($expense->note)
                        <flux:description><i class="text-sky-800">{{$expense->note}}</i></flux:description>
                    @endif
                    @if($expense->has('receipts'))
                        @if(isset($expense->receipts()->first()->notes))
                            <flux:description><i class="text-sky-800">{{$expense->receipts()->first()->notes}}</i></flux:description>
                        @endif
                    @endif
                @endif
            </flux:field>
        </div>

        {{-- SPLITS --}}
        <div
            x-data="{ open: @entangle('split'), splits: @entangle('splits'), total: @entangle('form.amount')}"
            x-show="open"
            x-transition
            >
            <flux:button
                wire:click="$dispatchTo('expenses.expense-splits-create', 'addSplits', { expense: {{$expense}} })"
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
                <flux:option value="NULL">{{auth()->user()->vendor->name}}</flux:option>
                @foreach($employees as $employee)
                    <flux:option value="{{$employee->id}}">{{$employee->first_name}}</flux:option>
                @endforeach
            </flux:select>
        </div>

        {{-- CHECK --}}
        {{-- SHOULD Be a component here --}}
        <div
            x-data="{ open: @entangle('form.paid_by'), project_id: @entangle('form.project_id'), splits: @entangle('splits') }"
            x-show="(project_id || splits) && !open"
            x-transition
            >

            @include('livewire.checks.form')
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
                    <flux:option>None</flux:option>
                    <flux:option x-bind:disabled="project_completed">Client</flux:option>
                    @foreach ($via_vendor_employees as $employee)
                        <flux:option value="{{$employee->id}}">{{$employee->first_name}}</flux:option>
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

        {{-- FOOTER --}}
        <div class="flex space-x-2 sticky bottom-0">
            <flux:spacer />

            @if($form->amount == '0.00' || $form->transaction != NULL || ($form->expense_transactions_sum == FALSE && $form->transaction == NULL && $form->bank_account_id == NULL))
                <flux:button wire:click="remove" variant="danger">Remove</flux:button>
            @endif
            <flux:button type="submit" variant="primary">{{$view_text['button_text']}}</flux:button>
        </div>
    </form>

    {{-- SPLITS MODAL --}}
    <livewire:expenses.expense-splits-create :projects="$this->projects" :distributions="$distributions" />
</flux:modal>
