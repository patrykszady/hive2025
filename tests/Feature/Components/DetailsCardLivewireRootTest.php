<?php

use Livewire\Component;
use Livewire\Livewire;

/**
 * Livewire tags a component's root as the first tag at the very start of the
 * rendered HTML or at the start of a line. x-details.card is the root of
 * several components (users.users-index, users.details, vendors.vendor-details,
 * estimates.estimate-details). Its collapsible variant wraps the card in an
 * Alpine x-data div; when that div came out right after Livewire's own
 * <!--[if BLOCK]> marker, wire:id landed on the inner card instead, and every
 * server round trip failed with "Snapshot missing" (Add Member on /clients/*
 * never opened its modal).
 */
function detailsCardRootTag(string $cardTag): string
{
    $component = new class extends Component
    {
        public string $cardTag = '';

        public function render(): string
        {
            return $this->cardTag.'<x-slot:details>Rows</x-slot:details></x-details.card>';
        }
    };

    $html = Livewire::test($component, ['cardTag' => $cardTag])->html();

    preg_match('/<[a-zA-Z][^>]*\swire:id="[^"]+"[^>]*>/', $html, $matches);

    return $matches[0] ?? '';
}

it('puts the Livewire root on the collapsible wrapper that holds the open state', function (string $cardTag, string $expectedState) {
    $rootTag = detailsCardRootTag($cardTag);

    expect($rootTag)
        ->toContain('x-data="{ open: '.$expectedState.' }"')
        ->not->toContain('data-flux-card');
})->with([
    'expanded' => ['<x-details.card title="Client Members">', 'true'],
    'collapsed' => ['<x-details.card title="Client Members" :expanded="false">', 'false'],
]);

it('puts the Livewire root on the card itself when the card does not collapse', function () {
    $rootTag = detailsCardRootTag('<x-details.card title="Client Members" :accordion="false">');

    expect($rootTag)
        ->toContain('data-flux-card')
        ->not->toContain('x-data');
});
