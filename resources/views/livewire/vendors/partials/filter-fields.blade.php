@props([
    'layout' => 'stacked', {{-- 'stacked' for mobile, 'inline' for desktop --}}
])

@php($fieldWrap = $layout === 'inline' ? 'flex-1 min-w-0 w-full' : 'min-w-0 w-full')

<div class="{{ $layout === 'inline' ? 'flex flex-col sm:flex-row items-end gap-4' : 'flex flex-col gap-4' }}">
    <div class="{{ $fieldWrap }}">
        <flux:input wire:model.live="business_name" label="Vendor Name" icon="magnifying-glass" placeholder="Search Vendors" />
    </div>

    <div class="{{ $fieldWrap }}">
        <flux:select wire:model.live="vendor_type" label="Business Type" placeholder="Choose type...">
            <flux:select.option value="All">All Vendor Types</flux:select.option>
            <flux:select.option value="Sub">Subcontractor</flux:select.option>
            <flux:select.option value="Retail">Retail</flux:select.option>
            <flux:select.option value="1099">1099/Independent</flux:select.option>
            <flux:select.option value="DBA">DBA</flux:select.option>
        </flux:select>
    </div>
</div>
