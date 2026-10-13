<?php

use Illuminate\Support\Facades\Blade;

/**
 * <x-vendor-picker> is bound like an input (wire:model on the component, Alpine
 * x-modelable inside) and never prints vendor options: the list is fetched by
 * the browser from /vendors/picker-options and filtered there.
 */
it('binds through wire:model, carries its static options, and prints no vendor options', function (): void {
    $html = Blade::render('<x-vendor-picker wire:model.live="expense_vendor" label="Vendor" placeholder="Choose Vendor..." clearable :details="true" :extra="[[\'value\' => \'0\', \'label\' => \'NO VENDOR\']]" />');

    expect($html)->toContain('wire:model.live="expense_vendor"')
        ->and($html)->toContain('x-modelable="value"')
        ->and($html)->toContain('vendorPicker(')
        ->and($html)->toContain('picker-options')
        ->and($html)->toContain('NO VENDOR')
        ->and($html)->toContain('Choose Vendor...')
        ->and($html)->toContain('data-vendor-picker')
        ->and($html)->not->toContain('<ui-option');
});

it('asks for every vendor or one project\'s vendors through the list URL', function (): void {
    $all = Blade::render('<x-vendor-picker wire:model="vendor_id" :all="true" />');
    $project = Blade::render('<x-vendor-picker wire:model="vendor_id" :project-id="77" />');

    expect($all)->toContain('all')
        ->and($project)->toContain('project');
});

it('has the same trigger as a flux:select: select-button classes and the placeholder colour, not a button', function (): void {
    $html = Blade::render('<x-vendor-picker wire:model="vendor_id" label="Vendor" placeholder="Choose Vendor..." />');

    expect($html)->toContain('data-flux-select-button')
        ->and($html)->toContain('border-b-zinc-300/80')
        ->and($html)->toContain('h-10 py-2 text-base sm:text-sm rounded-lg px-3')
        ->and($html)->toContain("text-zinc-400 dark:text-zinc-400")
        ->and($html)->not->toContain('data-flux-button');

    expect(Blade::render('<x-vendor-picker wire:model="vendor_id" size="sm" />'))->toContain('h-8 py-1.5 text-sm rounded-md');
});
