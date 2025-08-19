@props([
    'disablePaidBy' => false,
    'hideBasicFields' => false
])

@unless($hideBasicFields)
    {{-- DATE --}}
    <x-forms.one_line label="Date">
        <flux:input wire:model.live="form.date" type="date" />
        <flux:error name="form.date" />
    </x-forms.one_line>

    {{-- PAID BY --}}
    <x-forms.one_line label="Paid By">
        <flux:select wire:model.live="form.paid_by" placeholder="Choose paid by...">
            <flux:select.option value="" readonly>{{ auth()->user()->vendor->business_name }}</flux:select.option>
            @foreach ($employees as $employee)
                <flux:select.option value="{{ $employee->id }}" :disabled="$disablePaidBy">
                    {{ $employee->first_name }}
                </flux:select.option>
            @endforeach
        </flux:select>
        <flux:error name="form.paid_by" />
    </x-forms.one_line>
@endunless

{{-- BANK AND CHECK DETAILS --}}
<div x-show="!$wire.form.paid_by" x-transition>
    <div class="space-y-4">
        @if($hideBasicFields)
            {{-- Bank select with direct flux components --}}
            <flux:select 
                name="bank_account_id" 
                wire:model.live="bank_account_id" 
                label="Bank"
                placeholder="Choose bank..."
            >
                @foreach ($this->bank_accounts as $bank_account)
                    <flux:select.option value="{{ $bank_account->id }}">
                        {{ $bank_account->bank->name }}
                    </flux:select.option>
                @endforeach
            </flux:select>
            <flux:error name="bank_account_id" />

            <div x-show="$wire.bank_account_id" x-transition class="space-y-4">
                {{-- Payment Type with direct flux components --}}
                <flux:select 
                    wire:model.live="check_type" 
                    label="Payment Type"
                    placeholder="Choose payment type..."
                >
                    <flux:select.option value="Check">Check</flux:select.option>
                    <flux:select.option value="Transfer">Transfer</flux:select.option>
                    <flux:select.option value="Cash">Cash</flux:select.option>
                </flux:select>
                <flux:error name="check_type" />

                {{-- Check Number with direct flux components --}}
                <div x-data="{ check_type: @entangle('check_type') }" x-show="check_type == 'Check'" x-transition>
                    <flux:input 
                        wire:model.live="check_number" 
                        label="Check Number"
                        type="number" 
                        inputmode="numeric" 
                        step="1" 
                    />
                    <flux:error name="check_number" />
                    @if(property_exists($this, 'next_check_auto') && $next_check_auto)
                        <flux:description>
                            <i class="text-indigo-600">Automatic Next Check Number</i>
                        </flux:description>
                    @endif
                </div>
            </div>
        @else
            {{-- Original implementation with x-forms.one_line --}}
            <x-forms.one_line label="Bank">
                <flux:select name="bank_account_id" wire:model.live="bank_account_id" placeholder="Choose bank...">
                    @foreach ($this->bank_accounts as $bank_account)
                        <flux:select.option value="{{ $bank_account->id }}">
                            {{ $bank_account->bank->name }}
                        </flux:select.option>
                    @endforeach
                </flux:select>
                <flux:error name="bank_account_id" />
            </x-forms.one_line>

            <div x-show="$wire.bank_account_id" x-transition class="mt-2 space-y-2">
                <x-forms.one_line label="Payment Type">
                    <flux:select wire:model.live="check_type" placeholder="Choose payment type...">
                        <flux:select.option value="Check">Check</flux:select.option>
                        <flux:select.option value="Transfer">Transfer</flux:select.option>
                        <flux:select.option value="Cash">Cash</flux:select.option>
                    </flux:select>
                    <flux:error name="check_type" />
                </x-forms.one_line>

                <div x-data="{ check_type: @entangle('check_type') }" x-show="check_type == 'Check'" x-transition>
                    <x-forms.one_line label="Check Number">
                        <flux:input wire:model.live="check_number" type="number" inputmode="numeric" step="1" />
                        <flux:error name="check_number" />
                        @if(property_exists($this, 'next_check_auto') && $next_check_auto)
                            <flux:description>
                                <i class="text-indigo-600">Automatic Next Check Number</i>
                            </flux:description>
                        @endif
                    </x-forms.one_line>
                </div>
            </div>
        @endif
    </div>
</div>

{{-- INVOICE --}}
<div x-show="$wire.form.paid_by" x-transition>
    @if($hideBasicFields)
        {{-- Direct flux components for Reference/Invoice --}}
        <flux:input 
            wire:model.live.debounce.500ms="form.invoice" 
            label="Reference"
            type="text" 
        />
        <flux:error name="form.invoice" />
    @else
        <x-forms.one_line label="Reference">
            <flux:input wire:model.live.debounce.500ms="form.invoice" type="text" />
            <flux:error name="form.invoice" />
        </x-forms.one_line>
    @endif
</div>
