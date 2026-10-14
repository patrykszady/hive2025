<?php

use App\Livewire\Expenses\ExpenseIndex;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * <x-vendor-picker> is Flux's own searchable select (flux:select
 * variant="listbox" searchable), bound like an input (wire:model on the
 * component, Alpine x-modelable inside). It never prints the vendor list:
 * the browser fetches /vendors/picker-options and renders only the matches
 * as Flux options. The server prints one option, the chosen vendor, so the
 * button shows its name before the list arrives.
 */
/** Options inside the picker's own list (other Flux selects on a page have theirs). */
function vendorPickerOptionCount(string $html): int
{
    $start = strpos($html, 'data-vendor-picker-options>');
    if ($start === false) {
        return -1;
    }
    $end = strpos($html, '</ui-options>', $start);

    return preg_match_all('/<ui-option[\s>]/', substr($html, $start, $end === false ? null : $end - $start));
}

it('is a Flux searchable listbox bound through wire:model, with its static options and no vendor list', function (): void {
    $html = Blade::render('<x-vendor-picker wire:model.live="expense_vendor" label="Vendor" placeholder="Choose Vendor..." clearable :details="true" :extra="[[\'value\' => \'0\', \'label\' => \'NO VENDOR\']]" />');

    expect($html)->toContain('wire:model.live="expense_vendor"')
        ->and($html)->toContain('x-modelable="value"')
        ->and($html)->toContain('vendorPicker(')
        ->and($html)->toContain('picker-options')
        ->and($html)->toContain('NO VENDOR')
        ->and($html)->toContain('Choose Vendor...')
        ->and($html)->toContain('data-vendor-picker')
        ->and($html)->toContain('<ui-select')
        ->and($html)->toContain('data-flux-select-button')
        ->and($html)->toContain('placeholder="Search vendors..."')
        ->and($html)->toContain('x-model="query"')
        ->and(vendorPickerOptionCount($html))->toBe(0);
});

it('leaves the searching to the picker: Flux filtering off, no "No results found" row', function (): void {
    $html = Blade::render('<x-vendor-picker wire:model="vendor_id" label="Vendor" />');

    preg_match('/<ui-select\b[^>]*>/s', $html, $select);

    expect($select[0] ?? '')->not->toMatch('/\sfilter[\s=>]/')
        ->and($html)->not->toContain('No results found')
        ->and($html)->toContain('wire:ignore x-html="render()" data-vendor-picker-options');
});

it('asks for every vendor or one project\'s vendors through the list URL', function (): void {
    $all = Blade::render('<x-vendor-picker wire:model="vendor_id" :all="true" />');
    $project = Blade::render('<x-vendor-picker wire:model="vendor_id" :project-id="77" />');

    expect($all)->toContain('all')
        ->and($project)->toContain('project');
});

it('prints the chosen vendor as the one option, and keeps its Alpine settings the same whatever is chosen', function (): void {
    $company = Vendor::factory()->create(['business_name' => 'Owner LLC']);
    $chosen = Vendor::factory()->create(['business_name' => '1900\'s Barbershop Parlor']);
    $user = User::query()->create([
        'first_name' => 'Patryk', 'last_name' => 'Szady', 'email' => 'vendor-picker-'.uniqid().'@example.test',
        'cell_phone' => '2245550004', 'password' => null, 'primary_vendor_id' => $company->id,
    ]);
    $user->vendors()->attach($company->id, ['role_id' => 1]);
    $this->actingAs($user);

    $settings = function (string $html): string {
        preg_match('/<div\s+x-data="(vendorPicker\([^"]*\))"/', $html, $match);

        return $match[1] ?? '';
    };

    $none = Livewire::test(ExpenseIndex::class)->html();
    $vendor = Livewire::withQueryParams(['expense_vendor' => $chosen->id])->test(ExpenseIndex::class)->html();
    $noVendor = Livewire::withQueryParams(['expense_vendor' => '0'])->test(ExpenseIndex::class)->html();

    expect($vendor)->toMatch('/<ui-option\s[^>]*value="'.$chosen->id.'"[^>]*label="1900&#039;s Barbershop Parlor"/s')
        ->and(vendorPickerOptionCount($vendor))->toBe(1)
        ->and($noVendor)->toMatch('/<ui-option\s[^>]*value="0"[^>]*label="NO VENDOR"/s')
        ->and(vendorPickerOptionCount($none))->toBe(0)
        // A changed x-data attribute makes Alpine restart the picker when
        // Livewire morphs the page, and the pick is lost (2026-10-13).
        ->and($settings($none))->not->toBe('')
        ->and($settings($vendor))->toBe($settings($none))
        ->and($settings($noVendor))->toBe($settings($none));
});
