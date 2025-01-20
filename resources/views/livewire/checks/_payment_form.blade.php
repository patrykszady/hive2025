{{-- DATE --}}
<x-forms.one_line label="Date">
    <flux:input wire:model.live="form.date" type="date" />
    <flux:error name="form.date" />
</x-forms.one_line>

{{-- PAID BY --}}
<div
    {{-- 7-19-2024 'disable' entangle only used on TimesheetPaymentCreate, Console Error otherwire (Vendors Payment) --}}
    x-data="{ disable: @entangle('disable_paid_by') }"
    >
    <x-forms.one_line label="Paid By">
        <flux:select wire:model.live="form.paid_by" placeholder="Choose paid by...">
            <flux:option value="" readonly>{{auth()->user()->vendor->business_name}}</flux:option>
            @foreach ($employees as $employee)
                <flux:option value="{{$employee->id}}" x-bind:disabled="disable">{{$employee->first_name}}</flux:option>
            @endforeach
        </flux:select>
    </x-forms.one_line>
</div>

<div
    x-data="{ open: @entangle('form.paid_by') }"
    x-show="!open"
    x-transition
    >

    {{-- <livewire:checks.check-create /> --}}
    @include('livewire.checks._include_form')
</div>

{{-- INVOICE --}}
<div
    x-data="{ open: @entangle('form.paid_by') }"
    x-show="open"
    x-transition
    >
    <x-forms.one_line label="Reference">
        <flux:input wire:model="form.invoice" type="text" />
        <flux:error name="form.invoice" />
    </x-forms.one_line>
</div>
