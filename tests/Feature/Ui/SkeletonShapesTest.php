<?php

use Illuminate\Support\Facades\Blade;

/**
 * Skeleton shapes (resources/js/skeleton-shapes.js): a placeholder is drawn
 * from the shapes of the real content last seen on the same page, at the
 * same height. The browser pairs the two through one attribute each, set
 * by x-index-table for every index table from its heading — nothing per
 * component (Patryk, 2026-11-06).
 */
it('names the loaded table and its placeholder the same, from the heading', function (): void {
    $content = Blade::render('<x-index-table heading="Email Tracking"><table><tbody><tr><td>Opened</td></tr></tbody></table></x-index-table>');
    $placeholder = Blade::render('<x-index-table.placeholder heading="Email Tracking" :columns="[[\'label\' => \'Event\', \'width\' => \'w-1/2\'], [\'label\' => \'Sent\', \'width\' => \'w-1/2\']]" :rows="3" />');

    expect($content)->toContain('data-skeleton-source="email-tracking"')
        ->and($content)->toContain('wire:key="index-table-content"')
        ->and($content)->not->toContain('data-skeleton-target')
        ->and($placeholder)->toContain('data-skeleton-target="email-tracking"')
        ->and($placeholder)->toContain('wire:key="index-table-skeleton"')
        ->and($placeholder)->not->toContain('data-skeleton-source')
        ->and($placeholder)->toContain('data-flux-rows')
        ->and(preg_match_all('/<tr[^>]*data-flux-row/', $placeholder))->toBe(3)
        ->and($placeholder)->toContain('>Event<')->toContain('>Sent<');
});

it('takes an explicit skeleton key on both sides for tables that share a heading', function (): void {
    $content = Blade::render('<x-index-table heading="Payments" skeleton-key="project-payments"><table><tbody><tr><td>$1</td></tr></tbody></table></x-index-table>');
    $placeholder = Blade::render('<x-index-table.placeholder heading="Payments" skeleton-key="project-payments" :columns="[[\'label\' => \'Amount\', \'width\' => \'w-full\']]" :rows="1" />');

    expect($content)->toContain('data-skeleton-source="project-payments"')
        ->and($placeholder)->toContain('data-skeleton-target="project-payments"');
});

it('ships the recorder with the page and styles its shapes', function (): void {
    expect(file_get_contents(resource_path('js/app.js')))->toContain("import './skeleton-shapes';")
        ->and(file_get_contents(resource_path('js/skeleton-shapes.js')))->toContain('data-skeleton-target')->toContain('data-skeleton-source')->toContain("hook('morphed'")
        ->and(file_get_contents(resource_path('css/app.css')))->toContain('.skeleton-shapes')->toContain('.skeleton-shape ')->toContain('.skeleton-shapes-row');
});
