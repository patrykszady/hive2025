<?php

use App\Services\Estimates\SupplierQuotePricer;

/*
 * The client's price for each supplier line: list price, marked up by the
 * supplier's percentage, plus the line's share of the tax the supplier
 * charged — no tax line printed, the discount never shown.
 */

it('prices advanced window at list with its tax spread across the lines and no markup', function () {
    // Advanced Window Corp. estimate 10316499: list $2,775.18, 36% off, 10.5% tax on the discounted price = $186.49.
    $priced = SupplierQuotePricer::price([
        ['index' => 1, 'quantity' => 1, 'unit_price' => 264.59, 'amount' => 264.59],
        ['index' => 2, 'quantity' => 1, 'unit_price' => 2510.59, 'amount' => 2510.59],
    ], taxAmount: 186.49, markupPercent: 0);

    expect($priced['list_subtotal'])->toBe(2775.18)
        ->and($priced['tax_total'])->toBe(186.49)
        ->and($priced['markup_total'])->toBe(0.0)
        ->and($priced['client_total'])->toBe(2961.67)
        ->and($priced['rows'][0]['tax_unit'])->toBe(17.78)
        ->and($priced['rows'][0]['client_unit_price'])->toBe(282.37)
        ->and($priced['rows'][1]['tax_unit'])->toBe(168.71, 'the remainder lands on the last line, so the tax adds up exactly')
        ->and($priced['rows'][1]['client_unit_price'])->toBe(2679.30);
});

it('marks up climate solutions lines before adding their tax', function () {
    // Climate Solutions order 894357 at GS's price: $6,423.54 taxable, $658.41 tax, 15% markup.
    $priced = SupplierQuotePricer::price([
        ['index' => 1, 'quantity' => 1, 'unit_price' => 3595.74, 'amount' => 3595.74],
        ['index' => 2, 'quantity' => 1, 'unit_price' => 1900.00, 'amount' => 1900.00],
        ['index' => 3, 'quantity' => 1, 'unit_price' => 479.14, 'amount' => 479.14],
        ['index' => 4, 'quantity' => 1, 'unit_price' => 448.66, 'amount' => 448.66],
    ], taxAmount: 658.41, markupPercent: 15);

    expect($priced['markup_total'])->toBe(963.53)
        ->and($priced['tax_total'])->toBe(658.41)
        ->and($priced['client_total'])->toBe(round(6423.54 + 963.53 + 658.41, 2))
        ->and($priced['rows'][0]['markup_unit'])->toBe(539.36)
        ->and($priced['rows'][0]['client_unit_price'])->toBe(round(3595.74 + 539.36 + 368.56, 2));
});

it('spreads tax per unit on a multi-quantity line and falls back to the extended amount', function () {
    $priced = SupplierQuotePricer::price([
        ['index' => 1, 'quantity' => 4, 'unit_price' => 100, 'amount' => 400],
        ['index' => 2, 'quantity' => 2, 'unit_price' => null, 'amount' => 200],
    ], taxAmount: 60, markupPercent: 10);

    expect($priced['rows'][0]['tax_unit'])->toBe(10.0)
        ->and($priced['rows'][0]['client_unit_price'])->toBe(120.0)
        ->and($priced['rows'][1]['list_unit_price'])->toBe(100.0)
        ->and($priced['rows'][1]['client_total'])->toBe(240.0)
        ->and($priced['client_total'])->toBe(720.0);
});
