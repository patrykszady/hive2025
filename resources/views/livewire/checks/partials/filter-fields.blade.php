@props([
    'layout' => 'stacked', {{-- 'stacked' for mobile, 'inline' for desktop --}}
])

@php($fieldWrap = $layout === 'inline' ? 'flex-1 min-w-0 w-full' : 'min-w-0 w-full')

<div class="{{ $layout === 'inline' ? 'flex flex-col sm:flex-row items-end gap-4' : 'flex flex-col gap-4' }}">
    <div class="{{ $fieldWrap }}">
        <flux:input wire:model.debounce.500ms.live="amount" label="Amount" icon="magnifying-glass" placeholder="123.45" />
    </div>

    <div class="{{ $fieldWrap }}">
        <flux:input wire:model.debounce.500ms.live="check_number" label="Check Number" icon="magnifying-glass" placeholder="1234" />
    </div>

    <div class="{{ $fieldWrap }}">
        <flux:select wire:model.live="bank" label="Bank" variant="listbox" placeholder="Choose Bank...">
            <flux:select.option value="">All Banks</flux:select.option>
            @foreach ($banks->groupBy('plaid_ins_id') as $bank)
                <flux:select.option value="{{$bank->first()->id}}">{{$bank->first()->name}}</flux:select.option>
            @endforeach
        </flux:select>
    </div>
</div>
